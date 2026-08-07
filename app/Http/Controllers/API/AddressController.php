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

            $validator = Validator::make($request->all(), [
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
            ]);

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

            $address = Address::create([
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
                'is_billing' => $request->is_billing ?? true,
                'is_delivery' => $request->is_delivery ?? true,
            ]);

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

            $validator = Validator::make($request->all(), [
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
            ]);

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

            // Update only provided fields
            $address->fill($request->only([
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
                'is_delivery'
            ]));

            // Ensure country is set if not provided
            if ($request->has('country') && empty($request->country)) {
                $address->country = 'India';
            }

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
                ->first();

            if (!$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Address not found'
                ], 404);
            }

            // Check if this is the only address
            $addressCount = Address::where('user_id', $user->id)->count();

            if ($addressCount <= 1) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete the only address. Please add another address first.'
                ], 422);
            }

            // If deleting the default address, set another as default
            if ($address->is_default) {
                $newDefault = Address::where('user_id', $user->id)
                    ->where('id', '!=', $id)
                    ->first();

                if ($newDefault) {
                    $newDefault->update(['is_default' => true]);
                }
            }

            $address->delete();

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