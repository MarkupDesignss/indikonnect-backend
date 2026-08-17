<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


class AddressController extends Controller
{
    /**
     * Display a listing of the user's addresses.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $addresses = Address::where('user_id', $user->id)
                ->where('status', 'active')
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Addresses retrieved successfully',
                'data' => $addresses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created address.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Determine address type
            $addressType = $request->address_type ?? 'both'; // billing, delivery, both

            $rules = [
                'recipient_name' => 'required|string|max:255',
                'contact_number' => 'required|string|max:20',
                'address_line_1' => 'required|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'required|string|max:255',
                'state' => 'required|string|max:255',
                'postcode' => 'required|string|max:20',
                'country' => 'nullable|string|max:255',
                'is_default' => 'nullable|boolean',
                'is_billing' => 'nullable|boolean',
                'is_delivery' => 'nullable|boolean',
                'address_type' => 'nullable|in:billing,delivery,both',
                'gst_number' => 'nullable|string|max:20',
                'pan_number' => 'nullable|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'company_contact_person' => 'nullable|string|max:255',
                'address_label' => 'nullable|string|max:100',
            ];

            // If both billing and delivery are different, add billing fields
            if ($request->has('is_billing') && !$request->is_billing) {
                $rules = array_merge($rules, [
                    'billing_recipient_name' => 'required|string|max:255',
                    'billing_contact_number' => 'required|string|max:20',
                    'billing_address_line_1' => 'required|string|max:255',
                    'billing_address_line_2' => 'nullable|string|max:255',
                    'billing_city' => 'required|string|max:255',
                    'billing_state' => 'required|string|max:255',
                    'billing_postcode' => 'required|string|max:20',
                    'billing_country' => 'nullable|string|max:255',
                ]);
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // If this is set as default, remove default from other addresses
            if ($request->is_default) {
                Address::where('user_id', $user->id)->update(['is_default' => false]);
            } else {
                // If no default address exists, make this the default
                $defaultExists = Address::where('user_id', $user->id)
                    ->where('is_default', true)
                    ->exists();

                if (!$defaultExists) {
                    $request->merge(['is_default' => true]);
                }
            }

            // Prepare address data
            $addressData = [
                'user_id' => $user->id,
                'recipient_name' => $request->recipient_name,
                'contact_number' => $request->contact_number,
                'address_line_1' => $request->address_line_1,
                'address_line_2' => $request->address_line_2,
                'city' => $request->city,
                'state' => $request->state,
                'postcode' => $request->postcode,
                'country' => $request->country ?? 'India',
                'is_default' => $request->is_default ?? false,
                'status' => 'active'

            ];

            // Handle address types using is_billing and is_delivery
            if ($request->has('is_billing')) {
                $addressData['is_billing'] = $request->is_billing;
            } else {
                $addressData['is_billing'] = $addressType === 'billing' || $addressType === 'both';
            }

            if ($request->has('is_delivery')) {
                $addressData['is_delivery'] = $request->is_delivery;
            } else {
                $addressData['is_delivery'] = $addressType === 'delivery' || $addressType === 'both';
            }

            // If billing address is different from delivery, add billing details
            if (!$addressData['is_billing'] || $request->has('billing_address_line_1')) {
                $addressData['billing_recipient_name'] = $request->billing_recipient_name ?? $request->recipient_name;
                $addressData['billing_contact_number'] = $request->billing_contact_number ?? $request->contact_number;
                $addressData['billing_address_line_1'] = $request->billing_address_line_1 ?? $request->address_line_1;
                $addressData['billing_address_line_2'] = $request->billing_address_line_2;
                $addressData['billing_city'] = $request->billing_city ?? $request->city;
                $addressData['billing_state'] = $request->billing_state ?? $request->state;
                $addressData['billing_postcode'] = $request->billing_postcode ?? $request->postcode;
                $addressData['billing_country'] = $request->billing_country ?? $request->country ?? 'India';
            }

            $address = Address::create($addressData);

            return response()->json([
                'status' => true,
                'message' => 'Address added successfully',
                'data' => $address
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified address.
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $address = Address::where('user_id', $user->id)
                ->where('id', $id)
                ->first();

            if (!$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Address not found'
                ], 404);
            }

            $rules = [
                'recipient_name' => 'sometimes|required|string|max:255',
                'contact_number' => 'sometimes|required|string|max:20',
                'address_line_1' => 'sometimes|required|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'sometimes|required|string|max:255',
                'state' => 'sometimes|required|string|max:255',
                'postcode' => 'sometimes|required|string|max:20',
                'country' => 'nullable|string|max:255',
                'is_default' => 'nullable|boolean',
                'is_billing' => 'nullable|boolean',
                'is_delivery' => 'nullable|boolean',
                'gst_number' => 'nullable|string|max:20',
                'pan_number' => 'nullable|string|max:20',
                'company_name' => 'nullable|string|max:255',
                'company_contact_person' => 'nullable|string|max:255',
                'address_label' => 'nullable|string|max:100',
                'use_billing_as_delivery' => 'nullable|boolean',
            ];

            // If billing is separate, add billing fields
            if ($request->has('is_billing') && !$request->is_billing) {
                $rules = array_merge($rules, [
                    'billing_recipient_name' => 'required|string|max:255',
                    'billing_contact_number' => 'required|string|max:20',
                    'billing_address_line_1' => 'required|string|max:255',
                    'billing_address_line_2' => 'nullable|string|max:255',
                    'billing_city' => 'required|string|max:255',
                    'billing_state' => 'required|string|max:255',
                    'billing_postcode' => 'required|string|max:20',
                    'billing_country' => 'nullable|string|max:255',
                ]);
            }

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // If setting this as default, remove default from other addresses
            if ($request->has('is_default') && $request->is_default) {
                Address::where('user_id', $user->id)
                    ->where('id', '!=', $id)
                    ->update(['is_default' => false]);
            }

            // Prepare update data
            $updateData = $request->only([
                'recipient_name',
                'contact_number',
                'address_line_1',
                'address_line_2',
                'city',
                'state',
                'postcode',
                'country',
                'is_default',
                'is_billing',
                'is_delivery',
                'gst_number',
                'pan_number',
                'company_name',
                'company_contact_person',
                'address_label',
                'status'
            ]);

            // Handle billing fields
            if ($request->has('billing_address_line_1')) {
                $updateData['billing_recipient_name'] = $request->billing_recipient_name;
                $updateData['billing_contact_number'] = $request->billing_contact_number;
                $updateData['billing_address_line_1'] = $request->billing_address_line_1;
                $updateData['billing_address_line_2'] = $request->billing_address_line_2;
                $updateData['billing_city'] = $request->billing_city;
                $updateData['billing_state'] = $request->billing_state;
                $updateData['billing_postcode'] = $request->billing_postcode;
                $updateData['billing_country'] = $request->billing_country ?? 'India';
            }

            // If use_billing_as_delivery is true, copy billing address to delivery
            if ($request->has('use_billing_as_delivery') && $request->use_billing_as_delivery) {
                $updateData['address_line_1'] = $updateData['billing_address_line_1'] ?? $address->billing_address_line_1;
                $updateData['address_line_2'] = $updateData['billing_address_line_2'] ?? $address->billing_address_line_2;
                $updateData['city'] = $updateData['billing_city'] ?? $address->billing_city;
                $updateData['status'] = $updateData['status'] ?? $address->status;
                $updateData['state'] = $updateData['billing_state'] ?? $address->billing_state;
                $updateData['postcode'] = $updateData['billing_postcode'] ?? $address->billing_postcode;
                $updateData['country'] = $updateData['billing_country'] ?? $address->billing_country ?? 'India';
                $updateData['recipient_name'] = $updateData['billing_recipient_name'] ?? $address->billing_recipient_name;
                $updateData['contact_number'] = $updateData['billing_contact_number'] ?? $address->billing_contact_number;
                $updateData['is_delivery'] = true;
                $updateData['is_billing'] = true;
            }

            // Ensure country is set if not provided
            if (empty($updateData['country'])) {
                $updateData['country'] = 'India';
            }

            $address->fill($updateData);
            $address->save();

            // Refresh to get updated data
            $address->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Address updated successfully',
                'data' => $address
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Set an address as default.
     */
    public function setDefault(Request $request, $id)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $address = Address::where('user_id', $user->id)
                ->where('id', $id)
                ->first();

