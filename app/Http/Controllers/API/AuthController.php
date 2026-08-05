<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
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

class AuthController extends Controller
{
    public function sendOtp(Request $request)
    {
        try {
            /*
            --------------------------------
            FIND EXISTING USER FIRST
            --------------------------------
            */
            $existingUser = User::where('email', $request->email)->first();

            /*
            --------------------------------
            VALIDATION
            --------------------------------
            */
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'account_type' => 'required|in:user,distributer',
                'full_name' => 'required|string|max:255',

                'phone' => [
                    'nullable',
                    'digits_between:10,15',
                    Rule::unique('users', 'phone')
                        ->ignore(optional($existingUser)->id)
                        ->where(function ($query) {
                            return $query->where('is_registered', 1);
                        }),
                ],

                'country' => 'nullable|string|max:255',
                'terms_condition' => 'required|in:0,1',

                'password' => [
                    'required',
                    'string',
                    Password::min(8)->mixedCase()->numbers()->symbols()
                ],

                // Distributer fields
                'company_name' => 'required_if:account_type,distributer',
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
            BLOCK IF ALREADY REGISTERED
            --------------------------------
            */
            if ($existingUser && $existingUser->is_registered == 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email is already registered'
                ], 422);
            }

            /*
            --------------------------------
            GENERATE OTP
            --------------------------------
            */
            $otp = rand(1000, 9999);

