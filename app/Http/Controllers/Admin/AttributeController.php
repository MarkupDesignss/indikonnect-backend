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
    // GET /api/admin/attributes
    public function index()
    {
        $attributes = AttributeMaster::with('values')->get();
        return response()->json(['success' => true, 'data' => $attributes]);
    }

    // POST /api/admin/attributes
    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'attribute_key' => 'required|string|max:100|unique:attribute_masters,attribute_key',
            'values' => 'nullable|array',
            'values.*' => 'string|max:191'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $attribute = AttributeMaster::create([
                'attribute_key' => strtolower(trim($request->attribute_key))
            ]);

            if ($request->has('values')) {
                foreach ($request->values as $value) {
                    AttributeValue::create([
                        'attribute_master_id' => $attribute->id,
                        'value' => trim($value)
                    ]);
                }
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Attribute created',
                'data' => $attribute->load('values')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // GET /api/admin/attributes/{id}
    public function show($id)
    {
        $attribute = AttributeMaster::with('values')->find($id);
        if (!$attribute) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $attribute]);
    }

    // PUT /api/admin/attributes/{id}
    public function update(Request $request, $id)
    {
        $attribute = AttributeMaster::find($id);
        if (!$attribute) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = \Validator::make($request->all(), [
            'attribute_key' => ['required', 'string', 'max:100', Rule::unique('attribute_masters')->ignore($id)]
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $attribute->update(['attribute_key' => strtolower(trim($request->attribute_key))]);
        return response()->json(['success' => true, 'message' => 'Updated', 'data' => $attribute->load('values')]);
    }

    // DELETE /api/admin/attributes/{id}
    public function destroy($id)
    {
        $attribute = AttributeMaster::find($id);
        if (!$attribute) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $attribute->delete();
        return response()->json(['success' => true, 'message' => 'Deleted']);
    }

    // ============ VALUES ============

    // GET /api/admin/attributes/{attributeId}/values
    public function getValues($attributeId)
    {
        $attribute = AttributeMaster::with('values')->find($attributeId);
        if (!$attribute) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $attribute->values]);
    }

    // POST /api/admin/attributes/{attributeId}/values
    public function storeValue(Request $request, $attributeId)
    {
        $attribute = AttributeMaster::find($attributeId);
        if (!$attribute) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = \Validator::make($request->all(), [
            'value' => ['required', 'string', 'max:191', Rule::unique('attribute_values', 'value')->where('attribute_master_id', $attributeId)]
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $value = AttributeValue::create([
            'attribute_master_id' => $attributeId,
            'value' => trim($request->value)
        ]);

        return response()->json(['success' => true, 'message' => 'Value added', 'data' => $value], 201);
    }

    // PUT /api/admin/attributes/{attributeId}/values/{valueId}
    public function updateValue(Request $request, $attributeId, $valueId)
    {
        $value = AttributeValue::where('attribute_master_id', $attributeId)->find($valueId);
        if (!$value) {
            return response()->json(['success' => false, 'message' => 'Value not found'], 404);
        }

        $validator = \Validator::make($request->all(), [
            'value' => ['required', 'string', 'max:191', Rule::unique('attribute_values', 'value')->where('attribute_master_id', $attributeId)->ignore($valueId)]
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $value->update(['value' => trim($request->value)]);
        return response()->json(['success' => true, 'message' => 'Value updated', 'data' => $value]);
    }

    // DELETE /api/admin/attributes/{attributeId}/values/{valueId}
    public function destroyValue($attributeId, $valueId)
    {
        $value = AttributeValue::where('attribute_master_id', $attributeId)->find($valueId);
        if (!$value) {
            return response()->json(['success' => false, 'message' => 'Value not found'], 404);
        }

        $value->delete();
        return response()->json(['success' => true, 'message' => 'Value deleted']);
    }

    // POST /api/admin/attributes/{attributeId}/values/bulk
    public function bulkStoreValues(Request $request, $attributeId)
    {
        $attribute = AttributeMaster::find($attributeId);
        if (!$attribute) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validator = \Validator::make($request->all(), [
            'values' => 'required|array',
            'values.*' => 'required|string|max:191'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $created = [];
        foreach ($request->values as $value) {
            $created[] = AttributeValue::create([
                'attribute_master_id' => $attributeId,
                'value' => trim($value)
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => count($created) . ' values added',
            'data' => $created
        ], 201);
    }

    // GET /api/admin/attributes-dropdown
    public function getForDropdown()
    {
        $attributes = AttributeMaster::all()->map(function($attr) {
            return [
                'id' => $attr->id,
                'key' => $attr->attribute_key,
                'display_name' => ucwords(str_replace('_', ' ', $attr->attribute_key))
            ];
        });

        return response()->json(['success' => true, 'data' => $attributes]);
    }
}