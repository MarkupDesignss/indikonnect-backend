<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\BusinessProfile;
use App\Models\RefreshToken;
use App\Models\RejectedUser;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Password;
use App\Services\TwilioService;

class AuthController extends Controller
{
    protected $twilioService;

    public function __construct(TwilioService $twilioService)
    {
        $this->twilioService = $twilioService;
    }

    /**
     * Get role ID by account type from database
     */
    private function getRoleIdByAccountType($accountType)
    {
        $role = Role::where('slug', $accountType)->first();

        if (!$role) {
            $role = Role::where('name', $accountType)->first();
        }

        return $role ? $role->id : null;
    }

    /**
     * Assign role to user
     */
    private function assignRoleToUser($user)
    {
        try {
            $roleId = $this->getRoleIdByAccountType($user->account_type);

            if (!$roleId) {
                Log::error("Role not found for account type: {$user->account_type}");
                return false;
            }

            $role = Role::find($roleId);
            if (!$role) {
                Log::error("Role not found with ID: {$roleId}");
                return false;
            }

            RoleUser::updateOrInsert(
                ['user_id' => $user->id],
                [
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            $user->update(['role_id' => $roleId]);
            $user->refresh();

            Log::info("Role assigned to user: {$user->id} - Role ID: {$roleId}");
            return true;
        } catch (\Exception $e) {
            Log::error("Role assignment failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user role
     */
    private function getUserRole($user)
    {
        if ($user->role_id) {
            return Role::find($user->role_id);
        }

        $roleUser = RoleUser::where('user_id', $user->id)->first();
        if ($roleUser) {
            $role = Role::find($roleUser->role_id);
            if ($role) {
                $user->update(['role_id' => $role->id]);
                $user->refresh();
            }
            return $role;
        }
        return null;
    }

    /**
     * Verify temporary token
     */
    private function verifyTempToken($user, $token, $phone)
    {
        $dbToken = $user->temp_verification_token;
        $cachedToken = \Illuminate\Support\Facades\Cache::get('registration_token_' . $phone);

        Log::info('Token verification', [
            'user_id' => $user->id,
            'phone' => $phone,
            'received_token' => $token,
            'db_token' => $dbToken,
            'cached_token' => $cachedToken
        ]);

        if ($dbToken && $dbToken === $token) {
            return true;
        } elseif ($cachedToken && $cachedToken === $token) {
            return true;
        }

        return false;
    }

    /**
     * Generate token for existing user
     */
    private function generateTokenForExistingUser($user)
    {
        if ($user->is_active == 0) {
            return response()->json([
                'status' => false,
                'message' => 'Your account is blocked by admin. Please contact support.'
            ], 422);
        }

        // For distributors, check if active
        if ($user->account_type === 'distributor' && $user->distributor_status !== 'active') {
            return response()->json([
                'status' => false,
                'message' => 'Your distributor account is not active yet. Please wait for admin approval.'
            ], 422);
        }

        $token = $user->createToken('auth_token')->plainTextToken;
        $refreshToken = Str::random(100);

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(7),
            'last_used_at' => now()
        ]);

        $role = $this->getUserRole($user);
        $distributorProfile = null;
        if ($user->account_type === 'distributor') {
            $distributorProfile = BusinessProfile::where('user_id', $user->id)->first();
        }

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'token' => $token,
            'expires_in' => 3600,
            'refresh_token' => $refreshToken,
            'user' => $user,
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug
            ] : null,
            'distributor_profile' => $distributorProfile
        ]);
    }

    // ============ COMMON APIs (Both Customer & Distributor) ============

    /**
     * STEP 1: Send OTP to phone
     */
    /**
     * STEP 1: Send OTP
     */
    public function sendOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            // Generate 6 digit OTP
            $otp = rand(100000, 999999);

