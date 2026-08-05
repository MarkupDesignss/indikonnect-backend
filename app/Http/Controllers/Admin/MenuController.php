<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Menu;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    public function index()
    {
        try {
            $logo = Menu::where('type', 'logo')->first();

            $menus = Menu::where('type', 'menu')
                ->where('status', 1)
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'logo' => $logo ? [
                        'id' => $logo->id,
                        'type' => $logo->type,
                        'logo' => $logo->logo,
                        'favicon' => $logo->favicon,
                    ] : null,

                    'menus' => $menus->map(function ($menu) {
                        return [
                            'id' => $menu->id,
                            'title' => $menu->title,
                            'slug' => $menu->slug,
                            'sort_order' => $menu->sort_order,
                            'status' => (bool) $menu->status,
                        ];
                    }),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch header data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a new menu item
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'favicon' => 'nullable|image|mimes:ico,png|max:1024',
                'title' => 'required|string|max:255',
                'type' => 'required|string|in:logo,menu',
                'status' => 'nullable|boolean',
                'sort_order' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = []; // Initialize data array

            // Handle logo upload (only for logo type)
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                $oldMenu = Menu::where('type', 'logo')->first();
                if ($oldMenu && $oldMenu->logo) {
                    Storage::disk('public')->delete($oldMenu->logo);
                }

                $data['logo'] = $request->file('logo')->store('logos', 'public');
            }

            // Handle favicon upload (only for logo type)
            if ($request->hasFile('favicon')) {
                // Delete old favicon if exists
                $oldMenu = Menu::where('type', 'logo')->first();
                if ($oldMenu && $oldMenu->favicon) {
                    Storage::disk('public')->delete($oldMenu->favicon);
                }

                $data['favicon'] = $request->file('favicon')->store('favicons', 'public');
            }

            // Generate unique slug
            $title = strtolower(trim($request->title));
            $slug = Str::slug($title);
            $originalSlug = $slug;
            $count = 1;

            // Check for unique slug including soft deleted records if using soft deletes
            while (Menu::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $count++;
            }

            // Get the highest sort_order and add 1
            $maxSortOrder = Menu::where('type', $request->type)->max('sort_order');
            $sortOrder = $request->has('sort_order') ? (int) $request->sort_order : ($maxSortOrder + 1);

            // Create new menu
            $menu = Menu::create([
                'type' => $request->type,
                'slug' => $slug,
                'title' => Str::ucfirst(strtolower($request->title)),
                'sort_order' => $sortOrder,
                'status' => $request->boolean('status', false) ? 1 : 0,
                'logo' => $data['logo'] ?? null,
                'favicon' => $data['favicon'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Menu added successfully',
                'data' => [
                    'id' => $menu->id,
                    'type' => $menu->type,
                    'slug' => $menu->slug,
                    'title' => $menu->title,
                    'logo' => $menu->logo ? Storage::url($menu->logo) : null,
                    'favicon' => $menu->favicon ? Storage::url($menu->favicon) : null,
                    'sort_order' => $menu->sort_order,
                    'status' => (bool) $menu->status,
                ]
            ], 201);
        } catch (\Exception $e) {
            // Rollback: Delete uploaded files if creation fails
            if (isset($data['logo'])) {
                Storage::disk('public')->delete($data['logo']);
            }
            if (isset($data['favicon'])) {
                Storage::disk('public')->delete($data['favicon']);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to add menu',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Find the menu
            $menu = Menu::find($id);

            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu not found'
                ], 404);
            }

            // Validation
            $validator = Validator::make($request->all(), [
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
                'favicon' => 'nullable|image|mimes:ico,png|max:1024',
                'title' => 'nullable|string|max:255',
                'status' => 'nullable|boolean',
                'sort_order' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = [];

            // Logo Upload (only for logo type menu)
            if ($request->hasFile('logo') && $menu->type === 'logo') {
                // Delete old logo if exists
                if ($menu->logo) {
                    Storage::disk('public')->delete($menu->logo);
                }

                $data['logo'] = $request->file('logo')->store('logos', 'public');
            }

            // Favicon Upload (only for logo type menu)
            if ($request->hasFile('favicon') && $menu->type === 'logo') {
                // Delete old favicon if exists
                if ($menu->favicon) {
                    Storage::disk('public')->delete($menu->favicon);
                }

                $data['favicon'] = $request->file('favicon')->store('favicons', 'public');
            }

            // Update title if provided
            if ($request->has('title')) {
                $data['title'] = $request->title;
            }

            // Update status if provided
            if ($request->has('status')) {
                $data['status'] = $request->boolean('status') ? 1 : 0;
            }

            // Update sort_order if provided
            if ($request->has('sort_order')) {
                $data['sort_order'] = (int) $request->sort_order;
            }

            // Update the menu
            $menu->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Menu updated successfully',
                'data' => [
                    'id' => $menu->id,
                    'type' => $menu->type,
                    'title' => $menu->title,
                    'logo' => $menu->logo ? Storage::url($menu->logo) : null,
                    'favicon' => $menu->favicon ? Storage::url($menu->favicon) : null,
                    'sort_order' => $menu->sort_order,
                    'status' => (bool) $menu->status,
                ]
            ], 200);
        } catch (\Exception $e) {
            // Rollback: Delete uploaded files if update fails
            if (isset($data['logo'])) {
                Storage::disk('public')->delete($data['logo']);
            }
            if (isset($data['favicon'])) {
                Storage::disk('public')->delete($data['favicon']);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update menu',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $menu = Menu::where('type', 'menu')->find($id);

            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu not found'
                ], 404);
            }

            $menu->delete();

            return response()->json([
                'success' => true,
                'message' => 'Menu deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete menu',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $menu = Menu::where('type', 'menu')->find($id);

            if (!$menu) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu not found'
                ], 404);
            }

            $menu->status = $request->status ? 1 : 0;
            $menu->save();

            return response()->json([
                'success' => true,
                'message' => 'Menu status updated successfully',
                'data' => [
                    'id' => $menu->id,
                    'title' => $menu->title,
                    'status' => (bool) $menu->status,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update menu status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
