<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationMail;
use App\Mail\MobileVerificationCodeMail;
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
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use App\Services\TwilioService;

class AuthController extends Controller
{
    protected $twilioService;

    // Role constants matching your database
    const ROLE_CUSTOMER = 1;
    const ROLE_DISTRIBUTER = 2;

    public function __construct(TwilioService $twilioService)
    {
        $this->twilioService = $twilioService;
    }

    /**
     * Get role ID by account type
     */
    private function getRoleIdByAccountType($accountType)
    {
        return $accountType === 'distributer' ? self::ROLE_DISTRIBUTER : self::ROLE_CUSTOMER;
    }

    /**
     * Get role name by account type
     */
    private function getRoleNameByAccountType($accountType)
    {
        return $accountType === 'distributer' ? 'Distributer' : 'Customer';
    }

    /**
     * Assign role to user and update users table
     */
    private function assignRoleToUser($user)
    {
        try {
            $roleId = $this->getRoleIdByAccountType($user->account_type);

            // Check if role exists
            $role = Role::find($roleId);
            if (!$role) {
                Log::error("Role not found with ID: {$roleId}");
                return false;
            }

            // Assign role to user using RoleUser model
            RoleUser::updateOrInsert(
                ['user_id' => $user->id],
                [
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            );

            // Update role_id in users table
            $user->update(['role_id' => $roleId]);

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
                // Sync users table
                $user->update(['role_id' => $role->id]);
            }
            return $role;
        }
        return null;
    }

    /**
     * STEP 1: Send OTP to phone - Only phone number required
     */
    public function sendOtp(Request $request)
    {
        try {
            /*
            --------------------------------
            VALIDATION - Only phone required
            --------------------------------
            */
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            /*
            --------------------------------
            CHECK IF USER ALREADY REGISTERED
            --------------------------------
            */
            $existingUser = User::where('phone', $request->phone)->first();

            if ($existingUser && $existingUser->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Phone number is already registered. Please login.'
                ], 422);
            }

            /*
            --------------------------------
            GENERATE OTP
            --------------------------------
            */
            $otp = rand(100000, 999999);

            /*
            --------------------------------
            CREATE OR UPDATE USER (Minimal data)
            --------------------------------
            */
            $user = User::updateOrCreate(
                ['phone' => $request->phone],
                [
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes(10),
                    'is_registered' => 0,
                    'account_type' => 'customer' // Default role
                ]
            );

            /*
            --------------------------------
            SEND OTP VIA TWILIO
            --------------------------------
            */
            try {
                $this->twilioService->sendOtp($request->phone, $otp);
            } catch (\Exception $e) {
                Log::error('Twilio SMS failed: ' . $e->getMessage());
                return response()->json([
                    'status' => false,
                    'message' => $e->getMessage()
                ], 500);
            }