            if ($user) {
                // Existing user
                $user->update([
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes(10),
                ]);
            } else {
                // New user
                $user = User::create([
                    'phone' => $request->phone,
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes(10),
                    'is_registered' => 0,
                    'account_type' => 'customer',
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully',
                'phone' => $request->phone,

                // Remove this in production
                'otp' => $otp,
            ]);
        } catch (\Exception $e) {

            Log::error('Send OTP error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    /**
     * STEP 2: Verify OTP
     */
    /**
     * STEP 2: Verify OTP (Handles both new and existing users)
     */
    /**
     * STEP 2: Verify OTP
     */
    public function verifyOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'otp' => 'required|digits:6'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please request OTP first.'
                ], 422);
            }

            // Check OTP
            if ($user->otp != $request->otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP'
                ], 422);
            }

            // Check OTP expiry
            if (!$user->otp_expires_at || now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP has expired. Please request a new OTP.'
                ], 422);
            }

            // Clear OTP after successful verification
            $user->update([
                'otp' => null,
                'otp_expires_at' => null,
                'phone_verified' => 1,
            ]);

            /*
        |--------------------------------------------------------------------------
        | EXISTING REGISTERED CUSTOMER
        |--------------------------------------------------------------------------
        */
            if (
                $user->is_registered == 1 &&
                $user->account_type === 'customer'
            ) {
                // Generate login token
                $token = $user->createToken('customer-login')->plainTextToken;

                return response()->json([
                    'status' => true,
                    'message' => 'Login successful',
                    'token' => $token,
                    'user' => $user,
                    'is_registered' => true,
                    'requires_registration' => false,
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | NEW / NOT REGISTERED USER
        |--------------------------------------------------------------------------
        */

            $tempToken = Str::random(60);

            $user->update([
                'temp_verification_token' => $tempToken,
                'otp_verified_at' => now(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'OTP verified successfully. Please complete your registration.',
                'temp_token' => $tempToken,
                'phone' => $user->phone,
                'is_registered' => false,
                'requires_registration' => true,
            ]);
        } catch (\Exception $e) {

            Log::error('Verify OTP error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    // ============ CUSTOMER REGISTRATION ============

    /**
     * CUSTOMER: Complete Registration - Single step
     */
    public function completeCustomerRegistration(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'temp_token' => 'required|string',
                'full_name' => 'required|string|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users', 'email')->where(function ($query) {
                        return $query->where('is_registered', 1);
                    })
                ],
                'country' => 'nullable|string|max:255',
                'password' => 'nullable|string|min:8',
                'terms_condition' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please request OTP first.'
                ], 422);
            }

            if ($user->is_registered == 1) {
                return $this->generateTokenForExistingUser($user);
            }

            // Verify temp token
            if (!$this->verifyTempToken($user, $request->temp_token, $request->phone)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid verification token. Please verify OTP again.'
                ], 422);
            }

            // Complete registration
            $user->update([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'country' => $request->country,
                'account_type' => 'customer',
                'terms_condition' => $request->terms_condition,
                'phone_verified_at' => now(),
                'otp' => null,
                'otp_expires_at' => null,
                'temp_verification_token' => null,
                'otp_verified_at' => null,
                'is_registered' => 1,
                'distributor_status' => 'active',
                'password' => $request->filled('password') ? Hash::make($request->password) : null
            ]);

            $user->refresh();

            // Clear cache
            \Illuminate\Support\Facades\Cache::forget('registration_token_' . $request->phone);

            // Assign role
            $this->assignRoleToUser($user);

            // Generate tokens
            $token = $user->createToken('auth_token')->plainTextToken;
            $refreshToken = Str::random(100);

            RefreshToken::create([
                'user_id' => $user->id,
                'token' => hash('sha256', $refreshToken),
                'expires_at' => now()->addDays(7),
                'last_used_at' => now()
            ]);

            $role = $this->getUserRole($user);

            return response()->json([
                'status' => true,
                'message' => 'Customer account created successfully',
                'token' => $token,
                'expires_in' => 3600,
                'refresh_token' => $refreshToken,
                'user' => $user,
                'role' => $role ? [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug
                ] : null
            ]);
        } catch (\Exception $e) {
            Log::error('Customer registration error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ============ DISTRIBUTOR REGISTRATION (Multi-step) ============

    /**
     * DISTRIBUTOR: Step 1 - Personal Information
     */
    public function distributorStep1Personal(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'temp_token' => 'required|string',
                'full_name' => 'required|string|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users', 'email')->where(function ($query) {
                        return $query->where('is_registered', 1);
                    })
                ],
                'country' => 'nullable|string|max:255',
                'password' => 'nullable|string|min:8',
                'date_of_birth' => 'required|date|before:-18 years',
                'terms_condition' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please request OTP first.'
                ], 422);
            }

            if ($user->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'User is already registered.'
                ], 422);
            }

            // Verify temp token
            if (!$this->verifyTempToken($user, $request->temp_token, $request->phone)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid verification token. Please verify OTP again.'
                ], 422);
            }

            // Update user with personal information
            $user->update([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'country' => $request->country,
                'account_type' => 'distributor',
                'terms_condition' => $request->terms_condition,
                'date_of_birth' => $request->date_of_birth,
                'password' => $request->filled('password') ? Hash::make($request->password) : null,
                'registration_step' => 1
            ]);

            $user->refresh();

            // Create distributor profile
            BusinessProfile::updateOrCreate(
                ['user_id' => $user->id],
                ['user_id' => $user->id, 'kyc_status' => 'pending']
            );

            return response()->json([
                'status' => true,
                'message' => 'Personal information saved successfully',
                'step' => 1,
                'next_step' => 2,
                'user' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 1 error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 2 - Sponsor & Placement
     */
    public function distributorStep2Sponsor(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'sponsor_id' => 'nullable|string|max:255',  
                'placement_leg' => 'required|in:left,right,auto',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            // Update sponsor information
            $user->update([
                'sponsor_id' => $request->sponsor_id,
                'placement_leg' => $request->placement_leg,
                'registration_step' => 2
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Sponsor information saved successfully',
                'step' => 2,
                'next_step' => 3,
                'user' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 2 error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 3 - Send OTP for Email & Mobile Verification
     */
    public function distributorStep3SendVerificationOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'type' => 'required|in:email,mobile',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            if ($request->type === 'email') {
                if (!$user->email) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Email not found. Please complete step 1 first.'
                    ], 422);
                }

                $emailOtp = rand(100000, 999999);
                $user->update([
                    'email_otp' => $emailOtp,
                    'email_otp_expires_at' => now()->addMinutes(10)
                ]);

                // Send email OTP
                // Mail::to($user->email)->send(new EmailVerificationMail($emailOtp));

                return response()->json([
                    'status' => true,
                    'message' => 'Email OTP sent successfully',
                    'email' => $user->email,
                    'otp' => $emailOtp // Remove in production
                ]);
            } else {
                // Mobile OTP (reuse existing phone verification)
                $otp = rand(100000, 999999);
                $user->update([
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes(10),
                    'phone_verified' => 0
                ]);

                // Send mobile OTP
                // $this->twilioService->sendOtp($user->phone, $otp);

                return response()->json([
                    'status' => true,
                    'message' => 'Mobile OTP sent successfully',
                    'phone' => $user->phone,
                    'otp' => $otp // Remove in production
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Distributor step 3 send OTP error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 3 - Verify Email OTP
     */
    public function distributorStep3VerifyEmailOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'otp' => 'required|digits:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            if ($user->email_otp != $request->otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid email OTP'
                ], 422);
            }

            if (now()->gt($user->email_otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email OTP has expired'
                ], 422);
            }

            $user->update([
                'email_verified_at' => now(),
                'email_otp' => null,
                'email_otp_expires_at' => null,
                'registration_step' => 3
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Email verified successfully',
                'step' => 3,
                'next_step' => 4,
                'email_verified' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 3 verify email error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 3 - Verify Mobile OTP
     */
    public function distributorStep3VerifyMobileOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'otp' => 'required|digits:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            if ($user->otp != $request->otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid mobile OTP'
                ], 422);
            }

            if (now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Mobile OTP has expired'
                ], 422);
            }

            $user->update([
                'phone_verified' => 1,
                'otp' => null,
                'otp_expires_at' => null,
                'registration_step' => 3
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Mobile verified successfully',
                'step' => 3,
                'next_step' => 4,
                'mobile_verified' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 3 verify mobile error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 4 - Aadhaar Verification
     */
    public function distributorStep4Aadhaar(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'encrypted_aadhaar' => 'required|string|size:12',
                'aadhaar_consent' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            // Check if email and mobile are verified
            if (!$user->email_verified_at || !$user->phone_verified) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please verify your email and mobile first.'
                ], 422);
            }

            // Store Aadhaar (encrypted)
            $distributorProfile = BusinessProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'encrypted_aadhaar' => encrypt($request->encrypted_aadhaar),
                    'aadhaar_consent' => $request->aadhaar_consent,
                    'aadhaar_verified' => 0,
                    'kyc_status' => 'pending'
                ]
            );

            $user->update([
                'registration_step' => 4,
                'aadhaar_last4' => substr($request->encrypted_aadhaar, -4) // Store last 4 digits
            ]);

            // Simulate Aadhaar verification
            // In production, call KYC provider API here
            $aadhaarVerified = true; // Simulate success

            if ($aadhaarVerified) {
                $distributorProfile->update([
                    'aadhaar_verified' => 1,
                    'aadhaar_verified_at' => now()
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Aadhaar verification ' . ($aadhaarVerified ? 'successful' : 'failed'),
                'step' => 4,
                'next_step' => 5,
                'aadhaar_verified' => $aadhaarVerified,
                'aadhaar_last4' => '****' . substr($request->encrypted_aadhaar, -4)
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 4 Aadhaar error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 5 - PAN Verification
     */
    public function distributorStep5Pan(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'encrypted_pan' => 'required|string|size:10',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            // Check if Aadhaar is verified
            $distributorProfile = BusinessProfile::where('user_id', $user->id)->first();
            if (!$distributorProfile || !$distributorProfile->aadhaar_verified) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete Aadhaar verification first.'
                ], 422);
            }

            // Store PAN (encrypted)
            $distributorProfile->update([
                'encrypted_pan' => encrypt($request->encrypted_pan),
                'pan_verified' => 0
            ]);

            $user->update([
                'registration_step' => 5,
                'pan_last4' => substr($request->encrypted_pan, -4)
            ]);

            // Simulate PAN verification
            // In production, call PAN verification API here
            $panVerified = true; // Simulate success

            if ($panVerified) {
                $distributorProfile->update([
                    'pan_verified' => 1,
                    'pan_verified_at' => now()
                ]);
            }

            return response()->json([
                'status' => true,
                'message' => 'PAN verification ' . ($panVerified ? 'successful' : 'failed'),
                'step' => 5,
                'next_step' => 6,
                'pan_verified' => $panVerified,
                'pan_last4' => '****' . substr($request->encrypted_pan, -4)
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 5 PAN error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 6 - Bank Account Details
     */
    public function distributorStep6Bank(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'bank_holder_name' => 'required|string|max:255',
                'bank_name' => 'required|string|max:255',
                'branch_name' => 'required|string|max:255',
                'encrypted_bank_account' => 'required|string|max:50',
                'confirm_account_number' => 'required|string|max:50',
                'bank_ifsc' => 'required|string|max:20',
                'account_type' => 'required|in:current,savings',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if account numbers match
            if ($request->encrypted_bank_account !== $request->confirm_account_number) {
                return response()->json([
                    'status' => false,
                    'message' => 'Account numbers do not match.'
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            // Check if PAN is verified
            $distributorProfile = BusinessProfile::where('user_id', $user->id)->first();
            if (!$distributorProfile || !$distributorProfile->pan_verified) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete PAN verification first.'
                ], 422);
            }

            // Store bank details (encrypted)
            $distributorProfile->update([
                'encrypted_bank_account' => encrypt($request->encrypted_bank_account),
                'bank_ifsc' => $request->bank_ifsc,
                'bank_holder_name' => $request->bank_holder_name,
                'bank_name' => $request->bank_name,
                'branch_name' => $request->branch_name,
                'account_type' => $request->account_type,
                'bank_verified' => 0
            ]);

            $user->update([
                'registration_step' => 6,
                'account_last4' => substr($request->encrypted_bank_account, -4)
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Bank details saved successfully',
                'step' => 6,
                'next_step' => 7,
                'bank_details' => [
                    'bank_name' => $request->bank_name,
                    'bank_holder_name' => $request->bank_holder_name,
                    'account_last4' => '****' . substr($request->encrypted_bank_account, -4),
                    'bank_ifsc' => $request->bank_ifsc
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 6 bank error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 7 - Location Consent
     */
    public function distributorStep7Location(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'location_consent' => 'required|in:0,1',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            // Store location consent
            $distributorProfile = BusinessProfile::where('user_id', $user->id)->first();
            if ($distributorProfile) {
                $distributorProfile->update([
                    'location_consent' => $request->location_consent,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'location_consent_at' => $request->location_consent == 1 ? now() : null
                ]);
            }

            $user->update([
                'registration_step' => 7,
                'location_consent_given' => $request->location_consent
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Location consent saved successfully',
                'step' => 7,
                'next_step' => 8,
                'location_consent' => $request->location_consent == 1
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 7 location error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DISTRIBUTOR: Step 8 - Review & Submit
     */
    public function distributorStep8Submit(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'accept_terms' => 'required|in:0,1',
                'accept_agreement' => 'required|in:0,1',
                'accept_code_of_conduct' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check all acceptances
            if ($request->accept_terms != 1 || $request->accept_agreement != 1 || $request->accept_code_of_conduct != 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please accept all terms and conditions.'
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            // Check all previous steps completed
            $distributorProfile = BusinessProfile::where('user_id', $user->id)->first();

            if (!$distributorProfile) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete all previous steps.'
                ], 422);
            }

            // Verify all steps are complete
            if ($user->registration_step != 7) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete all previous steps first.'
                ], 422);
            }

            // Finalize registration
            $user->update([
                'is_registered' => 1,
                'distributor_status' => 'pending', // Admin will approve
                'registration_step' => 8,
                'registration_completed_at' => now(),
                'otp' => null,
                'otp_expires_at' => null,
                'temp_verification_token' => null,
                'otp_verified_at' => null,
                'accept_terms' => $request->accept_terms,
                'accept_agreement' => $request->accept_agreement,
                'accept_code_of_conduct' => $request->accept_code_of_conduct
            ]);

            $distributorProfile->update([
                'registration_completed' => 1,
                'submitted_at' => now(),
                'terms_accepted_at' => now(),
                'kyc_status' => 'pending'
            ]);

            // Assign role
            $this->assignRoleToUser($user);

            // Send notification to admin
            $admin = DB::table('admins')->first();
            if ($admin) {
                DB::table('admin_notifications')->insert([
                    'admin_id' => $admin->id ?? '1',
                    'type' => 'new_distributor_registration',
                    'title' => 'New Distributor Registration',
                    'message' => "{$user->full_name} has completed distributor registration.",
                    'reference_type' => 'user',
                    'reference_id' => $user->id,
                    'priority' => 'high',
                    'extra_data' => json_encode([
                        'distributor_id' => $user->id,
                        'name' => $user->full_name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'distributor_profile' => $distributorProfile
                    ]),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Clear cache
            \Illuminate\Support\Facades\Cache::forget('registration_token_' . $request->phone);

            return response()->json([
                'status' => true,
                'message' => 'Distributor registration submitted successfully. Please wait for admin approval.',
                'user' => $user,
                'distributor_profile' => $distributorProfile,
                'status' => 'pending_approval'
            ]);
        } catch (\Exception $e) {
            Log::error('Distributor step 8 submit error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get distributor registration progress
     */
    public function getDistributorProgress(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 422);
            }

            $distributorProfile = BusinessProfile::where('user_id', $user->id)->first();

            return response()->json([
                'status' => true,
                'registration_step' => $user->registration_step ?? 0,
                'completed_steps' => [
                    'personal_info' => !empty($user->full_name) && !empty($user->email),
                    'sponsor' => !empty($user->placement_leg),
                    'email_verified' => !empty($user->email_verified_at),
                    'phone_verified' => $user->phone_verified ?? false,
                    'aadhaar_verified' => $distributorProfile && $distributorProfile->aadhaar_verified ?? false,
                    'pan_verified' => $distributorProfile && $distributorProfile->pan_verified ?? false,
                    'bank_details' => $distributorProfile && !empty($distributorProfile->encrypted_bank_account),
                    'location_consent' => $user->location_consent_given ?? false,
                ],
                'is_registered' => $user->is_registered,
                'distributor_status' => $user->distributor_status,
                'distributor_profile' => $distributorProfile
            ]);
        } catch (\Exception $e) {
            Log::error('Get distributor progress error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Current User with Role
     */
    public function getCurrentUser(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $role = $this->getUserRole($user);

            $distributorProfile = null;
            if ($user->account_type === 'distributor') {
                $distributorProfile = BusinessProfile::where('user_id', $user->id)->first();
            }

            return response()->json([
                'status' => true,
                'user' => $user,
                'role' => $role ? [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug
                ] : null,
                'distributor_profile' => $distributorProfile
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $request->user()->currentAccessToken()->delete();
            RefreshToken::where('user_id', $user->id)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh Token
     */
    public function refreshToken(Request $request)
    {
        try {
            $request->validate([
                'refresh_token' => 'required'
            ]);

            $hashedToken = hash('sha256', $request->refresh_token);

            $refreshToken = RefreshToken::where('token', $hashedToken)->first();

            if (!$refreshToken) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid refresh token'
                ], 422);
            }

            if ($refreshToken->expires_at->isPast()) {
                $refreshToken->delete();
                return response()->json([
                    'status' => false,
                    'message' => 'Refresh token expired. Please login again.'
                ], 422);
            }

            $user = User::find($refreshToken->user_id);
            $newAccessToken = $user->createToken('auth_token')->plainTextToken;

            $newRefreshToken = Str::random(100);

            $refreshToken->update([
                'token' => hash('sha256', $newRefreshToken),
                'expires_at' => now()->addDays(7),
                'last_used_at' => now()
            ]);

            return response()->json([
                'status' => true,
                'access_token' => $newAccessToken,
                'expires_in' => 3600,
                'refresh_token' => $newRefreshToken
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change Password
     */
    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => ['required', 'string'],
                'new_password' => [
                    'required',
                    'confirmed',
                    Password::min(8)
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                ],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }

            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'New password cannot be same as current password'
                ], 400);
            }

            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Password changed successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('changePassword error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get user profile
     */
    public function profile()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 422);
            }

            $user->load('roles');

            // Load business profile if distributor
            if ($user->account_type === 'distributor') {
                $user->load('businessProfile');
            }

            // Profile picture full URL
            if (!empty($user->profile_picture)) {
                $user->profile_picture = asset('storage/profile_pictures/' . basename($user->profile_picture));
            }

            return response()->json([
                'status' => true,
                'message' => 'Profile fetched successfully',
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Profile - Updated with new BusinessProfile fields
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            /*
            --------------------------------
            PREVENT INVALID SWITCH
            --------------------------------
            */
            if ($user->account_type === 'distributor' && $request->account_type === 'customer') {
                return response()->json([
                    'status' => false,
                    'message' => 'distributor account cannot be converted to customer'
                ], 403);
            }

            /*
            --------------------------------
            DYNAMIC VALIDATION
            --------------------------------
            */
            $accountType = $request->account_type ?? $user->account_type;

            $rules = [
                'full_name' => 'nullable|string|max:255',
                'phone' => [
                    'nullable',
                    'string',
                    'min:10',
                    'max:15',
                ],
                'country' => 'nullable|string|max:255',
                'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png',
                'account_type' => 'nullable|in:customer,distributor',
            ];

            /*
            --------------------------------
            PASSWORD VALIDATION (OPTIONAL)
            --------------------------------
            */
            if ($request->filled('password')) {
                $rules['password'] = [
                    'required',
                    'string',
                    Password::min(8)
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                ];

                if ($accountType === 'customer') {
                    $rules['password'][] = 'confirmed';
                }
            }

            /*
            --------------------------------
            distributor VALIDATION - Updated with new fields
            --------------------------------
            */
            if ($accountType === 'distributor') {
                $rules = array_merge($rules, [
                    'encrypted_aadhaar' => 'nullable|string',
                    'encrypted_pan' => 'nullable|string',
                    'encrypted_bank_account' => 'nullable|string',
                    'bank_ifsc' => 'nullable|string|max:20',
                    'bank_holder_name' => 'nullable|string|max:255',
                ]);
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            /*
            --------------------------------
            UPDATE USER DATA
            --------------------------------
            */
            $data = [
                'full_name' => $request->full_name ?? $user->full_name,
                'phone' => $request->phone ?? $user->phone,
                'country' => $request->country ?? $user->country,
            ];

            $logoutRequired = false;
            $businessProfile = null;

            /*
            --------------------------------
            SWITCH customer → distributor
            --------------------------------
            */
            if ($user->account_type === 'customer' && $accountType === 'distributor') {
                $data['account_type'] = 'distributor';
                $data['distributor_status'] = 'pending';

                // Update role to distributor
                $distributorRole = Role::where('slug', 'distributor')->first();
                if ($distributorRole) {
                    RoleUser::updateOrCreate(
                        ['user_id' => $user->id],
                        ['role_id' => $distributorRole->id]
                    );
                    $user->update(['role_id' => $distributorRole->id]);
                } else {
                    Log::error("distributor role not found in database");
                    return response()->json([
                        'status' => false,
                        'message' => 'Role configuration error. Please contact admin.'
                    ], 500);
                }

                $logoutRequired = true;
            }

            // Password update
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }

            /*
            --------------------------------
            PROFILE IMAGE
            --------------------------------
            */
            if ($request->hasFile('profile_picture')) {
                if ($user->profile_picture && Storage::disk('public')->exists($user->profile_picture)) {
                    Storage::disk('public')->delete($user->profile_picture);
                }

                $data['profile_picture'] = $request->file('profile_picture')
                    ->store('profile_pictures', 'public');
            }

            $user->update($data);

            /*
            --------------------------------
            BUSINESS PROFILE - Updated with new fields
            --------------------------------
            */
            if ($accountType === 'distributor') {
                $businessData = [
                    'user_id' => $user->id,
                ];

                // Only update KYC fields if provided
                if ($request->has('encrypted_aadhaar')) {
                    $businessData['encrypted_aadhaar'] = $request->encrypted_aadhaar;
                }
                if ($request->has('encrypted_pan')) {
                    $businessData['encrypted_pan'] = $request->encrypted_pan;
                }
                if ($request->has('encrypted_bank_account')) {
                    $businessData['encrypted_bank_account'] = $request->encrypted_bank_account;
                }
                if ($request->has('bank_ifsc')) {
                    $businessData['bank_ifsc'] = $request->bank_ifsc;
                }
                if ($request->has('bank_holder_name')) {
                    $businessData['bank_holder_name'] = $request->bank_holder_name;
                }

                $businessProfile = BusinessProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    $businessData
                );
            }

            /*
            --------------------------------
            FORCE LOGOUT IF SWITCHED
            --------------------------------
            */
            if ($logoutRequired) {
                Auth::logout();
                return response()->json([
                    'status' => true,
                    'message' => 'Your business request has been submitted for admin approval',
                    'logout' => true
                ]);
            }

            // Get user role
            $role = $this->getUserRole($user);

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully',
                'user' => $user->fresh(),
                'role' => $role ? [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug
                ] : null,
                'business_profile' => $businessProfile
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove Profile Picture
     */
    public function removeProfilePicture()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            if (!$user->profile_picture) {
                return response()->json([
                    'status' => false,
                    'message' => 'No profile picture to remove'
                ], 404);
            }

            if (Storage::disk('public')->exists($user->profile_picture)) {
                Storage::disk('public')->delete($user->profile_picture);
            }

            $user->update([
                'profile_picture' => null
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Profile picture removed successfully',
                'user' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Forgot Password - Send OTP
     */
    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|min:10|max:15'
            ]);

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Phone number not registered'
                ], 422);
            }

            if ($user->is_registered == 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete your registration first'
                ], 422);
            }

            $otp = rand(100000, 999999);

            $user->update([
                'otp' => $otp,
                'otp_expires_at' => Carbon::now()->addMinutes(10)
            ]);

            // Send OTP via Twilio
            $this->twilioService->sendOtp($request->phone, $otp);

            return response()->json([
                'status' => true,
                'message' => 'OTP sent to your phone'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify reset password OTP
     */
    public function verifyResetOtp(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|min:10|max:15',
                'otp' => 'required|digits:6'
            ]);

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 422);
            }

            if ($user->otp != $request->otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP'
                ], 422);
            }

            if (Carbon::now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP expired'
                ], 422);
            }

            return response()->json([
                'status' => true,
                'message' => 'OTP verified successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|min:10|max:15|exists:users,phone',
                'password' => 'nullable|string|min:8'
            ]);

            $user = User::where('phone', $request->phone)->first();

            $updateData = [
                'otp' => null,
                'otp_expires_at' => null
            ];

            // If password is provided, update it
            if ($request->has('password') && !empty($request->password)) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);

            return response()->json([
                'status' => true,
                'message' => 'Password reset successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}