            /*
            --------------------------------
            CREATE OR UPDATE USER
            --------------------------------
            */
            $user = User::updateOrCreate(
                ['email' => $request->email],
                [
                    'full_name' => $request->full_name,
                    'phone' => $request->phone,
                    'country' => $request->country,
                    'account_type' => $request->account_type,
                    'terms_condition' => $request->terms_condition,
                    'password' => Hash::make($request->password),
                    'otp' => $otp,
                    'otp_expires_at' => now()->addMinutes(10),
                    'is_registered' => 0
                ]
            );

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
            }

            /*
            --------------------------------
            SEND OTP MAIL
            --------------------------------
            */
            Mail::html("
                <p>Hello,</p>

                <p>Your One-Time Password (OTP) for email verification is:</p>

                <h1 style='color: black; font-weight: 800; letter-spacing: 3px; margin: 0;'>{$otp}</h1>

                <p>This OTP is valid for <strong>10 minutes</strong>. Please do not share it with anyone.</p>

                <p>If you did not request this OTP, you can safely ignore this email.</p>

                <br>

                <p>Regards,<br><strong>" . e(env('APP_NAME')) . " Team</strong></p>
            ", function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('Email Verification OTP');
            });

            /*
            --------------------------------
            RESPONSE
            --------------------------------
            */
            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully',
                'otp' => $otp
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /* -----------------------------
    | VerifyOtp Function
    ----------------------------- */
    public function verifyOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'otp' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('email', $request->email)->first();

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

            if (now()->gt($user->otp_expires_at)) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP has expired'
                ], 422);
            }

            /*
            --------------------------------
            FINAL REGISTRATION HERE
            --------------------------------
            */
            $user->update([
                'email_verified_at' => now(),
                'otp' => null,
                'otp_expires_at' => null,
                'is_registered' => 1,
                'business_status' => $user->account_type === 'distributer' ? 'pending' : null
            ]);

            // Send notification for distributer registration
            if ($user->account_type === 'distributer') {
                $admin = DB::table('admins')->first();

                if ($admin) {
                    DB::table('admin_notifications')->insert([
                        'admin_id'       => $admin->id,
                        'type'           => 'new_distributer_registration',
                        'title'          => 'New Distributer Registration',
                        'message'        => "{$user->full_name} has registered as a distributer.",
                        'reference_type' => 'user',
                        'reference_id'   => $user->id,
                        'priority'       => 'high',
                        'extra_data'     => json_encode([
                            'customer_id'    => $user->id,
                            'customer_name'  => $user->full_name,
                            'customer_email' => $user->email,
                            'phone'          => $user->phone
                        ]),
                        'created_at'     => now(),
                        'updated_at'     => now()
                    ]);
                }
            }

            /*
            --------------------------------
            ASSIGN ROLE
            --------------------------------
            */
            // Get role based on account type
            $roleSlug = $user->account_type === 'distributer' ? 'distributer' : 'user';
            $role = Role::where('slug', $roleSlug)->first();

            if ($role) {
                DB::table('role_users')->updateOrInsert(
                    ['user_id' => $user->id],
                    [
                        'role_id' => $role->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                );
            }

            return response()->json([
                'status' => true,
                'message' => 'Account created successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */
            $request->validate([
                'email'        => 'required|email',
                'password'     => 'required',
                'account_type' => 'required|in:user,distributer,warehouse_manager,sales_executive',
            ]);

            /*
            |--------------------------------------------------------------------------
            | USER FETCH
            |--------------------------------------------------------------------------
            */
            $user = User::with('roles')
                ->where('email', $request->email)
                ->where('account_type', $request->account_type)
                ->first();

            /*
            |--------------------------------------------------------------------------
            | USER NOT FOUND
            |--------------------------------------------------------------------------
            */
            if (!$user) {
                $rejectedUser = RejectedUser::where('email', $request->email)->first();

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
                    'message' => 'Invalid email or account type'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | REGISTRATION CHECK
            |--------------------------------------------------------------------------
            */
            if ($user->is_registered == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Please complete your registration first'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | ACTIVE CHECK
            |--------------------------------------------------------------------------
            */
            if ($user->is_active == 0) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your account is blocked by admin. Please contact admin to continue'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | PASSWORD CHECK
            |--------------------------------------------------------------------------
            */
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid password'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | JWT TOKEN CREATE
            |--------------------------------------------------------------------------
            */
            $token = Auth::login($user);

            $refreshToken = Str::random(100);

            RefreshToken::create([
                'user_id'      => $user->id,
                'token'        => hash('sha256', $refreshToken),
                'expires_at'   => now()->addDays(7),
                'last_used_at' => now()
            ]);

            /*
            |--------------------------------------------------------------------------
            | BUSINESS APPROVAL CHECK (Only for distributers)
            |--------------------------------------------------------------------------
            */
            if (
                $user->account_type === 'distributer' &&
                $user->business_status === 'pending'
            ) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Your business request is waiting for admin approval'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | SUCCESS RESPONSE
            |--------------------------------------------------------------------------
            */
            return response()->json([
                'status' => true,
                'message' => 'Login successfully',
                'token' => $token,
                'expires_in' => 3600,
                'refresh_token' => $refreshToken,
                'user' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

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
                ], 401);
            }

            if ($refreshToken->expires_at->isPast()) {
                $refreshToken->delete();
                return response()->json([
                    'status' => false,
                    'message' => 'Refresh token expired. Please login again.'
                ], 401);
            }

            $user = User::find($refreshToken->user_id);
            $newAccessToken = Auth::login($user);

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

    /* -----------------------------
    | Profile Function
    ----------------------------- */
    public function profile()
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
            LOAD RELATIONS
            --------------------------------
            */
            $user->load('roles', 'businessProfile');

            /*
            --------------------------------
            DOCUMENT FULL URL
            --------------------------------
            */
            if (
                $user->businessProfile &&
                $user->businessProfile->document_path
            ) {
                $user->businessProfile->document_path =
                    url('/storage/' . $user->businessProfile->document_path);
            }

            /*
            --------------------------------
            GET CURRENT TOKEN
            --------------------------------
            */
            $token = Auth::getToken();

            return response()->json([
                'status' => true,
                'message' => 'Profile fetched successfully',
                'token' => $token ? $token->get() : null,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

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
            if ($user->account_type === 'distributer' && $request->account_type === 'user') {
                return response()->json([
                    'status' => false,
                    'message' => 'Distributer account cannot be converted to user'
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
                'account_type' => 'nullable|in:user,distributer',
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

                if ($accountType === 'user') {
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
            SWITCH user → distributer
            --------------------------------
            */
            if ($user->account_type === 'user' && $accountType === 'distributer') {
                $data['account_type'] = 'distributer';
                $data['business_status'] = 'pending';

                // Update role
                $distributerRole = Role::where('slug', 'distributer')->first();

                if ($distributerRole) {
                    $data['role_id'] = $distributerRole->id;
                    RoleUser::updateOrCreate(
                        ['user_id' => $user->id],
                        ['role_id' => $distributerRole->id]
                    );
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

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully',
                'user' => $user->fresh(),
                'business_profile' => $businessProfile
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

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

    /* -----------------------------
    | Forgot password Function
    ----------------------------- */
    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email'
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Email not registered'
                ], 422);
            }

            $otp = rand(1000, 9999);

            if ($user->is_registered == 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Please complete your registration first'
                ], 422);
            }

            $user->update([
                'otp' => $otp,
                'otp_expires_at' => Carbon::now()->addMinutes(10)
            ]);

            Mail::html("
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                        <h2 style='color: #333;'>Password Reset Request</h2>

                        <p>Hello,</p>

                        <p>We received a request to reset your password. Please use the OTP below to proceed:</p>

                        <div style='background: #f4f4f4; padding: 15px; text-align: center; border-radius: 5px; margin: 20px 0;'>
                            <h1 style='margin: 0; color: black; letter-spacing: 5px;'>$otp</h1>
                        </div>

                        <p>This OTP is valid for <strong>10 minutes</strong>. Please do not share it with anyone.</p>

                        <p>If you did not request a password reset, you can safely ignore this email.</p>

                        <br>

                        <p>Regards,<br><strong>" . env('APP_NAME') . " Team</strong></p>
                    </div>
                ", function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Password Reset OTP');
            });

            return response()->json([
                'status' => true,
                'message' => 'OTP sent to your email'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /* -----------------------------
    | Verify forgot password otp Function
    ----------------------------- */
    public function verifyResetOtp(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'otp' => 'required'
            ]);

            $user = User::where('email', $request->email)->first();

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

    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
                'password' => [
                    'required',
                    'confirmed',
                    Password::min(8)
                        ->mixedCase()
                        ->numbers()
                        ->symbols()
                ]
            ]);

            $user = User::where('email', $request->email)->first();

            $user->update([
                'password' => Hash::make($request->password),
                'otp' => null,
                'otp_expires_at' => null
            ]);

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
     * Logout user
     */
    public function logout(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Delete refresh tokens
            RefreshToken::where('user_id', $user->id)->delete();

            // Logout
            Auth::logout();

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
}
