<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\UserNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminUserController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // ===== Step 1: Personal Info =====
            'full_name'         => 'required|string|max:255',
            'email'             => 'required|email|unique:users,email',
            'phone'             => 'required|min:10|max:15|unique:users,phone',
            'country'           => 'nullable|string|max:255',      // country is still there
            'password'          => 'required|string|min:8|confirmed',
            'date_of_birth'     => 'nullable|date|before:-18 years',
            'terms_condition'   => 'nullable|in:0,1',

            // ===== Step 2: Sponsor =====
            'sponsor_id'        => 'nullable|string|max:20',
            'placement_leg'     => 'nullable|in:left,right',

            // ===== Step 3: Aadhaar =====
            'encrypted_aadhaar' => 'nullable|string|size:12',
            'aadhaar_consent'   => 'nullable|in:0,1',

            // ===== Step 4: PAN =====
            'encrypted_pan'     => 'nullable|string|size:10',

            // ===== Step 5: Bank =====
            'bank_holder_name'  => 'nullable|string|max:255',
            'bank_name'         => 'nullable|string|max:255',
            'title'             => 'nullable|string|max:255',
            'type_of_entity'    => 'nullable|string|max:255',
            'branch_name'       => 'nullable|string|max:255',
            'encrypted_bank_account' => 'nullable|string|max:50',
            'confirm_account_number' => 'nullable|string|max:50',
            'bank_ifsc'         => 'nullable|string|max:20',
            'account_type'      => 'nullable|in:current,savings',

            // ===== Step 6: Location Consent (latitude/longitude only) =====
            'location_consent'  => 'nullable|in:0,1',
            'latitude'          => 'nullable|numeric',
            'longitude'         => 'nullable|numeric',

            // ===== Step 7: Acceptances =====
            'accept_terms'            => 'nullable|in:0,1',
            'accept_agreement'        => 'nullable|in:0,1',
            'accept_code_of_conduct'  => 'nullable|in:0,1',

            // ===== Admin control =====
            'distributor_status' => 'nullable|in:active,pending,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        // Check bank account confirmation
        if ($request->filled('encrypted_bank_account') && 
            $request->encrypted_bank_account !== $request->confirm_account_number) {
            return response()->json([
                'status' => false,
                'message' => 'Account numbers do not match.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // ----- Create User -----
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
                'sponsor_id'         => $request->sponsor_id,
                'placement_leg'      => $request->placement_leg,
                'terms_condition'    => $request->terms_condition ?? 1,
                'location_consent_given' => $request->location_consent ?? 0,
                'accept_terms'       => $request->accept_terms ?? 1,
                'accept_agreement'   => $request->accept_agreement ?? 1,
                'accept_code_of_conduct' => $request->accept_code_of_conduct ?? 1,
            ]);

            // Save last 4 digits
            if ($request->filled('encrypted_aadhaar')) {
                $user->update(['aadhaar_last4' => substr($request->encrypted_aadhaar, -4)]);
            }
            if ($request->filled('encrypted_pan')) {
                $user->update(['pan_last4' => substr($request->encrypted_pan, -4)]);
            }
            if ($request->filled('encrypted_bank_account')) {
                $user->update(['account_last4' => substr($request->encrypted_bank_account, -4)]);
            }

            // ----- Create BusinessProfile (without city/address) -----
            $profileData = [
                'user_id'            => $user->id,
                'kyc_status'         => 'verified',
                'application_status' => 'approved',
                'submitted_at'       => now(),
                'terms_accepted_at'  => now(),
                'registration_completed' => 1,
                // Location consent (only latitude/longitude)
                'latitude'           => $request->latitude,
                'longitude'          => $request->longitude,
                'location_consent'   => $request->location_consent ?? 0,
                'location_consent_at'=> $request->location_consent == 1 ? now() : null,
                // Bank fields
                'title'              => $request->title,
                'type_of_entity'     => $request->type_of_entity,
                'branch_name'        => $request->branch_name,
                'bank_holder_name'   => $request->bank_holder_name,
                'bank_name'          => $request->bank_name,
                'bank_ifsc'          => $request->bank_ifsc,
                'account_type'       => $request->account_type,
                'bank_verified'      => $request->filled('encrypted_bank_account') ? 1 : 0,
                'aadhaar_consent'    => $request->aadhaar_consent ?? 0,
            ];

            // Encrypt sensitive KYC
            if ($request->filled('encrypted_aadhaar')) {
                $profileData['encrypted_aadhaar'] = encrypt($request->encrypted_aadhaar);
                $profileData['aadhaar_verified'] = 1;
                $profileData['aadhaar_verified_at'] = now();
            }
            if ($request->filled('encrypted_pan')) {
                $profileData['encrypted_pan'] = encrypt($request->encrypted_pan);
                $profileData['pan_verified'] = 1;
                $profileData['pan_verified_at'] = now();
            }
            if ($request->filled('encrypted_bank_account')) {
                $profileData['encrypted_bank_account'] = encrypt($request->encrypted_bank_account);
            }

            $businessProfile = BusinessProfile::updateOrCreate(
                ['user_id' => $user->id],
                $profileData
            );

            // ----- Notification Settings -----
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

            // (Optional) Send welcome email
            // Mail::to($user->email)->send(new DistributorWelcomeMail($user, $request->password));

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Distributor account created successfully.',
                'user'    => $user->load('businessProfile'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Admin distributor creation error: ' . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create distributor. ' . $e->getMessage(),
            ], 500);
        }
    }
}