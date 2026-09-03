<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    /**
     * Display a listing of brands.
     */
    public function index(Request $request)
    {
        try {
            $brands = Brand::orderBy('created_at', 'desc')->get();

            $data = $brands->map(function ($brand) {
                return [
                    'id' => $brand->id,
                    'title' => $brand->title,
                    'discount_percentage' => $brand->discount_percentage,
                    'logo' => $brand->logo_url,
                    'banner' => $brand->banner_url,
                    'created_at' => $brand->created_at?->toISOString(),
                    'updated_at' => $brand->updated_at?->toISOString(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Brands retrieved successfully',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve brands: ' . $e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * Store a newly created brand.
     */
    public function store(Request $request)
    {
        try {
            // Validation
            $validated = $request->validate([
                'title' => 'required|string|max:255|unique:brands,title',
                'discount_percentage' => 'required|integer|min:0|max:100',
                'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:5120',
                'banner' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
            ], [
                'title.required' => 'Brand heading is required',
                'title.unique' => 'This brand already exists',
                'discount_percentage.required' => 'Discount percentage is required',
                'discount_percentage.min' => 'Discount percentage must be at least 0',
                'discount_percentage.max' => 'Discount percentage cannot exceed 100',
                'logo.max' => 'Logo must not be larger than 5MB',
                'banner.max' => 'Banner must not be larger than 10MB',
            ]);

            // Handle logo upload
            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $this->uploadFile($request->file('logo'), 'brands/logos');
            }

            // Handle banner upload
            $bannerPath = null;
            if ($request->hasFile('banner')) {
                $bannerPath = $this->uploadFile($request->file('banner'), 'brands/banners');
            }

            // Create brand
            $brand = Brand::create([
                'title' => $validated['title'],
                'discount_percentage' => $validated['discount_percentage'],
                'logo' => $logoPath,
                'banner' => $bannerPath,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Brand created successfully',
                'data' => [
                    'id' => $brand->id,
                    'title' => $brand->title,
                    'discount_percentage' => $brand->discount_percentage,
                    'logo' => $brand->logo_url,
                    'banner' => $brand->banner_url,
                    'created_at' => $brand->created_at?->toISOString(),
                    'updated_at' => $brand->updated_at?->toISOString(),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
                'data' => null,
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create brand: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * Display the specified brand.
     */
    public function show($id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Brand not found',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Brand retrieved successfully',
                'data' => [
                    'id' => $brand->id,
                    'title' => $brand->title,
                    'discount_percentage' => $brand->discount_percentage,
                    'logo' => $brand->logo_url,
                    'banner' => $brand->banner_url,
                    'created_at' => $brand->created_at?->toISOString(),
                    'updated_at' => $brand->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve brand: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * Update the specified brand.
     */
    public function update(Request $request, $id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Brand not found',
                    'data' => null,
                ], 404);
            }

            // Validation
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255', Rule::unique('brands', 'title')->ignore($id)],
                'discount_percentage' => 'required|integer|min:0|max:100',
                'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:5120',
                'banner' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
            ], [
                'title.required' => 'Brand heading is required',
                'title.unique' => 'This brand already exists',
                'discount_percentage.required' => 'Discount percentage is required',
                'discount_percentage.min' => 'Discount percentage must be at least 0',
                'discount_percentage.max' => 'Discount percentage cannot exceed 100',
                'logo.max' => 'Logo must not be larger than 5MB',
                'banner.max' => 'Banner must not be larger than 10MB',
            ]);

            // Prepare update data
            $updateData = [
                'title' => $validated['title'],
                'discount_percentage' => $validated['discount_percentage'],
            ];

            // Handle logo upload
            if ($request->hasFile('logo')) {
                // Delete old logo
                if ($brand->logo) {
                    Storage::disk('public')->delete($brand->logo);
                }
                $updateData['logo'] = $this->uploadFile($request->file('logo'), 'brands/logos');
            }

            // Handle banner upload
            if ($request->hasFile('banner')) {
                // Delete old banner
                if ($brand->banner) {
                    Storage::disk('public')->delete($brand->banner);
                }
                $updateData['banner'] = $this->uploadFile($request->file('banner'), 'brands/banners');
            }

            // Update brand
            $brand->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Brand updated successfully',
                'data' => [
                    'id' => $brand->id,
                    'title' => $brand->title,
                    'discount_percentage' => $brand->discount_percentage,
                    'logo' => $brand->logo_url,
                    'banner' => $brand->banner_url,
                    'created_at' => $brand->created_at?->toISOString(),
                    'updated_at' => $brand->updated_at?->toISOString(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
                'data' => null,
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update brand: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * Remove the specified brand.
     */
    public function destroy($id)
    {
        try {
            $brand = Brand::find($id);

            if (!$brand) {
                return response()->json([
                    'success' => false,
                    'message' => 'Brand not found',
                    'data' => null,
                ], 404);
            }

            // Delete logo and banner files
            if ($brand->logo) {
                Storage::disk('public')->delete($brand->logo);
            }
            if ($brand->banner) {
                Storage::disk('public')->delete($brand->banner);
            }

            $brand->delete();

            return response()->json([
                'success' => true,
                'message' => 'Brand deleted successfully',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete brand: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    /**
     * Helper method to upload file.
     */
    private function uploadFile($file, $path)
    {
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        return $file->storeAs($path, $filename, 'public');
    }
}
