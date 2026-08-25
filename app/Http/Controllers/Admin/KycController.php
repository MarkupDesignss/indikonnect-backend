<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\DistributorProfile;
use App\Models\Admin;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KycController extends Controller
{
    /**
     * List pending applications.
     * GET /api/admin/kyc/applications
     */
    public function pendingApplications(Request $request)
    {
        // Admin must have KYC permission (you can add middleware check)
        // For now, assume authenticated admin

        $applications = DistributorProfile::with('user')
            ->whereIn('application_status', ['submitted', 'under_review'])
            ->orderBy('submitted_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $applications->map(function ($profile) {
                return [
                    'id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'name' => $profile->user->full_name ?? 'N/A',
                    'email' => $profile->user->email ?? 'N/A',
                    'phone' => $profile->user->phone ?? 'N/A',
                    'application_status' => $profile->application_status,
                    'kyc_status' => $profile->kyc_status,
                    'submitted_at' => $profile->submitted_at?->toDateTimeString(),
                    'aadhaar_verified' => (bool) $profile->aadhaar_verified,
                    'pan_verified' => (bool) $profile->pan_verified,
                    'bank_verified' => (bool) $profile->bank_verified,
                ];
            }),
            'total' => $applications->count(),
        ]);
    }

    /**
     * Approve application.
     * POST /api/admin/kyc/applications/{userId}/approve
     */
    public function approve($userId, Request $request)
    {
        $user = User::findOrFail($userId);
        $profile = DistributorProfile::where('user_id', $user->id)->first();

        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'Distributor profile not found.'], 404);
        }

        // Only submitted/under_review can be approved
        if (!in_array($profile->application_status, ['submitted', 'under_review'])) {
            return response()->json([
                'success' => false,
                'message' => 'This application cannot be approved. Current status: ' . $profile->application_status
            ], 422);
        }

        // Update profile
        $profile->application_status = 'approved';
        $profile->kyc_status = 'verified';
        $profile->reviewed_at = now();
        $profile->reviewed_by = Auth::id();
        $profile->rejection_reason = null; // clear previous rejection reason if any
        $profile->save();

        // Activate distributor account
        $user->distributor_status = 'active';
        $user->activation_date = now();
        $user->save();

        // Create admin notification
        $this->createAdminNotification('KYC Approved', "Application for {$user->full_name} has been approved.", $user->id);

        // Fire notification to user (you can implement later)
        // event(new DistributorApplicationApproved($user));

        return response()->json([
            'success' => true,
            'message' => 'Application approved. Distributor account activated.',
            'user_id' => $user->id,
            'new_status' => 'approved'
        ]);
    }

    /**
     * Reject application.
     * POST /api/admin/kyc/applications/{userId}/reject
     */
    public function reject($userId, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $user = User::findOrFail($userId);
        $profile = DistributorProfile::where('user_id', $user->id)->first();

        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'Distributor profile not found.'], 404);
        }

        if (!in_array($profile->application_status, ['submitted', 'under_review'])) {
            return response()->json([
                'success' => false,
                'message' => 'This application cannot be rejected. Current status: ' . $profile->application_status
            ], 422);
        }

        $profile->application_status = 'rejected';
        $profile->kyc_status = 'rejected';
        $profile->reviewed_at = now();
        $profile->reviewed_by = Auth::id();
        $profile->rejection_reason = $request->reason;
        $profile->save();

        // Optionally close the account or keep it pending
        // $user->distributor_status = 'closed';

        $this->createAdminNotification('KYC Rejected', "Application for {$user->full_name} has been rejected. Reason: {$request->reason}", $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Application rejected.',
            'user_id' => $user->id,
            'new_status' => 'rejected',
            'reason' => $request->reason
        ]);
    }

    /**
     * Return application for correction.
     * POST /api/admin/kyc/applications/{userId}/return
     */
    public function returnForCorrection($userId, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $user = User::findOrFail($userId);
        $profile = DistributorProfile::where('user_id', $user->id)->first();

        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'Distributor profile not found.'], 404);
        }

        if (!in_array($profile->application_status, ['submitted', 'under_review'])) {
            return response()->json([
                'success' => false,
                'message' => 'This application cannot be returned. Current status: ' . $profile->application_status
            ], 422);
        }

        $profile->application_status = 'returned';
        // kyc_status remains 'pending'
        $profile->reviewed_at = now();
        $profile->reviewed_by = Auth::id();
        $profile->rejection_reason = $request->reason; // This is the correction reason
        $profile->save();

        $this->createAdminNotification('KYC Returned', "Application for {$user->full_name} has been returned for correction. Reason: {$request->reason}", $user->id);

        // Fire notification to user
        // event(new DistributorApplicationReturned($user, $request->reason));

        return response()->json([
            'success' => true,
            'message' => 'Application returned for correction.',
            'user_id' => $user->id,
            'new_status' => 'returned',
            'reason' => $request->reason
        ]);
    }

    /**
     * Get a specific application details (for admin view)
     */
    public function show($userId)
    {
        $user = User::with('distributorProfile')->findOrFail($userId);
        $profile = $user->distributorProfile;

        if (!$profile) {
            return response()->json(['success' => false, 'message' => 'Profile not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'distributor_status' => $user->distributor_status,
                ],
                'profile' => [
                    'id' => $profile->id,
                    'application_status' => $profile->application_status,
                    'kyc_status' => $profile->kyc_status,
                    'submitted_at' => $profile->submitted_at?->toDateTimeString(),
                    'reviewed_at' => $profile->reviewed_at?->toDateTimeString(),
                    'reviewed_by' => $profile->reviewed_by ? Admin::find($profile->reviewed_by)?->name : null,
                    'rejection_reason' => $profile->rejection_reason,
                    'aadhaar_verified' => $profile->aadhaar_verified,
                    'pan_verified' => $profile->pan_verified,
                    'bank_verified' => $profile->bank_verified,
                    // Mask sensitive data
                    'aadhaar_last4' => $profile->aadhaar_last4 ?? null,
                    'pan_last4' => $profile->pan_last4 ?? null,
                    'account_last4' => $profile->account_last4 ?? null,
                ]
            ]
        ]);
    }

    // ========== Helper ==========

    private function createAdminNotification($title, $message, $userId)
    {
        AdminNotification::create([
            'admin_id' => Auth::id(),
            'type' => 'kyc_review',
            'title' => $title,
            'message' => $message,
            'reference_type' => 'user',
            'reference_id' => $userId,
            'priority' => 'high',
            'extra_data' => json_encode([
                'user_id' => $userId,
                'action_by' => Auth::id(),
                'action_at' => now()->toDateTimeString(),
            ]),
        ]);
    }
}