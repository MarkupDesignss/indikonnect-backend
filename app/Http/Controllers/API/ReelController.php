<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Reel;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ReelController extends Controller
{
    /**
     * Display a listing of reels with product details
     */
    public function index(Request $request)
    {
        $cacheKey = 'reels_' . md5($request->fullUrl());

        $reels =  Reel::with(['product.images', 'product.primaryImage'])
            // ->published()
            ->ordered()
            ->paginate($request->get('per_page', 15));
        // $reels = Cache::remember($cacheKey, 3600, function () use ($request) {
        //     return Reel::with(['product.images', 'product.primaryImage'])
        //         // ->published()
        //         ->ordered()
        //         ->paginate($request->get('per_page', 15));
        // });

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
            'video_url' => [
                'nullable',
                'string',
                'max:500',
            ],

            // Thumbnail validation
            'thumbnail' => ['nullable', 'mimes:jpeg,png,jpg,gif,webp,avif', 'max:5120'], // Max 5MB
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

            // Sanitize inputs
            $reelData = [
                'title' => strip_tags(trim($validated['title'])),
                'creator_handle' => strip_tags(trim($validated['creator_handle'])),
                'followers_count' => $validated['followers_count'] ?? 0,
                'product_id' => $validated['product_id'],
                'is_published' => $validated['is_published'] ?? true,
                'sort_order' => $validated['sort_order'] ?? $this->getNextSortOrder(),
                'video_url' => null,
                'video_path' => null,
                'thumbnail' => null,
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

            // Handle thumbnail upload
            if ($request->hasFile('thumbnail')) {
                $thumbnailFile = $request->file('thumbnail');
                $thumbnailPath = $thumbnailFile->store('reels/thumbnails', 'public');
                $reelData['thumbnail'] = $thumbnailPath;
            }

            $reel = Reel::create($reelData);

            DB::commit();

            // Clear cache
            $this->clearReelsCache();

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
                'error' => 'An error occurred while creating the reel. Please try again.'
            ], 500);
        }
    }

    /**
     * Display the specified reel with product details
     */
    public function show($id)
    {
        $cacheKey = 'reel_' . $id;

        $reel = Cache::remember($cacheKey, 3600, function () use ($id) {
            return Reel::with(['product.category', 'product.taxCategory', 'product.images', 'product.primaryImage'])
                ->find($id);
        });

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
            'video_url' => [
                'nullable',
                'string',
                'max:500',
            ],
            'thumbnail' => ['nullable', 'mimes:jpeg,png,jpg,gif,webp,avif', 'max:5120'],
            'remove_video' => ['nullable', 'boolean'],
            'remove_thumbnail' => ['nullable', 'boolean'],
        ]);

        // Custom validation: At least one video source must remain
        $validator->after(function ($validator) use ($request, $reel) {
            $hasVideo = $request->hasFile('video') ||
                !empty($request->video_url) ||
                (!empty($reel->video_path) && empty($request->remove_video));

            if (!$hasVideo && empty($request->video_url) && empty($reel->video_path)) {
                $validator->errors()->add('video', 'At least one video source is required.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();

            $reelData = [];

            // Update fields if provided with sanitization
            if (isset($validated['title'])) {
                $reelData['title'] = strip_tags(trim($validated['title']));
            }
            if (isset($validated['creator_handle'])) {
                $reelData['creator_handle'] = strip_tags(trim($validated['creator_handle']));
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

            // Handle thumbnail removal
            if (!empty($validated['remove_thumbnail']) && $reel->thumbnail) {
                // Delete old thumbnail file
                if (Storage::disk('public')->exists($reel->thumbnail)) {
                    Storage::disk('public')->delete($reel->thumbnail);
                }
                $reelData['thumbnail'] = null;
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

            // Handle thumbnail upload
            if ($request->hasFile('thumbnail')) {
                // Delete old thumbnail if exists
                if ($reel->thumbnail && Storage::disk('public')->exists($reel->thumbnail)) {
                    Storage::disk('public')->delete($reel->thumbnail);
                }

                $thumbnailFile = $request->file('thumbnail');
                $thumbnailPath = $thumbnailFile->store('reels/thumbnails', 'public');
                $reelData['thumbnail'] = $thumbnailPath;
            }

            if (!empty($reelData)) {
                $reel->update($reelData);
            }

            DB::commit();

            // Clear cache
            $this->clearReelsCache();
            Cache::forget('reel_' . $id);

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
                'error' => 'An error occurred while updating the reel. Please try again.'
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

            // Delete thumbnail file if exists
            if ($reel->thumbnail && Storage::disk('public')->exists($reel->thumbnail)) {
                Storage::disk('public')->delete($reel->thumbnail);
            }

            $reel->delete();

            DB::commit();

            // Clear cache
            $this->clearReelsCache();
            Cache::forget('reel_' . $id);

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
                'error' => 'An error occurred while deleting the reel. Please try again.'
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

        $cacheKey = 'product_reels_' . $productId;

        $reels = Cache::remember($cacheKey, 3600, function () use ($productId) {
            return Reel::with(['product.category', 'product.taxCategory', 'product.images', 'product.primaryImage'])
                ->forProduct($productId)
                ->published()
                ->ordered()
                ->get();
        });

        return response()->json([
            'product' => $this->formatProduct($product),
            'reels' => $this->formatReelCollection($reels)
        ]);
    }

    /**
     * Bulk update sort order
     */
    public function updateSortOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reels' => ['required', 'array'],
            'reels.*.id' => ['required', 'exists:reels,id'],
            'reels.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($request->reels as $reelData) {
                Reel::where('id', $reelData['id'])
                    ->update(['sort_order' => $reelData['sort_order']]);
            }

            DB::commit();

            // Clear cache
            $this->clearReelsCache();

            return response()->json([
                'message' => 'Sort order updated successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update sort order:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to update sort order',
                'error' => 'An error occurred while updating sort order. Please try again.'
            ], 500);
        }
    }

    /**
     * Get the next available sort order
     */
    protected function getNextSortOrder()
    {
        $maxSortOrder = Reel::max('sort_order');
        return ($maxSortOrder ?? 0) + 1;
    }

    /**
     * Clear all reel-related cache
     */
    protected function clearReelsCache()
    {
        // Clear paginated cache - you might want to implement a more sophisticated cache clearing strategy
        Cache::flush(); // Or use a more specific approach
    }

    /**
     * Format single reel response
     */
    protected function formatReel($reel)
    {
        // dd($reel->product->primaryImage);
        return [
            'id' => $reel->id,
            'title' => $reel->title,
            'creator_handle' => $reel->creator_handle,
            'followers_count' => $reel->followers_count,
            'video_path' => $reel->video_path,
            'video_url' => $reel->video_url,
            'video_full_url' => $reel->video_full_url,
            'video_full_path' => $reel->video_full_path,
            'thumbnail' => $reel->thumbnail,
            'thumbnail_url' => $reel->thumbnail ? asset('storage/' . $reel->thumbnail) : null,
            // 'thumbnail_full_url' => $reel->thumbnail ? url('storage/' . $reel->thumbnail) : null,
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
            'category_id' => $product->category_id,
            'tax_category_id' => $product->tax_category_id,
            'retail_mrp' => $product->retail_mrp,
            'retail_price' => $product->retail_price,
            'distributor_mrp' => $product->distributor_mrp,
            'distributor_price' => $product->distributor_price,
            'product_image' => $product->primaryImage
                ? array_merge(
                    $product->primaryImage->toArray(),
                    [
                        'image' => asset('storage/' . $product->primaryImage->image),
                    ]
                )
                : null,
        ];
    }
}