            if (!$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Address not found'
                ], 404);
            }

            // Remove default from all other addresses
            Address::where('user_id', $user->id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);

            // Set this as default
            $address->update(['is_default' => true]);

            return response()->json([
                'status' => true,
                'message' => 'Default address set successfully',
                'data' => $address->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified address.
     */
    // public function destroy($id)
    // {
    //     try {
    //         $user = Auth::user();

    //         if (!$user) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Unauthorized'
    //             ], 401);
    //         }

    //         $address = Address::where('user_id', $user->id)
    //             ->where('id', $id)
    //             ->first();

    //         if (!$address) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Address not found'
    //             ], 404);
    //         }

    //         // Check if this is the only address
    //         $addressCount = Address::where('user_id', $user->id)->count();

    //         if ($addressCount <= 1) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Cannot delete the only address. Please add another address first.'
    //             ], 422);
    //         }

    //         // If deleting the default address, set another as default
    //         if ($address->is_default) {
    //             $newDefault = Address::where('user_id', $user->id)
    //                 ->where('id', '!=', $id)
    //                 ->first();

    //             if ($newDefault) {
    //                 $newDefault->update(['is_default' => true]);
    //             }
    //         }

    //         $address->delete();

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Address deleted successfully'
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function destroy($id)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $address = Address::where('user_id', $user->id)
                ->where('id', $id)
                ->where('status', 'active')
                ->first();

            if (!$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Address not found'
                ], 404);
            }

            // Mark address as inactive instead of deleting
            $address->update([
                'status' => 'inactive'
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Address deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the default address.
     */
    public function getDefault(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $address = Address::where('user_id', $user->id)
                ->where('is_default', true)
                ->first();

            if (!$address) {
                // If no default address, get the first one
                $address = Address::where('user_id', $user->id)->first();
            }

            return response()->json([
                'status' => true,
                'message' => $address ? 'Default address retrieved' : 'No address found',
                'data' => $address
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get billing addresses.
     */
    public function getBillingAddresses(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $addresses = Address::where('user_id', $user->id)
                ->where('is_billing', true)
                ->orderBy('is_default', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Billing addresses retrieved successfully',
                'data' => $addresses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get delivery addresses.
     */
    public function getDeliveryAddresses(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $addresses = Address::where('user_id', $user->id)
                ->where('is_delivery', true)
                ->orderBy('is_default', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Delivery addresses retrieved successfully',
                'data' => $addresses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}