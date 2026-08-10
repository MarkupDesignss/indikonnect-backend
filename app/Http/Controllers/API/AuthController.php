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
     * Get role name by account type
     */
    private function getRoleNameByAccountType($accountType)
    {
        $role = Role::where('slug', $accountType)->first();

        if (!$role) {
            $role = Role::where('name', $accountType)->first();
        }

        return $role ? $role->name : ucfirst($accountType);
    }

    /**
     * Assign role to user and update users table
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
     * STEP 1: Send OTP to phone - Only phone number required
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

            // Check if user already registered
            $existingUser = User::where('phone', $request->phone)->first();

            if ($existingUser && $existingUser->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Phone number is already registered. Please login.'
                ], 422);
            }

            // Generate OTP (6 digits)
            $otp = rand(100000, 999999);

            // Create or update user with minimal data
            $user = User::updateOrCreate(
                ['phone' => $request->phone],
                [
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes(10),
                    'is_registered' => 0,
                    'account_type' => 'customer' // Default role
                ]
            );

            // Send OTP via Twilio
            // try {
            //     $this->twilioService->sendOtp($request->phone, $otp);
            // } catch (\Exception $e) {
            //     Log::error('Twilio SMS failed: ' . $e->getMessage());
            //     return response()->json([
            //         'status' => false,
            //         'message' => $e->getMessage()
            //     ], 500);
            // }

            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully to your phone',
                'phone' => $request->phone,
                'otp' => $otp // Remove in production
            ]);
        } catch (\Exception $e) {
            Log::error('Send OTP error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * STEP 2: Verify OTP only - Just verify the OTP without saving any other data
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

            if ($user->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'User is already registered. Please login.'
                ], 422);
            }

            // Verify OTP
            if ($user->otp != $request->otp) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP'
                ], 422);
            }

            if (now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP has expired. Please request a new OTP.'
                ], 422);
            }

            // Generate temporary token
            $tempToken = Str::random(60);

            // Store temp token in database
            $user->update([
                'temp_verification_token' => $tempToken,
                'otp_verified_at' => now()
            ]);

            // Also store in cache for redundancy
            \Illuminate\Support\Facades\Cache::put(
                'registration_token_' . $request->phone,
                $tempToken,
                now()->addMinutes(10)
            );

            return response()->json([
                'status' => true,
                'message' => 'OTP verified successfully. Please complete your registration.',
                'temp_token' => $tempToken,
                'phone' => $request->phone,
                'is_registered ' => $user->is_registered
            ]);
        } catch (\Exception $e) {
            Log::error('Verify OTP error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * STEP 3: Complete Registration - Save all user details and mark as registered
     * Updated to use new BusinessProfile fields
     */
    public function completeRegistration(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|min:10|max:15',
                'temp_token' => 'required|string',
                'account_type' => 'required|in:customer,distributor',
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
                'password' => 'nullable|string|min:8',

                // Updated distributor fields for BusinessProfile
                'encrypted_aadhaar' => 'required_if:account_type,distributor|nullable|string',
                'encrypted_pan' => 'required_if:account_type,distributor|nullable|string',
                'encrypted_bank_account' => 'required_if:account_type,distributor|nullable|string',
                'bank_ifsc' => 'required_if:account_type,distributor|nullable|string|max:20',
                'bank_holder_name' => 'required_if:account_type,distributor|nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find user
            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found. Please request OTP first.'
                ], 422);
            }

            // Check if already registered
            if ($user->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'User is already registered. Please login.'
                ], 422);
            }

            // Verify temp token - Check both database and cache
            $dbToken = $user->temp_verification_token;
            $cachedToken = \Illuminate\Support\Facades\Cache::get('registration_token_' . $request->phone);

            // Log for debugging
            Log::info('Token verification', [
                'user_id' => $user->id,
                'phone' => $request->phone,
                'received_token' => $request->temp_token,
                'db_token' => $dbToken,
                'cached_token' => $cachedToken,
                'db_match' => $dbToken === $request->temp_token,
                'cache_match' => $cachedToken === $request->temp_token
            ]);

            // Check if token matches either database or cache
            $tokenValid = false;
            if ($dbToken && $dbToken === $request->temp_token) {
                $tokenValid = true;
            } elseif ($cachedToken && $cachedToken === $request->temp_token) {
                $tokenValid = true;
            }

            if (!$tokenValid) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid verification token. Please verify OTP again.'
                ], 422);
            }

            // Complete registration
            $updateData = [
                'full_name' => $request->full_name,
                'email' => $request->email,
                'country' => $request->country,
                'account_type' => $request->account_type,
                'terms_condition' => $request->terms_condition,
                'phone_verified_at' => now(),
                'otp' => null,
                'otp_expires_at' => null,
                'temp_verification_token' => null,
                'otp_verified_at' => null,
                'is_registered' => 1,
                'distributor_status' => $request->account_type === 'distributor' ? 'pending' : 'active'
            ];

            // Set password if provided
            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
            }

            // Update user
            $user->update($updateData);
            $user->refresh();

            // Clear cache token
            \Illuminate\Support\Facades\Cache::forget('registration_token_' . $request->phone);

            // Assign role
            $this->assignRoleToUser($user);

            // Store distributor data with new fields
            if ($request->account_type === 'distributor') {
                BusinessProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'user_id' => $user->id,
                        'encrypted_aadhaar' => $request->encrypted_aadhaar,
                        'encrypted_pan' => $request->encrypted_pan,
                        'encrypted_bank_account' => $request->encrypted_bank_account,
                        'bank_ifsc' => $request->bank_ifsc,
                        'bank_holder_name' => $request->bank_holder_name,
                        'kyc_status' => 'pending'
                    ]
                );

                // Send notification for distributor registration
                $admin = DB::table('admins')->first();
                if ($admin) {
                    DB::table('admin_notifications')->insert([
                        'admin_id'       => $admin->id ?? '1',
                        'type'           => 'new_distributor_registration',
                        'title'          => 'New distributor Registration',
                        'message'        => "{$request->full_name} has registered as a distributor.",
                        'reference_type' => 'user',
                        'reference_id'   => $user->id,
                        'priority'       => 'high',
                        'extra_data'     => json_encode([
                            'customer_id'    => $user->id,
                            'customer_name'  => $request->full_name,
                            'customer_phone' => $request->phone,
                            'email'          => $request->email,
                        ]),
                        'created_at'     => now(),
                        'updated_at'     => now()
                    ]);
                }
            }

            // Get user role
            $role = $this->getUserRole($user);

            // Prepare response data
            $responseData = [
                'status' => true,
                'message' => 'Account created successfully',
                'data' => [
                    'user' => $user,
                    'role' => $role ? [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug
                    ] : null,
                    'business_profile' => $request->account_type === 'distributor' ?
                        BusinessProfile::where('user_id', $user->id)->first() : null
                ]
            ];

            // Generate tokens only for customers
            if ($request->account_type == 'customer') {
                $token = $user->createToken('auth_token')->plainTextToken;

                $refreshToken = Str::random(100);

                RefreshToken::create([
                    'user_id'      => $user->id,
                    'token'        => hash('sha256', $refreshToken),
                    'expires_at'   => now()->addDays(7),
                    'last_used_at' => now()
                ]);

                $responseData['token'] = $token;
                $responseData['expires_in'] = 3600;
                $responseData['refresh_token'] = $refreshToken;
            } else {
                // For distributors, they might need to wait for approval
                $responseData['message'] = 'distributor registration submitted successfully. Please wait for admin approval.';
            }

            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            Log::error('Complete registration error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login with phone - Auto detect account type
     */
    // public function login(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'phone' => 'required|min:10|max:15',
    //         ]);

    //         $user = User::where('phone', $request->phone)->first();

    //         if (!$user) {
    //             $rejectedUser = RejectedUser::where('phone', $request->phone)->first();

    //             if ($rejectedUser) {
    //                 $daysPassed = Carbon::parse($rejectedUser->rejected_at)
    //                     ->diffInDays(now());

    //                 if ($daysPassed < 30) {
    //                     $remaining = 30 - $daysPassed;
    //                     return response()->json([
    //                         'status'  => false,
    //                         'message' => "Your business request was rejected. Please try again after {$remaining} days"
    //                     ], 422);
    //                 }

    //                 return response()->json([
    //                     'status'  => false,
    //                     'message' => 'Your business request was rejected more than 30 days ago. Please contact admin.'
    //                 ], 422);
    //             }

    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Phone number not registered'
    //             ], 422);
    //         }

    //         if ($user->is_registered == 0) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Please complete your registration first'
    //             ], 422);
    //         }

    //         if ($user->is_active == 0) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Your account is blocked by admin. Please contact admin to continue'
    //             ], 422);
    //         }

    //         // Check business approval for distributors
    //         if ($user->account_type === 'distributor') {
    //             $businessProfile = BusinessProfile::where('user_id', $user->id)->first();
    //             if ($user && $user->distributor_status  != 'active') {
    //                 return response()->json([
    //                     'status'  => false,
    //                     'message' => 'Your account is waiting for admin approval'
    //                 ], 422);
    //             }

    //             if ($businessProfile && $businessProfile->kyc_status === 'rejected') {
    //                 return response()->json([
    //                     'status'  => false,
    //                     'message' => 'Your KYC has been rejected. Please contact admin.'
    //                 ], 422);
    //             }
    //         }

    //         $otp = rand(100000, 999999);

    //         $user->update([
    //             'otp' => $otp,
    //             'otp_expires_at' => now()->addMinutes(10)
    //         ]);

    //         // $this->twilioService->sendOtp($request->phone, $otp);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'OTP sent successfully to your phone',
    //             'phone' => $request->phone,
    //             'otp' => $otp,
    //             'account_type' => $user->account_type,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    public function login(Request $request)
    {
        try {
            $request->validate([
                'phone' => 'required|min:10|max:15',
            ]);

            $user = User::where('phone', $request->phone)->first();

            if (!$user) {
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

            // Check if user is inactive - Updated message
            if ($user->is_active == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You are blocked by Admin, please contact him'
                ], 422);
            }

            // Check business approval for distributors
            if ($user->account_type === 'distributor') {
                $businessProfile = BusinessProfile::where('user_id', $user->id)->first();
                if ($user && $user->distributor_status  != 'active') {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Your account is waiting for admin approval'
                    ], 422);
                }

                if ($businessProfile && $businessProfile->kyc_status === 'rejected') {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Your KYC has been rejected. Please contact admin.'
                    ], 422);
                }
            }

            $otp = rand(100000, 999999);

            $user->update([
                'otp' => $otp,
                'otp_expires_at' => now()->addMinutes(10)
            ]);

            // $this->twilioService->sendOtp($request->phone, $otp);

            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully to your phone',
                'phone' => $request->phone,
                'otp' => $otp,
                'account_type' => $user->account_type,
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

            $user->update([
                'otp' => null,
                'otp_expires_at' => null
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            $refreshToken = Str::random(100);

            RefreshToken::create([
                'user_id'      => $user->id,
                'token'        => hash('sha256', $refreshToken),
                'expires_at'   => now()->addDays(7),
                'last_used_at' => now()
            ]);

            $role = $this->getUserRole($user);

            // Load business profile for distributors
            $businessProfile = null;
            if ($user->account_type === 'distributor') {
                $businessProfile = BusinessProfile::where('user_id', $user->id)->first();
            }

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

            if ($user->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'User is already registered. Please login.'
                ], 422);
            }

            $otp = rand(100000, 999999);

            $user->update([
                'otp' => $otp,
                'otp_expires_at' => now()->addMinutes(10),
                'temp_verification_token' => null,
                'otp_verified_at' => null
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

            // Load business profile if distributor
            $businessProfile = null;
            if ($user->account_type === 'distributor') {
                $businessProfile = BusinessProfile::where('user_id', $user->id)->first();
            }

            return response()->json([
                'status' => true,
                'user' => $user,
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
