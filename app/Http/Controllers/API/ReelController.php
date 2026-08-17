<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reel;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ReelController extends Controller
{
    /**
     * Display a listing of reels with product details
     */
    public function index(Request $request)
    {
        $reels = Reel::with(['product.category', 'product.taxCategory', 'product.images', 'product.primaryImage'])
            ->where('is_published', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'data' => $this->formatReelCollection($reels),
            'meta' => [
                'current_page' => $reels->currentPage(),
                'per_page' => $reels->perPage(),
                'total' => $reels->total(),
                'last_page' => $reels->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created reel
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'creator_handle' => ['required', 'string', 'max:255'],
            'followers_count' => ['nullable', 'integer', 'min:0'],
            'product_id' => ['required', 'exists:products,id'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],

            // Either video file or video URL is required
            'video' => ['nullable', 'file', 'mimes:mp4,mov,avi,wmv,flv,mkv', 'max:102400'], // Max 100MB
            'video_url' => ['nullable', 'url', 'max:500'],
        ]);

        // Custom validation: At least one video source is required
        $validator->after(function ($validator) use ($request) {
            if (!$request->hasFile('video') && empty($request->video_url)) {
                $validator->errors()->add('video', 'Either video file or video URL is required.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();

            $reelData = [
                'title' => $validated['title'],
                'creator_handle' => $validated['creator_handle'],
                'followers_count' => $validated['followers_count'] ?? 0,
                'product_id' => $validated['product_id'],
                'is_published' => $validated['is_published'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
                'video_url' => null,
                'video_path' => null,
            ];

            // Handle video upload
            if ($request->hasFile('video')) {
                $videoFile = $request->file('video');
                $path = $videoFile->store('reels/videos', 'public');
                $reelData['video_path'] = $path;
            }
            // Handle video URL
            elseif (!empty($validated['video_url'])) {
                $reelData['video_url'] = $validated['video_url'];
            }

            $reel = Reel::create($reelData);

            DB::commit();
            $reel->load(['product.category', 'product.taxCategory', 'product.images', 'product.primaryImage']);

            return response()->json([
                'message' => 'Reel created successfully',
                'data' => $this->formatReel($reel)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create reel:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to create reel',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified reel with product details
     */
    public function show($id)
    {
        $reel = Reel::with(['product.category', 'product.taxCategory', 'product.images', 'product.primaryImage'])
            ->find($id);

        if (!$reel) {
            return response()->json(['message' => 'Reel not found'], 404);
        }

        return response()->json([
            'data' => $this->formatReel($reel)
        ]);
    }

    /**
     * Update the specified reel
     */
    public function update(Request $request, $id)
    {
        $reel = Reel::find($id);
        if (!$reel) {
            return response()->json(['message' => 'Reel not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['nullable', 'string', 'max:255'],
            'creator_handle' => ['nullable', 'string', 'max:255'],
            'followers_count' => ['nullable', 'integer', 'min:0'],
            'product_id' => ['nullable', 'exists:products,id'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'video' => ['nullable', 'file', 'mimes:mp4,mov,avi,wmv,flv,mkv', 'max:102400'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'remove_video' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();

            $reelData = [];

            // Update fields if provided
            if (isset($validated['title'])) {
                $reelData['title'] = $validated['title'];
            }
            if (isset($validated['creator_handle'])) {
                $reelData['creator_handle'] = $validated['creator_handle'];
            }
            if (isset($validated['followers_count'])) {
                $reelData['followers_count'] = $validated['followers_count'];
            }
            if (isset($validated['product_id'])) {
                $reelData['product_id'] = $validated['product_id'];
            }
            if (isset($validated['is_published'])) {
                $reelData['is_published'] = $validated['is_published'];
            }
            if (isset($validated['sort_order'])) {
                $reelData['sort_order'] = $validated['sort_order'];
            }

            // Handle video removal
            if (!empty($validated['remove_video']) && $reel->video_path) {
                // Delete old video file
                if (Storage::disk('public')->exists($reel->video_path)) {
                    Storage::disk('public')->delete($reel->video_path);
                }
                $reelData['video_path'] = null;
                $reelData['video_url'] = null;
            }

            // Handle new video upload
            if ($request->hasFile('video')) {
                // Delete old video if exists
                if ($reel->video_path && Storage::disk('public')->exists($reel->video_path)) {
                    Storage::disk('public')->delete($reel->video_path);
                }

                $videoFile = $request->file('video');
                $path = $videoFile->store('reels/videos', 'public');
                $reelData['video_path'] = $path;
                $reelData['video_url'] = null; // Clear URL if uploading video
            }
            // Handle video URL update
            elseif (isset($validated['video_url'])) {
                // Delete old video if exists
                if ($reel->video_path && Storage::disk('public')->exists($reel->video_path)) {
                    Storage::disk('public')->delete($reel->video_path);
                }
                $reelData['video_url'] = $validated['video_url'];
                $reelData['video_path'] = null;
            }

            if (!empty($reelData)) {
                $reel->update($reelData);
            }

            DB::commit();
            $reel->load(['product.category', 'product.taxCategory', 'product.images', 'product.primaryImage']);

            return response()->json([
                'message' => 'Reel updated successfully',
                'data' => $this->formatReel($reel)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update reel:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to update reel',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified reel
     */
    public function destroy($id)
    {
        $reel = Reel::find($id);
        if (!$reel) {
            return response()->json(['message' => 'Reel not found'], 404);
        }

        DB::beginTransaction();
        try {
            // Delete video file if exists
            if ($reel->video_path && Storage::disk('public')->exists($reel->video_path)) {
                Storage::disk('public')->delete($reel->video_path);
            }

            $reel->delete();

            DB::commit();

            return response()->json([
                'message' => 'Reel deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete reel:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to delete reel',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get reels by product
     */
    public function getByProduct($productId)
    {
        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $reels = Reel::with(['product.category', 'product.taxCategory', 'product.images', 'product.primaryImage'])
            ->where('product_id', $productId)
            ->where('is_published', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'product' => $this->formatProduct($product),
            'reels' => $this->formatReelCollection($reels)
        ]);
    }

    /**
     * Format single reel response
     */
    protected function formatReel($reel)
    {
        return [
            'id' => $reel->id,
            'title' => $reel->title,
            'creator_handle' => $reel->creator_handle,
            'followers_count' => $reel->followers_count,
            'video_path' => $reel->video_path,
            'video_url' => $reel->video_url,
            'video_full_url' => $reel->video_full_url, // Using accessor
            'is_published' => (bool) $reel->is_published,
            'sort_order' => $reel->sort_order,
            'created_at' => $reel->created_at?->toISOString(),
            'updated_at' => $reel->updated_at?->toISOString(),
            'product' => $this->formatProduct($reel->product),
        ];
    }

    /**
     * Format multiple reels response
     */
    protected function formatReelCollection($reels)
    {
        return $reels->map(function ($reel) {
            return $this->formatReel($reel);
        })->values()->toArray();
    }

    /**
     * Format product details with full image URLs
     */
    protected function formatProduct($product)
    {
        if (!$product) {
            return null;
        }

        $primaryImage = $product->images->where('is_primary', true)->first()
            ?? $product->images->first();

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            // 'description' => $product->description,
            // 'specification' => $product->specification,
            'category_id' => $product->category_id,
            // 'category' => $product->category ? [
            //     'id' => $product->category->id,
            //     'name' => $product->category->title,
            //     'slug' => $product->category->slug,
            // ] : null,
            'tax_category_id' => $product->tax_category_id,
            'retail_mrp' => $product->retail_mrp,
            'retail_price' => $product->retail_price,
            // 'retail_price_formatted' => number_format($product->retail_price, 2),
            // 'retail_discount_type' => $product->retail_discount_type,
            // 'retail_discount_value' => $product->retail_discount_value,
            'distributor_mrp' => $product->distributor_mrp,
            'distributor_price' => $product->distributor_price,
            // 'distributor_price_formatted' => $product->distributor_price ? number_format($product->distributor_price, 2) : null,
            'stock_quantity' => (int) $product->stock_quantity,
            'low_stock_threshold' => (int) $product->low_stock_threshold,
            'is_published' => (bool) $product->is_published,
            // 'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
            // 'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
            // 'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
            'images' => $product->images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'image' => $image->image,
                    'image_url' => asset('storage/' . $image->image),
                    'is_primary' => (bool) $image->is_primary,
                    'sort_order' => $image->sort_order,
                ];
            })->values()->toArray(),
            // 'primary_image' => $primaryImage ? [
            //     'id' => $primaryImage->id,
            //     'image_url' => asset('storage/' . $primaryImage->image),
            // ] : null,
            // 'created_at' => $product->created_at?->toISOString(),
            // 'updated_at' => $product->updated_at?->toISOString(),
        ];
    }
}
