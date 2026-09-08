<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\RoleUser;
use App\Models\BusinessProfile;
use App\Models\UserNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AdminUserController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name'         => 'required|string|max:255',
            'email'             => 'required|email|unique:users,email',
            'phone'             => 'required|min:10|max:15|unique:users,phone',
            'password'          => 'required|string|min:8|confirmed',
            'country'           => 'nullable|string|max:255',
            'date_of_birth'     => 'nullable|date|before:-18 years',
            'distributor_status'=> 'nullable|in:active,pending,suspended',
            // Location data
            'location'          => 'nullable|array',
            'location.city'     => 'nullable|string|max:255',
            'location.address'  => 'nullable|string',
            // Optional KYC / bank (admin can pre‑fill)
            'aadhaar'           => 'nullable|string|size:12',
            'pan'               => 'nullable|string|size:10',
            'bank_details'      => 'nullable|array',
            'bank_details.bank_name'        => 'nullable|string',
            'bank_details.bank_holder_name' => 'nullable|string',
            'bank_details.account_number'   => 'nullable|string',
            'bank_details.ifsc'             => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Create user
            $user = User::create([
                'full_name'          => $request->full_name,
                'email'              => $request->email,
                'phone'              => $request->phone,
                'country'            => $request->country,
                'password'           => Hash::make($request->password),
                'date_of_birth'      => $request->date_of_birth,
                'account_type'       => 'distributor',
                'is_registered'      => 1,
                'distributor_status' => $request->distributor_status ?? 'active',
                'registration_step'  => 7,
                'registration_completed_at' => now(),
            ]);

            // 2. Assign distributor role
            $roleId = Role::where('slug', 'distributor')->value('id');
            if ($roleId) {
                RoleUser::updateOrInsert(['user_id' => $user->id], ['role_id' => $roleId]);
                $user->update(['role_id' => $roleId]);
            }

            // 3. Create BusinessProfile
            $profileData = [
                'user_id'            => $user->id,
                'kyc_status'         => 'verified',
                'application_status' => 'approved',
                'submitted_at'       => now(),
                'terms_accepted_at'  => now(),
                'registration_completed' => 1,
                'city'               => $request->location['city'] ?? null,
                'address'            => $request->location['address'] ?? null,
            ];

            // KYC fields (if provided, mark as verified)
            if ($request->filled('aadhaar')) {
                $profileData['encrypted_aadhaar'] = encrypt($request->aadhaar);
                $profileData['aadhaar_verified'] = 1;
                $profileData['aadhaar_verified_at'] = now();
                $user->update(['aadhaar_last4' => substr($request->aadhaar, -4)]);
            }
            if ($request->filled('pan')) {
                $profileData['encrypted_pan'] = encrypt($request->pan);
                $profileData['pan_verified'] = 1;
                $profileData['pan_verified_at'] = now();
                $user->update(['pan_last4' => substr($request->pan, -4)]);
            }
            if ($request->filled('bank_details')) {
                $bank = $request->bank_details;
                $profileData['bank_holder_name'] = $bank['bank_holder_name'] ?? null;
                $profileData['bank_name']        = $bank['bank_name'] ?? null;
                $profileData['bank_ifsc']        = $bank['ifsc'] ?? null;
                if (!empty($bank['account_number'])) {
                    $profileData['encrypted_bank_account'] = encrypt($bank['account_number']);
                    $user->update(['account_last4' => substr($bank['account_number'], -4)]);
                }
            }

            $businessProfile = BusinessProfile::create($profileData);

            // 4. Notification settings
            UserNotificationSetting::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'email_notifications' => true,
                    'order_updates'       => true,
                    'payment_alerts'      => true,
                    'promotional_emails'  => true,
                    'security_alerts'     => true,
                ]
            );

            // 5. Send welcome email (optional)
            // Mail::to($user->email)->send(new DistributorWelcomeMail($user, $request->password));

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Distributor account created successfully.',
                'user'    => $user->load('businessProfile'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Admin user creation error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create user. ' . $e->getMessage(),
            ], 500);
        }
    }
}