<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminResetOtpMail;
use App\Models\Admin;
use Illuminate\Http\Request;
use App\Models\AdminPasswordOtp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\RejectedUser;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Validate input
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'remember' => 'nullable|boolean',
        ]);

        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        // Attempt login with admin guard
        if (Auth::guard('admin')->attempt($credentials, $remember)) {

            $admin = Auth::guard('admin')->user();

            $token = $admin->createToken('admin-auth-token', ['admin'])->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'admin' => $admin,
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ], 200);
        }

        // Check if admin exists for better error messages
        $admin = \App\Models\Admin::where('email', $request->email)->first();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Admin not found with this email address',
                'errors' => [
                    'email' => ['Admin not found with this email address']
                ]
            ], 404);
        }

        if (!Hash::check($request->password, $admin->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Incorrect password provided',
                'errors' => [
                    'password' => ['Incorrect password provided']
                ]
            ], 422);
        }

        // Fallback
        return response()->json([
            'success' => false,
            'message' => 'Invalid credentials provided',
            'errors' => [
                'email' => ['Invalid credentials provided']
            ]
        ], 422);
    }

    public function logout(Request $request)
    {
        try {
            // Revoke the current access token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout, please try again'
            ], 500);
        }
    }

    /**
     * Get the authenticated Admin.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        try {
            $admin = $request->user();

            return response()->json([
                'success' => true,
                'data' => [
                    'admin' => $admin
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user data'
            ], 422);
        }
    }

    public function sendResetOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);
        $admin = Admin::where('email', $request->email)->first();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Admin not found with this email address',
                'errors' => [
                    'email' => ['Admin not found with this email address']
                ]
            ], 404);
        }

        $otp = random_int(100000, 999999);

        AdminPasswordOtp::updateOrCreate(
            ['email' => $request->email],
            [
                'otp' => $otp,
                'expires_at' => Carbon::now()->addMinutes(10),
            ]
        );

        // Send OTP email
        Mail::to($request->email)->send(new AdminResetOtpMail($otp));

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully to your email address',
            'data' => [
                'email' => $request->email,
                'expires_in' => '10 minutes'
            ]
        ], 200);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|digits:6',
        ]);

        $record = AdminPasswordOtp::where('email', $request->email)->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'OTP record not found. Please request a new OTP',
                'errors' => [
                    'email' => ['OTP record not found. Please request a new OTP']
                ]
            ], 404);
        }

        if (Carbon::now()->gt($record->expires_at)) {
            // Delete expired OTP
            $record->delete();

            return response()->json([
                'success' => false,
                'message' => 'OTP has expired. Please request a new OTP',
                'errors' => [
                    'otp' => ['OTP has expired. Please request a new OTP']
                ]
            ], 410);
        }

        if ($request->otp != $record->otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP entered. Please try again',
                'errors' => [
                    'otp' => ['Invalid OTP entered. Please try again']
                ]
            ], 422);
        }

        // Generate a temporary token for password reset
        $admin = Admin::where('email', $request->email)->first();
        $resetToken = $admin->createToken('password-reset-token', ['password-reset'], now()->addMinutes(10))->plainTextToken;

        // Delete OTP record after successful verification
        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully',
            'data' => [
                'email' => $request->email,
                'reset_token' => $resetToken,
                'expires_in' => '10 minutes'
            ]
        ], 200);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
            'reset_token' => 'required|string'
        ]);

        try {
            // Find the admin by email
            $admin = Admin::where('email', $request->email)->first();

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin not found with this email address',
                    'errors' => [
                        'email' => ['Admin not found with this email address']
                    ]
                ], 404);
            }

            // Find the token in the database
            $tokenRecord = \Laravel\Sanctum\PersonalAccessToken::findToken($request->reset_token);

            if (!$tokenRecord) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired reset token',
                    'errors' => [
                        'reset_token' => ['Invalid or expired reset token']
                    ]
                ], 422);
            }

            // Check if token belongs to the admin
            if ($tokenRecord->tokenable_id != $admin->id || $tokenRecord->tokenable_type != Admin::class) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid reset token',
                    'errors' => [
                        'reset_token' => ['Invalid reset token']
                    ]
                ], 400);
            }

            // Check if token has the correct ability
            if (!$tokenRecord->can('password-reset')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid reset token purpose',
                    'errors' => [
                        'reset_token' => ['Invalid reset token purpose']
                    ]
                ], 400);
            }

            // Check if token is expired
            if ($tokenRecord->expires_at && Carbon::now()->gt($tokenRecord->expires_at)) {
                $tokenRecord->delete();
                return response()->json([
                    'success' => false,
                    'message' => 'Reset token has expired',
                    'errors' => [
                        'reset_token' => ['Reset token has expired']
                    ]
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token',
                'errors' => [
                    'reset_token' => ['Invalid or expired reset token']
                ]
            ], 422);
        }

        // Update password
        $admin->update([
            'password' => Hash::make($request->password),
        ]);

        // Delete all tokens for this admin to force re-login
        $admin->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please login with your new password'
        ], 200);
    }

    public function update(Request $request)
    {
        try {
            $admin = $request->user();

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access'
                ], 401);
            }

            $request->validate([
                'name'            => 'required|string|max:150',
                'email'           => 'required|email|unique:admins,email,' . $admin->id,
                'password'        => 'nullable|min:6',
            ]);

            $admin->name = $request->name;
            $admin->email = $request->email;


            if ($request->filled('password')) {
                $admin->password = Hash::make($request->password);
            }

            $admin->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'admin' => $admin
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'errors' => [
                    'auth' => ['Failed to update profile']
                ]
            ], 422);
        }
    }
}
