<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Footer;
use App\Models\HeritageSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class FooterController extends Controller
{
    /**
     * GET /api/footer - Get footer data
     */
    public function index(Request $request)
    {
        try {
            // Initialize query for Heritage Sites
            $query = HeritageSite::query();

            // Filter by state if provided
            if ($request->has('state') && $request->state) {
                $query->byState($request->state);
            }

            // Filter by category if provided
            if ($request->has('category') && $request->category) {
                $query->byCategory($request->category);
            }

            // Search by title if provided
            if ($request->has('search') && $request->search) {
                $query->where('title', 'LIKE', '%' . $request->search . '%');
            }

            $sites = $query->orderBy('id', 'asc')->get();

            // Transform the collection to include full image URL
            $sites->transform(function ($site) {
                return [
                    'id' => $site->id,
                    'title' => $site->title,
                    'subtitle' => $site->subtitle,
                    'location' => $site->location,
                    'state' => $site->state,
                    'description' => $site->description,
                    'image_url' => $site->image_url,
                    'category' => $site->category,
                    'full_location' => $site->full_location,
                    'created_at' => $site->created_at,
                    'updated_at' => $site->updated_at,
                ];
            });

            // Get Footer data
            $footer = Footer::first();

            // Add full URL for logo if exists
            if ($footer && $footer->logo) {
                $footer->logo_url = asset('storage/' . $footer->logo);
            }

            // Prepare response data
            $responseData = [
                'heritage_sites' => [
                    'data' => $sites,
                    'count' => $sites->count()
                ]
            ];

            // Add footer data if it exists
            if ($footer) {
                $responseData['footer'] = $footer;
            }

            return response()->json([
                'success' => true,
                'data' => $responseData,
                'message' => 'Heritage sites and footer data retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/footer - Create new footer data with logo image
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048', // Max 2MB
                'title' => 'nullable|string|max:255',
                'instagram' => 'nullable|url',
                'facebook' => 'nullable|url',
                'linkedin' => 'nullable|url',
                'twitter' => 'nullable|url',
                'youtube' => 'nullable|url',
                'email' => 'nullable|email',
                'phone' => 'nullable|string|max:255',
                'quote1' => 'nullable|string',
                'quote2' => 'nullable|string',
                'quote3' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'copyright' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if footer already exists (only one record allowed)
            $existing = Footer::first();
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Footer data already exists. Use PUT to update.'
                ], 409);
            }

            // Handle logo upload
            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('footer-logos', 'public');
            }

            // Create footer with logo path
            $footerData = $request->except('logo');
            $footerData['logo'] = $logoPath;

            $footer = Footer::create($footerData);

            // Add full URL for logo in response
            $footer->logo_url = asset('storage/' . $footer->logo);

            return response()->json([
                'success' => true,
                'message' => 'Footer data created successfully',
                'data' => $footer
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create footer data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/footer - Update footer data with logo image
     */
    public function update(Request $request)
    {
        try {
            $footer = Footer::first();

            if (!$footer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Footer data not found. Please create it first using POST.'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'logo' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048', // Max 2MB
                'title' => 'sometimes|string|max:255',
                'instagram' => 'sometimes|url',
                'facebook' => 'sometimes|url',
                'linkedin' => 'sometimes|url',
                'twitter' => 'sometimes|url',
                'youtube' => 'sometimes|url',
                'email' => 'sometimes|email',
                'phone' => 'sometimes|string|max:255',
                'quote1' => 'sometimes|string',
                'quote2' => 'sometimes|string',
                'quote3' => 'sometimes|string',
                'location' => 'sometimes|string|max:255',
                'copyright' => 'sometimes|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Handle logo upload if provided
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($footer->logo && Storage::disk('public')->exists($footer->logo)) {
                    Storage::disk('public')->delete($footer->logo);
                }

                // Upload new logo
                $logoPath = $request->file('logo')->store('footer-logos', 'public');
                $footer->logo = $logoPath;
            }

            // Update other fields
            $footer->fill($request->except('logo'));
            $footer->save();

            // Add full URL for logo in response
            $footer->logo_url = asset('storage/' . $footer->logo);

            return response()->json([
                'success' => true,
                'message' => 'Footer data updated successfully',
                'data' => $footer->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update footer data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
