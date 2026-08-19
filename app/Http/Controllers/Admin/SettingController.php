<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    // List all settings (with optional group filter)
    public function index(Request $request)
    {
        $query = Setting::query();
        if ($request->has('group')) {
            $query->where('group', $request->group);
        }
        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    // Create a new setting
    public function store(Request $request)
    {
        $validated = $request->validate([
            'group' => 'required|string|max:50',
            'key' => 'required|string|max:100|unique:settings,key',
            'value' => 'required',
            'data_type' => 'required|in:string,integer,boolean,json,email',
            'description' => 'nullable|string',
            'is_editable' => 'sometimes|boolean',
        ]);

        $setting = Setting::create($validated);
        return response()->json([
            'success' => true,
            'data' => $setting,
            'message' => 'Setting created successfully.'
        ], 201);
    }

    // Update a setting by key
    public function update(Request $request, string $key)
    {
        $setting = Setting::where('key', $key)->firstOrFail();

        if (!$setting->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'This setting is protected and cannot be edited.'
            ], 403);
        }

        $validated = $request->validate([
            'value' => 'required',
            'data_type' => 'sometimes|in:string,integer,boolean,json,email',
            'description' => 'sometimes|string',
            'is_editable' => 'sometimes|boolean',
        ]);

        $setting->update($validated);
        // Cache clears automatically via Model booted() method

        return response()->json([
            'success' => true,
            'data' => $setting,
            'message' => 'Setting updated successfully.'
        ]);
    }

    // Delete a setting (only if editable)
    public function destroy(string $key)
    {
        $setting = Setting::where('key', $key)->firstOrFail();

        if (!$setting->is_editable) {
            return response()->json([
                'success' => false,
                'message' => 'This setting is protected and cannot be deleted.'
            ], 403);
        }

        $setting->delete();
        return response()->json([
            'success' => true,
            'message' => 'Setting deleted successfully.'
        ]);
    }
}