<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    public function buybackActivate(Request $request)
    {
        $setting = Setting::where('key', 'buyback_activate')->first();

        return response()->json([
            'success' => true,
            'data' => $setting,
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

        // ============ PAYMENT GATEWAY RULES ============
        if ($setting->group === 'payment_gateway') {

            $newValue       = $validated['value'];
            $dataType       = $validated['data_type'] ?? $setting->data_type;
            $isActivating   = $this->isActivating($newValue, $dataType);
            $currentlyActive = $this->isActivating($setting->value, $setting->data_type);

            // Rule 1: Deactivate karte waqt — last active gateway ko band mat hone do
            if (!$isActivating && $currentlyActive) {
                $otherActiveExists = Setting::where('group', 'payment_gateway')
                    ->where('key', '!=', $key)
                    ->whereIn('value', ['1', 'true', 'on'])
                    ->exists();

                if (!$otherActiveExists) {
                    return response()->json([
                        'success' => false,
                        'message' => "At least one payment gateway must remain active. Activate another gateway first before deactivating '{$key}'.",
                    ], 422);
                }
            }

            // Rule 2: Activate karte waqt — baaki sab gateways ko auto-off kar do
            if ($isActivating) {
                DB::transaction(function () use ($setting, $validated, $key) {
                    Setting::where('group', 'payment_gateway')
                        ->where('key', '!=', $key)
                        ->whereIn('value', ['1', 'true', 'on'])
                        ->each(function (Setting $other) {
                            // data_type ke hisaab se OFF value set karo
                            $offValue = match ($other->data_type) {
                                'boolean' => false,
                                'integer' => 0,
                                default   => '0',
                            };
                            $other->update(['value' => $offValue]);
                        });

                    $setting->update($validated);
                });

                return response()->json([
                    'success' => true,
                    'data'    => $setting->fresh(),
                    'message' => "Setting updated successfully. Only '{$key}' is now active.",
                ]);
            }
            // ==============================================
        }

        $setting->update($validated);

        return response()->json([
            'success' => true,
            'data' => $setting,
            'message' => 'Setting updated successfully.'
        ]);
    }

    /**
     * Check karta hai ki naya value "active" state represent karta hai ya nahi.
     */
    private function isActivating($value, string $dataType = 'boolean'): bool
    {
        if ($dataType === 'boolean') {
            return in_array($value, [true, 1, '1', 'true', 'on'], true);
        }
        return false;
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

    public function paymentGateways()
    {
        $settings = Setting::where('group', 'payment_gateway')
            ->orderBy('key')
            ->get()
            ->map(function ($setting) {
                return [
                    'key'         => $setting->key,
                    'value'       => $setting->value,
                    'data_type'   => $setting->data_type,
                    'description' => $setting->description,
                    'is_editable' => (bool) $setting->is_editable,
                    'is_active'   => $this->isActivating($setting->value, $setting->data_type),
                ];
            });

        $activeGateway = $settings->firstWhere('is_active', true);

        return response()->json([
            'success'         => true,
            'active_gateway'  => $activeGateway['key'] ?? null,
            'data'            => $settings->values(),
        ]);
    }
}
