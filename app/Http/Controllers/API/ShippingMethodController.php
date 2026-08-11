<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShippingMethodController extends Controller
{
    /**
     * Get all shipping methods
     */
    public function index(Request $request)
    {
        $query = ShippingMethod::query();

        // Optional filtering
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $shippingMethods = $query->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $shippingMethods,
            'message' => 'Shipping methods retrieved successfully'
        ], Response::HTTP_OK);
    }

    /**
     * Create a new shipping method
     */
    public function store(Request $request)
    {
        // Validation rules
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:shipping_methods,code',
            'description' => 'nullable|string',
            'base_rate' => 'required|numeric|min:0',
            'rate_type' => 'required|in:flat,percentage,free',
            'rate_value' => 'required|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_order_amount' => 'nullable|numeric|min:0|gte:min_order_amount',
            'estimated_days' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ], [
            'name.required' => 'The shipping method name is required.',
            'code.required' => 'The code is required.',
            'code.unique' => 'This code is already in use.',
            'base_rate.required' => 'The base rate is required.',
            'rate_type.required' => 'The rate type is required.',
            'rate_type.in' => 'Invalid rate type. Must be fixed, percentage, per_kg, or per_item.',
            'rate_value.required' => 'The rate value is required.',
            'max_order_amount.gte' => 'The maximum order amount must be greater than or equal to minimum order amount.'
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Get validated data
        $validated = $validator->validated();

        // Set default values if not provided
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $shippingMethod = ShippingMethod::create($validated);

        return response()->json([
            'success' => true,
            'data' => $shippingMethod,
            'message' => 'Shipping method created successfully'
        ], Response::HTTP_CREATED);
    }

    /**
     * Get a single shipping method
     */
    public function show($id)
    {
        $shippingMethod = ShippingMethod::find($id);

        if (!$shippingMethod) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping method not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => $shippingMethod,
            'message' => 'Shipping method retrieved successfully'
        ], Response::HTTP_OK);
    }

    /**
     * Update a shipping method
     */
    public function update(Request $request, $id)
    {
        $shippingMethod = ShippingMethod::find($id);

        if (!$shippingMethod) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping method not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Validation rules with ignore unique for current record
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('shipping_methods', 'code')->ignore($id)
            ],
            'description' => 'nullable|string',
            'base_rate' => 'required|numeric|min:0',
            'rate_type' => 'required|in:flat,percentage,free',
            'rate_value' => 'required|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_order_amount' => 'nullable|numeric|min:0|gte:min_order_amount',
            'estimated_days' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0'
        ], [
            'name.required' => 'The shipping method name is required.',
            'code.required' => 'The code is required.',
            'code.unique' => 'This code is already in use.',
            'base_rate.required' => 'The base rate is required.',
            'rate_type.required' => 'The rate type is required.',
            'rate_type.in' => 'Invalid rate type. Must be fixed, percentage, per_kg, or per_item.',
            'rate_value.required' => 'The rate value is required.',
            'max_order_amount.gte' => 'The maximum order amount must be greater than or equal to minimum order amount.'
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Get validated data
        $validated = $validator->validated();

        // Update the shipping method
        $shippingMethod->update($validated);

        return response()->json([
            'success' => true,
            'data' => $shippingMethod->fresh(),
            'message' => 'Shipping method updated successfully'
        ], Response::HTTP_OK);
    }

    /**
     * Delete a shipping method
     */
    public function destroy($id)
    {
        $shippingMethod = ShippingMethod::find($id);

        if (!$shippingMethod) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping method not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $shippingMethod->delete();

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => 'Shipping method deleted successfully'
        ], Response::HTTP_OK);
    }
}
