<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttributeMaster;
use App\Models\AttributeValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttributeController extends Controller
{
    /**
     * GET /api/admin/attributes
     * Display a listing of attributes.
     */
    public function index()
    {
        $attributes = AttributeMaster::with('values')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attributes
        ]);
    }

    /**
     * POST /api/admin/attributes
     * Store a newly created attribute.
     */
    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'attribute_key' => 'required|string|max:100|unique:attribute_masters,attribute_key',
            'values' => 'nullable|array',
            'values.*' => 'string|max:191'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create attribute
            $attribute = AttributeMaster::create([
                'attribute_key' => strtolower(trim($request->attribute_key)),
            ]);

            // Add values if provided
            if ($request->has('values') && !empty($request->values)) {
                foreach ($request->values as $value) {
                    AttributeValue::create([
                        'attribute_master_id' => $attribute->id,
                        'value' => trim($value),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attribute created successfully',
                'data' => $attribute->load('values')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create attribute',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/admin/attributes/{id}
     * Display the specified attribute.
     */
    public function show($id)
    {
        $attribute = AttributeMaster::with('values')->find($id);

        if (!$attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $attribute
        ]);
    }

    /**
     * PUT/PATCH /api/admin/attributes/{id}
     * Update the specified attribute.
     */
    public function update(Request $request, $id)
    {
        $attribute = AttributeMaster::find($id);

        if (!$attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found'
            ], 404);
        }

        $validator = \Validator::make($request->all(), [
            'attribute_key' => [
                'required',
                'string',
                'max:100',
                Rule::unique('attribute_masters', 'attribute_key')->ignore($id)
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $attribute->update([
                'attribute_key' => strtolower(trim($request->attribute_key))
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attribute updated successfully',
                'data' => $attribute->load('values')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update attribute',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/attributes/{id}
     * Remove the specified attribute.
     */
    public function destroy($id)
    {
        $attribute = AttributeMaster::find($id);

        if (!$attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found'
            ], 404);
        }

        try {
            $attribute->delete();

            return response()->json([
                'success' => true,
                'message' => 'Attribute deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attribute',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== VALUE MANAGEMENT ====================

    /**
     * GET /api/admin/attributes/{attributeId}/values
     * Get all values for an attribute.
     */
    public function getValues($attributeId)
    {
        $attribute = AttributeMaster::with('values')->find($attributeId);

        if (!$attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $attribute->values
        ]);
    }

    /**
     * POST /api/admin/attributes/{attributeId}/values
     * Store a new value for an attribute.
     */
    public function storeValue(Request $request, $attributeId)
    {
        $attribute = AttributeMaster::find($attributeId);

        if (!$attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found'
            ], 404);
        }

        $validator = \Validator::make($request->all(), [
            'value' => [
                'required',
                'string',
                'max:191',
                Rule::unique('attribute_values', 'value')
                    ->where('attribute_master_id', $attributeId)
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $value = AttributeValue::create([
                'attribute_master_id' => $attributeId,
                'value' => trim($request->value),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Value added successfully',
                'data' => $value
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add value',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/admin/attributes/{attributeId}/values/{valueId}
     * Update a value.
     */
    public function updateValue(Request $request, $attributeId, $valueId)
    {
        $value = AttributeValue::where('attribute_master_id', $attributeId)
            ->find($valueId);

        if (!$value) {
            return response()->json([
                'success' => false,
                'message' => 'Value not found'
            ], 404);
        }

        $validator = \Validator::make($request->all(), [
            'value' => [
                'required',
                'string',
                'max:191',
                Rule::unique('attribute_values', 'value')
                    ->where('attribute_master_id', $attributeId)
                    ->ignore($valueId)
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $value->update([
                'value' => trim($request->value)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Value updated successfully',
                'data' => $value
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update value',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/attributes/{attributeId}/values/{valueId}
     * Delete a value.
     */
    public function destroyValue($attributeId, $valueId)
    {
        $value = AttributeValue::where('attribute_master_id', $attributeId)
            ->find($valueId);

        if (!$value) {
            return response()->json([
                'success' => false,
                'message' => 'Value not found'
            ], 404);
        }

        try {
            $value->delete();

            return response()->json([
                'success' => true,
                'message' => 'Value deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete value',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/admin/attributes/{attributeId}/values/bulk
     * Bulk add values to an attribute.
     */
    public function bulkStoreValues(Request $request, $attributeId)
    {
        $attribute = AttributeMaster::find($attributeId);

        if (!$attribute) {
            return response()->json([
                'success' => false,
                'message' => 'Attribute not found'
            ], 404);
        }

        $validator = \Validator::make($request->all(), [
            'values' => 'required|array',
            'values.*' => 'required|string|max:191'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $created = [];
            foreach ($request->values as $value) {
                $created[] = AttributeValue::create([
                    'attribute_master_id' => $attributeId,
                    'value' => trim($value),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($created) . ' values added successfully',
                'data' => $created
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add values',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/admin/attributes-dropdown
     * Get all attributes for dropdown (helper).
     */
    public function getForDropdown()
    {
        $attributes = AttributeMaster::orderBy('id')->get()
            ->map(function($attr) {
                return [
                    'id' => $attr->id,
                    'key' => $attr->attribute_key,
                    'display_name' => ucwords(str_replace('_', ' ', $attr->attribute_key)),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $attributes
        ]);
    }
}