            /*
            --------------------------------
            RESPONSE
            --------------------------------
            */
            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully to your phone',
                'phone' => $request->phone,
                'otp' => $otp // Remove in production
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * STEP 2: Verify OTP and Complete Registration with all user data
     */
    public function verifyOtp(Request $request)
    {
        try {
            /*
            --------------------------------
            VALIDATION
            --------------------------------
            */
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'otp' => 'required|digits:4',
                'account_type' => 'required|in:customer,distributer',
                'full_name' => 'required|string|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('users', 'email')->where(function ($query) {
                        return $query->where('is_registered', 1);
                    })
                ],
                'country' => 'nullable|string|max:255',
                'terms_condition' => 'required|in:0,1',

                // Distributer fields
                'company_name' => 'required_if:account_type,distributer|nullable|string|max:255',
                'gst_number' => 'nullable|string|max:255',
                'billing_address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:255',
                'pin_code' => 'nullable|string|max:255',

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            /*
            --------------------------------
            FIND USER
            --------------------------------
            */
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
                    'message' => 'User is already registered. Please login.'
                ], 422);
            }

            /*
            --------------------------------
            VERIFY OTP
            --------------------------------
            */
            if ($user->otp != $request->otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP'
                ], 422);
            }

            if (now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP has expired'
                ], 422);
            }

            /*
            --------------------------------
            COMPLETE REGISTRATION
            --------------------------------
            */
            $updateData = [
                'full_name' => $request->full_name,
                'email' => $request->email,
                'country' => $request->country,
                'account_type' => $request->account_type,
                'terms_condition' => $request->terms_condition,
                'phone_verified_at' => now(),
                'otp' => null,
                'otp_expires_at' => null,
                'is_registered' => 1,
                'business_status' => $request->account_type === 'distributer' ? 'pending' : null
            ];

            // Set password if provided (required for customers)
            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);

            /*
            --------------------------------
            ASSIGN ROLE
            --------------------------------
            */
            $this->assignRoleToUser($user);

            /*
            --------------------------------
            STORE DISTRIBUTER DATA
            --------------------------------
            */
            if ($request->account_type === 'distributer') {
                BusinessProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_name' => $request->company_name,
                        'gst_number' => $request->gst_number,
                        'billing_address' => $request->billing_address,
                        'city' => $request->city,
                        'state' => $request->state,
                        'pin_code' => $request->pin_code,
                        'country' => $request->country,
                    ]
                );

                // Send notification for distributer registration
                $admin = DB::table('admins')->first();
                if ($admin) {
                    DB::table('admin_notifications')->insert([
                        'admin_id'       => $admin->id ?? '1',
                        'type'           => 'new_distributer_registration',
                        'title'          => 'New Distributer Registration',
                        'message'        => "{$request->full_name} has registered as a distributer.",
                        'reference_type' => 'user',
                        'reference_id'   => $user->id,
                        'priority'       => 'high',
                        'extra_data'     => json_encode([
                            'customer_id'    => $user->id,
                            'customer_name'  => $request->full_name,
                            'customer_phone' => $request->phone,
                            'email'          => $request->email,
                            'company_name'   => $request->company_name
                        ]),
                        'created_at'     => now(),
                        'updated_at'     => now()
                    ]);
                }
            }

            /*
            --------------------------------
            GENERATE TOKENS
            --------------------------------
            */
            // Generate JWT token
            $token = $user->createToken('auth_token')->plainTextToken;

            $refreshToken = Str::random(100);

            RefreshToken::create([
                'user_id'      => $user->id,
                'token'        => hash('sha256', $refreshToken),
                'expires_at'   => now()->addDays(7),
                'last_used_at' => now()
            ]);

            // Get the assigned role
            $role = $this->getUserRole($user);

            return response()->json([
                'status' => true,
                'message' => 'Account created successfully',
                'token' => $token,
                'expires_in' => 3600,
                'refresh_token' => $refreshToken,
                'data' => [
                    'user' => $user,
                    'role' => $role ? [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug
                    ] : null,
                    'business_profile' => $request->account_type === 'distributer' ?
                        BusinessProfile::where('user_id', $user->id)->first() : null
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login with phone and OTP
     */
    /**
     * Login with phone - Auto detect account type
     */
    public function login(Request $request)
    {
        try {

            $request->validate([
                'phone' => 'required|min:10|max:15',
            ]);


            $user = User::where('phone', $request->phone)->first();


            if (!$user) {
                // Check if user is rejected
                $rejectedUser = RejectedUser::where('phone', $request->phone)->first();

                if ($rejectedUser) {
                    $daysPassed = Carbon::parse($rejectedUser->rejected_at)
                        ->diffInDays(now());

                    if ($daysPassed < 30) {
                        $remaining = 30 - $daysPassed;
                        return response()->json([
                            'status'  => false,
                            'message' => "Your business request was rejected. Please try again after {$remaining} days"
                        ], 422);
                    }

                    return response()->json([
                        'status'  => false,
                        'message' => 'Your business request was rejected more than 30 days ago. Please contact admin.'
                    ], 422);
                }

                return response()->json([
                    'status'  => false,
                    'message' => 'Phone number not registered'
                ], 422);
            }


            if ($user->is_registered == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please complete your registration first'
                ], 422);
            }


            if ($user->is_active == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your account is blocked by admin. Please contact admin to continue'
                ], 422);
            }


            if (
                $user->account_type === 'distributer' &&
                $user->business_status === 'pending'
            ) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your business request is waiting for admin approval'
                ], 422);
            }


            $otp = rand(100000, 999999);

            $user->update([
                'otp' => $otp,
                'otp_expires_at' => now()->addMinutes(10)
            ]);

            // Send OTP via Twilio
            $this->twilioService->sendOtp($request->phone, $otp);


            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully to your phone',
                'phone' => $request->phone,
                'account_type' => $user->account_type, // Return the detected account type
                'otp' => $otp // Remove in production
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify Login OTP and generate tokens
     */
    public function verifyLoginOtp(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|min:10|max:15',
                'otp' => 'required|digits:4'
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

            // Clear OTP
            $user->update([
                'otp' => null,
                'otp_expires_at' => null
            ]);

            // Generate JWT token
            $token = $user->createToken('auth_token')->plainTextToken;

            $refreshToken = Str::random(100);

            RefreshToken::create([
                'user_id'      => $user->id,
                'token'        => hash('sha256', $refreshToken),
                'expires_at'   => now()->addDays(7),
                'last_used_at' => now()
            ]);

            // Get user role
            $role = $this->getUserRole($user);

            return response()->json([
                'status' => true,
                'message' => 'Login successfully',
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
                'otp' => 'required|digits:4'
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

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|min:10|max:15'
            ]);

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 422);
            }

            $otp = rand(100000, 999999);

            $user->update([
                'otp' => $otp,
                'otp_expires_at' => now()->addMinutes(10)
            ]);

            $this->twilioService->sendOtp($request->phone, $otp);

            return response()->json([
                'status' => true,
                'message' => 'OTP resent successfully',
                'otp' => $otp // Remove in production
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Profile
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
            if ($user->account_type === 'distributer' && $request->account_type === 'customer') {
                return response()->json([
                    'status' => false,
                    'message' => 'Distributer account cannot be converted to customer'
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
                'account_type' => 'nullable|in:customer,distributer',
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
            DISTRIBUTER VALIDATION
            --------------------------------
            */
            if ($accountType === 'distributer') {
                $rules = array_merge($rules, [
                    'company_name' => 'nullable|string|max:255',
                    'gst_number' => 'nullable|string|max:100',
                    'billing_address' => 'nullable|string',
                    'document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240'
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
            SWITCH customer → distributer
            --------------------------------
            */
            if ($user->account_type === 'customer' && $accountType === 'distributer') {
                $data['account_type'] = 'distributer';
                $data['business_status'] = 'pending';

                // Update role to Distributer (ID = 2)
                RoleUser::updateOrCreate(
                    ['user_id' => $user->id],
                    ['role_id' => self::ROLE_DISTRIBUTER]
                );
                $user->update(['role_id' => self::ROLE_DISTRIBUTER]);

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
            BUSINESS PROFILE (For distributer)
            --------------------------------
            */
            if ($accountType === 'distributer') {
                $businessData = $request->only(
                    'company_name',
                    'gst_number',
                    'billing_address'
                );

                if ($request->hasFile('document')) {
                    $businessData['document_path'] = $request->file('document')
                        ->store('business_documents', 'public');
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

            return response()->json([
                'status' => true,
                'user' => $user,
                'role' => $role ? [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug
                ] : null
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

            // Delete the current access token
            $request->user()->currentAccessToken()->delete();

            // If you're using custom refresh tokens
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

            /*
            * Rotate refresh token
            */
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
}
