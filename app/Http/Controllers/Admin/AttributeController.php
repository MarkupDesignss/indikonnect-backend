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
     * Display a listing of attributes with their values.
     */
    public function index()
    {
        $attributes = AttributeMaster::with('values')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attributes
        ]);
    }

    /**
     * Show the form for creating a new attribute.
     */
    public function create()
    {
        // Return view for creating attribute
        // return view('admin.attributes.create');
    }

    /**
     * Store a newly created attribute.
     */
    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'attribute_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z_]+$/',
                Rule::unique('attribute_masters', 'attribute_key')
            ],
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'values' => 'nullable|array',
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
            // Create attribute
            $attribute = AttributeMaster::create([
                'attribute_key' => strtolower(trim($request->attribute_key)),
                'is_required' => $request->is_required ?? false,
                'sort_order' => $request->sort_order ?? 0,
            ]);

            // Add values if provided
            if ($request->has('values') && !empty($request->values)) {
                foreach ($request->values as $value) {
                    AttributeValue::create([
                        'attribute_master_id' => $attribute->id,
                        'value' => trim($value),
                        'sort_order' => 0,
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
     * Show the form for editing the specified attribute.
     */
    public function edit($id)
    {
        // Return view for editing attribute
        // $attribute = AttributeMaster::with('values')->find($id);
        // return view('admin.attributes.edit', compact('attribute'));
    }

    /**
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
                'regex:/^[a-z_]+$/',
                Rule::unique('attribute_masters', 'attribute_key')->ignore($id)
            ],
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $attribute->update([
                'attribute_key' => strtolower(trim($request->attribute_key)),
                'is_required' => $request->is_required ?? $attribute->is_required,
                'sort_order' => $request->sort_order ?? $attribute->sort_order,
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
            // This will cascade delete values due to foreign key constraint
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
            ],
            'sort_order' => 'nullable|integer|min:0',
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
                'sort_order' => $request->sort_order ?? 0,
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
            ],
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $value->update([
                'value' => trim($request->value),
                'sort_order' => $request->sort_order ?? $value->sort_order,
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

    // ==================== HELPERS ====================

    /**
     * Get all attributes for dropdown.
     */
    public function getForDropdown()
    {
        $attributes = AttributeMaster::orderBy('sort_order')
            ->get()
            ->map(function($attr) {
                return [
                    'id' => $attr->id,
                    'key' => $attr->attribute_key,
                    'display_name' => $attr->display_name,
                    'is_required' => $attr->is_required,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $attributes
        ]);
    }

    /**
     * Get values for a specific attribute.
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
            'data' => [
                'attribute' => $attribute,
                'values' => $attribute->values,
            ]
        ]);
    }

    /**
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
                    'sort_order' => 0,
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
}