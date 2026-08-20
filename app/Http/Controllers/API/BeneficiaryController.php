<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BeneficiaryController extends Controller
{
    /**
     * List all beneficiaries for the authenticated distributor.
     */
    public function index()
    {
        $user = Auth::user();

        $beneficiaries = Beneficiary::where('user_id', $user->id)
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $beneficiaries,
        ]);
    }

    /**
     * Store a new beneficiary.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:191',
            'relationship' => 'required|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:191',
            'share_percentage' => 'required|numeric|min:0.01|max:100',
            'is_primary' => 'sometimes|boolean',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Ensure total share doesn't exceed 100%
        $currentTotal = Beneficiary::where('user_id', $user->id)->sum('share_percentage');
        $newTotal = $currentTotal + $data['share_percentage'];

        if ($newTotal > 100) {
            return response()->json([
                'success' => false,
                'message' => "Total share percentage cannot exceed 100%. Current total: {$currentTotal}%, Adding: {$data['share_percentage']}%",
            ], 422);
        }

        // If setting as primary, remove primary from others
        $isPrimary = $data['is_primary'] ?? false;
        if ($isPrimary) {
            Beneficiary::where('user_id', $user->id)->update(['is_primary' => false]);
        }

        // If no primary exists and this is the first, make it primary
        if (!$isPrimary) {
            $primaryExists = Beneficiary::where('user_id', $user->id)->where('is_primary', true)->exists();
            if (!$primaryExists) {
                $data['is_primary'] = true;
                $isPrimary = true;
            }
        }

        $beneficiary = Beneficiary::create([
            'user_id' => $user->id,
            'full_name' => $data['full_name'],
            'relationship' => $data['relationship'],
            'contact_number' => $data['contact_number'] ?? null,
            'email' => $data['email'] ?? null,
            'share_percentage' => $data['share_percentage'],
            'is_primary' => $data['is_primary'] ?? false,
            'address' => $data['address'] ?? null,
            'confirmed_at' => null, // Requires OTP confirmation
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Beneficiary added successfully. Please confirm via OTP to activate.',
            'data' => $beneficiary,
            'requires_confirmation' => true,
        ], 201);
    }

    /**
     * Update an existing beneficiary.
     */
    public function update(Request $request, int $id)
    {
        $user = Auth::user();
        $beneficiary = Beneficiary::where('user_id', $user->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'full_name' => 'sometimes|string|max:191',
            'relationship' => 'sometimes|string|max:100',
            'contact_number' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:191',
            'share_percentage' => 'sometimes|numeric|min:0.01|max:100',
            'is_primary' => 'sometimes|boolean',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Validate total share (excluding current record)
        if (isset($data['share_percentage'])) {
            $currentTotal = Beneficiary::where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->sum('share_percentage');
            $newTotal = $currentTotal + $data['share_percentage'];

            if ($newTotal > 100) {
                return response()->json([
                    'success' => false,
                    'message' => "Total share percentage cannot exceed 100%. Current total (excluding this): {$currentTotal}%, New share: {$data['share_percentage']}%",
                ], 422);
            }
        }

        // If setting as primary, remove primary from others
        if (isset($data['is_primary']) && $data['is_primary'] === true) {
            Beneficiary::where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->update(['is_primary' => false]);
        }

        // If user tries to set primary = false, but no other primary exists, prevent it
        if (isset($data['is_primary']) && $data['is_primary'] === false) {
            $otherPrimaryExists = Beneficiary::where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->where('is_primary', true)
                ->exists();
            if (!$otherPrimaryExists && $beneficiary->is_primary) {
                return response()->json([
                    'success' => false,
                    'message' => 'At least one beneficiary must be marked as primary. Please set another beneficiary as primary first.',
                ], 422);
            }
        }

        // Update
        $beneficiary->update($data);

        // Reset confirmation status if sensitive fields changed
        if ($beneficiary->wasChanged(['full_name', 'contact_number', 'email', 'share_percentage'])) {
            $beneficiary->update(['confirmed_at' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Beneficiary updated successfully. Please confirm via OTP if sensitive fields were changed.',
            'data' => $beneficiary,
            'requires_confirmation' => is_null($beneficiary->confirmed_at),
        ]);
    }

    /**
     * Delete a beneficiary.
     */
    public function destroy(int $id)
    {
        $user = Auth::user();
        $beneficiary = Beneficiary::where('user_id', $user->id)->findOrFail($id);

        // Prevent deletion if it's the only primary
        if ($beneficiary->is_primary) {
            $count = Beneficiary::where('user_id', $user->id)->count();
            if ($count <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete the only primary beneficiary. Please add another beneficiary first.',
                ], 422);
            }
        }

        $beneficiary->delete();

        return response()->json([
            'success' => true,
            'message' => 'Beneficiary deleted successfully.',
        ]);
    }

    /**
     * Confirm beneficiary via OTP.
     * (Step 1: Request OTP, Step 2: Verify OTP and set confirmed_at)
     */
    public function confirm(Request $request, int $id)
    {
        $user = Auth::user();
        $beneficiary = Beneficiary::where('user_id', $user->id)->findOrFail($id);

        // In a real scenario, you'd validate an OTP here.
        // For now, we'll just set confirmed_at if OTP is valid.
        // Assuming the OTP is sent and verified elsewhere, we just mark it.
        $beneficiary->update([
            'confirmed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Beneficiary confirmed successfully.',
            'data' => $beneficiary,
        ]);
    }

    /**
     * Get total share distribution.
     */
    public function summary()
    {
        $user = Auth::user();
        $beneficiaries = Beneficiary::where('user_id', $user->id)->get();

        $total = $beneficiaries->sum('share_percentage');
        $primary = $beneficiaries->firstWhere('is_primary', true);

        return response()->json([
            'success' => true,
            'data' => [
                'total_share' => round($total, 2),
                'remaining_share' => round(100 - $total, 2),
                'primary_beneficiary' => $primary,
                'beneficiaries' => $beneficiaries,
            ],
        ]);
    }
}