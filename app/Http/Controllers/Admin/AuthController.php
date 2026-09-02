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
use App\Mail\DistributorStatusMail;
use Carbon\Carbon;
use App\Models\User;
use App\Models\BusinessProfile;
use Illuminate\Validation\Rule;
use App\Traits\AuditLogTrait;
use App\Models\RejectedUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    use AuditLogTrait;
    // public function login(Request $request)
    // {
    //     // Validate input
    //     $request->validate([
    //         'email'    => 'required|email',
    //         'password' => 'required|string',
    //         'remember' => 'nullable|boolean',
    //     ]);

    //     $credentials = $request->only('email', 'password');
    //     $remember = $request->boolean('remember');

    //     // Attempt login with admin guard
    //     if (Auth::guard('admin')->attempt($credentials, $remember)) {
    //         $admin = Auth::guard('admin')->user();
    //         // Load roles and permissions
    //         $admin->load('roles.permissions');

    //         // Get all permissions for the admin
    //         $permissions = $this->getAdminPermissions($admin);

    //         // Create token
    //         $token = $admin->createToken('admin-auth-token', ['admin'])->plainTextToken;

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Login successful',
    //             'data' => [
    //                 'admin' => [
    //                     'id' => $admin->id,
    //                     'name' => $admin->name,
    //                     'email' => $admin->email,
    //                     'roles' => $admin->roles->map(function ($role) {
    //                         return [
    //                             'id' => $role->id,
    //                             'name' => $role->name,
    //                             'slug' => $role->slug,
    //                         ];
    //                     }),
    //                     'created_at' => $admin->created_at,
    //                     'updated_at' => $admin->updated_at,
    //                 ],
    //                 'token' => $token,
    //                 'token_type' => 'Bearer',
    //                 'permissions' => $permissions
    //             ]
    //         ], 200);
    //     }

    //     // Check if admin exists for better error messages
    //     $admin = Admin::where('email', $request->email)->first();

    //     if (!$admin) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Admin not found with this email address',
    //             'errors' => [
    //                 'email' => ['Admin not found with this email address']
    //             ]
    //         ], 404);
    //     }

    //     if (!Hash::check($request->password, $admin->password)) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Incorrect password provided',
    //             'errors' => [
    //                 'password' => ['Incorrect password provided']
    //             ]
    //         ], 422);
    //     }

    //     // Fallback
    //     return response()->json([
    //         'success' => false,
    //         'message' => 'Invalid credentials provided',
    //         'errors' => [
    //             'email' => ['Invalid credentials provided']
    //         ]
    //     ], 422);
    // }
    public function login(Request $request)
    {
        try {
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

                // Load roles and permissions
                $admin->load('roles.permissions');

                // Get all permissions
                $permissions = $this->getAdminPermissions($admin);

                // Create token
                $token = $admin->createToken(
                    'admin-auth-token',
                    ['admin']
                )->plainTextToken;

                // Log successful login
                $this->logAudit(
                    'login',
                    'auth',
                    $admin->toArray(),
                    [
                        'admin_id' => $admin->id,
                        'email' => $admin->email,
                        'status' => 'success',
                        'login_method' => 'email_password',
                    ],
                    $admin->id
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Login successful',
                    'data' => [
                        'admin' => [
                            'id' => $admin->id,
                            'name' => $admin->name,
                            'email' => $admin->email,
                            'profile_image' => $admin->profile_image
                                ? url('storage/' . $admin->profile_image)
                                : null,
                            'roles' => $admin->roles->map(function ($role) {
                                return [
                                    'id' => $role->id,
                                    'name' => $role->name,
                                    'slug' => $role->slug,
                                ];
                            }),
                            'created_at' => $admin->created_at,
                            'updated_at' => $admin->updated_at,
                        ],
                        'token' => $token,
                        'token_type' => 'Bearer',
                        'permissions' => $permissions
                    ]
                ], 200);
            }

            // Check if admin exists
            $admin = Admin::where('email', $request->email)->first();

            if (!$admin) {

                // Log failed login
                $this->logAudit(
                    'login_failed',
                    'auth',
                    null,
                    [
                        'email' => $request->email,
                        'status' => 'failed',
                        'reason' => 'admin_not_found',
                    ],
                    null
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Admin not found with this email address',
                    'errors' => [
                        'email' => [
                            'Admin not found with this email address'
                        ]
                    ]
                ], 404);
            }

            // Check password
            if (!Hash::check($request->password, $admin->password)) {

                // Log failed login
                $this->logAudit(
                    'login_failed',
                    'auth',
                    null,
                    [
                        'admin_id' => $admin->id,
                        'email' => $admin->email,
                        'status' => 'failed',
                        'reason' => 'incorrect_password',
                    ],
                    $admin->id
                );

                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect password provided',
                    'errors' => [
                        'password' => [
                            'Incorrect password provided'
                        ]
                    ]
                ], 422);
            }

            // Fallback
            $this->logAudit(
                'login_failed',
                'auth',
                null,
                [
                    'admin_id' => $admin->id,
                    'email' => $admin->email,
                    'status' => 'failed',
                    'reason' => 'invalid_credentials',
                ],
                $admin->id
            );

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials provided',
                'errors' => [
                    'email' => [
                        'Invalid credentials provided'
                    ]
                ]
            ], 422);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to login, please try again',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function logout(Request $request)
    {
        try {
            // Revoke the current access token
            $admin = $request->user('admin');

            if ($admin && $admin->currentAccessToken()) {
                $this->logAudit(
                    'logout',
                    'auth',
                    $admin->toArray(),
                    [
                        'admin_id' => $admin->id,
                        'email' => $admin->email,
                        'session_id' => $admin->currentAccessToken()->id ?? null,
                        'status' => 'success'
                    ],
                    $admin->id
                );

                $admin->currentAccessToken()->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout, please try again',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the authenticated Admin.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    // public function me(Request $request)
    // {
    //     try {
    //         $admin = $request->user('admin');

    //         if (!$admin) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Unauthorized'
    //             ], 401);
    //         }

    //         // Load roles and permissions
    //         $admin->load('roles.permissions');

    //         // Get all permissions for the admin
    //         $permissions = $this->getAdminPermissions($admin);

    //         return response()->json([
    //             'success' => true,
    //             'data' => [
    //                 'admin' => [
    //                     'id' => $admin->id,
    //                     'name' => $admin->name,
    //                     'email' => $admin->email,
    //                     'roles' => $admin->roles->map(function ($role) {
    //                         return [
    //                             'id' => $role->id,
    //                             'name' => $role->name,
    //                             'slug' => $role->slug,
    //                             'description' => $role->description,
    //                         ];
    //                     }),
    //                     'created_at' => $admin->created_at,
    //                     'updated_at' => $admin->updated_at,
    //                 ],
    //                 'permissions' => $permissions
    //             ]
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to get user data',
    //             'error' => $e->getMessage()
    //         ], 422);
    //     }
    // }
    public function me(Request $request)
    {
        try {
            $admin = $request->user();

            if (!$admin || !$admin instanceof \App\Models\Admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $admin->load('roles.permissions');

            $permissions = $this->getAdminPermissions($admin);

            $detailedPermissions = $this->getDetailedPermissions($admin);

            return response()->json([
                'success' => true,
                'data' => [
                    'admin' => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                        'profile_image' => $admin->profile_image
                            ? asset('storage/' . $admin->profile_image)
                            : null,

                        'roles' => $admin->roles->map(function ($role) {
                            return [
                                'id' => $role->id,
                                'name' => $role->name,
                                'slug' => $role->slug,
                                'description' => $role->description,

                                'permissions' => $role->permissions->map(function ($permission) {
                                    return [
                                        'id' => $permission->id,
                                        'name' => $permission->name,
                                        'slug' => $permission->slug,
                                        'module' => $permission->module,
                                        'action' => $permission->action,
                                    ];
                                }),
                            ];
                        }),

                        'created_at' => $admin->created_at,
                        'updated_at' => $admin->updated_at,
                    ],

                    // 'permissions' => $permissions,
                    // 'permissions_details' => $detailedPermissions,
                    'permissions_grouped' => $this->getGroupedPermissions($admin),
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get admin data',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    private function getAdminPermissions($admin)
    {
        $permissions = [];
        foreach ($admin->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = $permission->slug;
            }
        }
        return array_values(array_unique($permissions));
    }

    /**
     * Get detailed permission objects for an admin.
     */
    private function getDetailedPermissions($admin)
    {
        $permissions = [];
        $seen = [];

        foreach ($admin->roles as $role) {
            foreach ($role->permissions as $permission) {
                if (!in_array($permission->id, $seen)) {
                    $permissions[] = [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'slug' => $permission->slug,
                        'module' => $permission->module,
                        'action' => $permission->action,
                        'created_at' => $permission->created_at,
                        'updated_at' => $permission->updated_at,
                    ];
                    $seen[] = $permission->id;
                }
            }
        }

        return $permissions;
    }

    /**
     * Get permissions grouped by module.
     */
    private function getGroupedPermissions($admin)
    {
        $grouped = [];
        $seen = [];

        foreach ($admin->roles as $role) {
            foreach ($role->permissions as $permission) {
                if (!in_array($permission->id, $seen)) {
                    if (!isset($grouped[$permission->module])) {
                        $grouped[$permission->module] = [];
                    }
                    $grouped[$permission->module][] = [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'slug' => $permission->slug,
                        'action' => $permission->action,
                    ];
                    $seen[] = $permission->id;
                }
            }
        }

        return $grouped;
    }

    /**
     * Get all permissions for an admin.
     *
     * @param \App\Models\Admin $admin
     * @return array
     */
    // private function getAdminPermissions($admin)
    // {
    //     $permissions = [];
    //     foreach ($admin->roles as $role) {
    //         foreach ($role->permissions as $permission) {
    //             $permissions[] = $permission->slug;
    //         }
    //     }
    //     return array_values(array_unique($permissions));
    // }

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
            'otp'     => $otp,
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

    // public function update(Request $request)
    // {
    //     try {
    //         $admin = $request->user();

    //         if (!$admin) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Unauthorized access'
    //             ], 401);
    //         }

    //         $request->validate([
    //             'name'            => 'required|string|max:150',
    //             'email'           => 'required|email|unique:admins,email,' . $admin->id,
    //             'password'        => 'nullable|min:6',
    //         ]);

    //         $admin->name = $request->name;
    //         $admin->email = $request->email;


    //         if ($request->filled('password')) {
    //             $admin->password = Hash::make($request->password);
    //         }

    //         $admin->save();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Profile updated successfully',
    //             'data' => [
    //                 'admin' => $admin
    //             ]
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to update profile',
    //             'errors' => [
    //                 'auth' => ['Failed to update profile']
    //             ]
    //         ], 422);
    //     }
    // }
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
                'name'         => 'required|string|max:150',
                'email'        => 'required|email|unique:admins,email,' . $admin->id,
                'password'     => 'nullable|string|min:6',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            ]);

            $admin->name = $request->name;
            $admin->email = $request->email;

            // Update password
            if ($request->filled('password')) {
                $admin->password = Hash::make($request->password);
            }

            // Update profile image
            if ($request->hasFile('profile_image')) {

                // Delete old profile image
                if ($admin->profile_image && Storage::disk('public')->exists($admin->profile_image)) {
                    Storage::disk('public')->delete($admin->profile_image);
                }

                // Store new image
                $admin->profile_image = $request->file('profile_image')
                    ->store('admin/profile-images', 'public');
            }

            $admin->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'admin' => $admin
                ]
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
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


    public function getRegisteredUsers()
    {
        try {
            $users = User::with('role', 'businessProfile')->where('is_registered', true)
                ->orderBy('id', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Registered users fetched successfully.',
                'data' => $users
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch registered users.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUserDetails($id)
    {
        try {
            $user = User::with('role', 'businessProfile')->where('is_registered', true)
                ->where('id', $id)
                ->get();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                    'errors' => [
                        'user_id' => ['User not found.']
                    ]
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'User details fetched successfully.',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user details.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // public function toggleUserStatus($id)
    // {
    //     try {
    //         $user = User::find($id);

    //         if (!$user) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'User not found.',
    //                 'errors' => [
    //                     'user_id' => ['User not found.']
    //                 ]
    //             ], 404);
    //         }
    //         // Toggle status
    //         $user->is_active = !$user->is_active;
    //         $user->save();

    //         return response()->json([
    //             'success' => true,
    //             'message' => $user->is_active
    //                 ? 'User activated successfully.'
    //                 : 'User deactivated successfully.',
    //             'data' => [
    //                 'user_id' => $user->id,
    //                 'is_active' => (bool) $user->is_active,
    //                 'status' => $user->is_active ? 'active' : 'inactive'
    //             ]
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to update user status.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function toggleUserStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'is_active' => 'required|boolean',
            ]);

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                    'errors' => [
                        'user_id' => ['User not found.']
                    ]
                ], 404);
            }
            $oldStatus = $user->is_active;
            $newStatus = $request->is_active;
            // Update status from request
            $user->is_active = $request->is_active;
            $user->save();

            $this->logAudit(
                'user_status_change',
                'user_management',
                [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'old_status' => $oldStatus ? 'active' : 'inactive',
                    'old_is_active' => $oldStatus,
                ],
                [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'new_status' => $newStatus ? 'active' : 'inactive',
                    'new_is_active' => $newStatus,
                    'changed_by' => $this->getAdminId(),
                    'action' => $newStatus ? 'activation' : 'deactivation'
                ]
            );

            return response()->json([
                'success' => true,
                'message' => $user->is_active
                    ? 'User activated successfully.'
                    : 'User deactivated successfully.',
                'data' => [
                    'user_id' => $user->id,
                    'is_active' => (bool) $user->is_active,
                    'status' => $user->is_active ? 'active' : 'inactive'
                ]
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // public function updateStatus(Request $request, int $id)
    // {
    //     $request->validate([
    //         'kyc_status' => [
    //             'required',
    //             Rule::in(['verified', 'rejected']),
    //         ],
    //     ]);

    //     try {
    //         // Find distributor
    //         $distributor = User::where('id', $id)
    //             ->where('account_type', 'distributor')
    //             ->first();

    //         if (!$distributor) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Distributor not found.',
    //             ], 404);
    //         }

    //         // Find business profile
    //         $businessProfile = BusinessProfile::where('user_id', $distributor->id)
    //             ->first();

    //         if (!$businessProfile) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Business profile not found.',
    //             ], 404);
    //         }

    //         $newKycStatus = $request->kyc_status;

    //         // Automatically determine distributor status
    //         $newDistributorStatus = match ($newKycStatus) {
    //             'verified' => 'active',
    //             'rejected' => 'suspended',
    //         };

    //         // Check if both statuses are already same
    //         if (
    //             $businessProfile->kyc_status === $newKycStatus &&
    //             $distributor->distributor_status === $newDistributorStatus
    //         ) {
    //             return response()->json([
    //                 'success' => true,
    //                 'message' => 'KYC and distributor status are already updated.',
    //                 'data' => [
    //                     'user_id' => $distributor->id,
    //                     'kyc_status' => $businessProfile->kyc_status,
    //                     'distributor_status' => $distributor->distributor_status,
    //                 ],
    //             ], 200);
    //         }

    //         // Update KYC status
    //         $businessProfile->kyc_status = $newKycStatus;
    //         $businessProfile->save();

    //         // Automatically update distributor status
    //         $distributor->distributor_status = $newDistributorStatus;
    //         $distributor->save();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'KYC and distributor status updated successfully.',
    //             'data' => [
    //                 'user_id' => $distributor->id,
    //                 'kyc_status' => $businessProfile->kyc_status,
    //                 'distributor_status' => $distributor->distributor_status,
    //             ],
    //         ], 200);
    //     } catch (\Throwable $e) {

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Something went wrong.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'kyc_status' => [
                'required',
                Rule::in(['verified', 'rejected']),
            ],
            'rejection_reason' => 'required_if:kyc_status,rejected|string|max:500|nullable',
        ]);

        try {
            // Find distributor
            $distributor = User::where('id', $id)
                ->where('account_type', 'distributor')
                ->first();

            if (!$distributor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distributor not found.',
                ], 404);
            }

            // Find business profile
            $businessProfile = BusinessProfile::where('user_id', $distributor->id)
                ->first();

            if (!$businessProfile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Business profile not found.',
                ], 404);
            }

            $newKycStatus = $request->kyc_status;
            $rejectionReason = $request->rejection_reason ?? null;

            // Automatically determine distributor status
            $newDistributorStatus = match ($newKycStatus) {
                'verified' => 'active',
                'rejected' => 'suspended',
            };

            // Check if both statuses are already same
            if (
                $businessProfile->kyc_status === $newKycStatus &&
                $distributor->distributor_status === $newDistributorStatus
            ) {
                return response()->json([
                    'success' => true,
                    'message' => 'KYC and distributor status are already updated.',
                    'data' => [
                        'user_id' => $distributor->id,
                        'kyc_status' => $businessProfile->kyc_status,
                        'distributor_status' => $distributor->distributor_status,
                        'rejection_reason' => $businessProfile->rejection_reason,
                    ],
                ], 200);
            }

            // Update KYC status
            $businessProfile->kyc_status = $newKycStatus;

            // Store rejection reason if rejected
            if ($newKycStatus === 'rejected') {
                $businessProfile->rejection_reason = $rejectionReason;
            } else {
                // Clear rejection reason if verified
                $businessProfile->rejection_reason = null;
            }

            $businessProfile->save();

            // Automatically update distributor status
            $distributor->distributor_status = $newDistributorStatus;
            $distributor->save();

            // Send email notification to user with rejection reason if applicable
            $this->sendKycStatusEmail($distributor, $newKycStatus, $rejectionReason);

            return response()->json([
                'success' => true,
                'message' => 'KYC and distributor status updated successfully.',
                'data' => [
                    'user_id' => $distributor->id,
                    'kyc_status' => $businessProfile->kyc_status,
                    'distributor_status' => $distributor->distributor_status,
                    'rejection_reason' => $businessProfile->rejection_reason,
                ],
            ], 200);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send KYC status email to user
     */
    protected function sendKycStatusEmail(User $user, string $status, ?string $rejectionReason = null)
    {
        try {
            $subject = $status === 'verified'
                ? 'Your KYC has been verified'
                : 'Your KYC has been rejected';

            // Build email content
            if ($status === 'verified') {
                $message = "Dear {$user->full_name},\n\n";
                $message .= "Your KYC has been verified successfully. Your distributor account is now active.\n\n";
                $message .= "You can now start using all distributor features.\n\n";
                $message .= "Thank you for choosing " . config('app.name') . ".";
            } else {
                $message = "Dear {$user->full_name},\n\n";
                $message .= "Your KYC has been rejected.\n\n";

                if ($rejectionReason) {
                    $message .= "Reason for rejection:\n";
                    $message .= "----------------------------------------\n";
                    $message .= "{$rejectionReason}\n";
                    $message .= "----------------------------------------\n\n";
                }

                $message .= "Please log in to your account to update your documents and resubmit for verification.\n\n";
                $message .= "If you have any questions, please contact support.\n\n";
                $message .= "Thank you for choosing " . config('app.name') . ".";
            }

            Mail::raw($message, function ($mail) use ($user, $subject) {
                $mail->to($user->email)
                    ->subject($subject);
            });

            Log::info('KYC status email sent to user: ' . $user->email . ' with status: ' . $status);
        } catch (\Exception $e) {
            Log::error('Failed to send KYC status email: ' . $e->getMessage());
        }
    }
}
