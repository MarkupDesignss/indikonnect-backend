<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentBlock;
use App\Models\ContentMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ContentController extends Controller
{
    /**
     * Display a listing of all contents
     */
    public function index(Request $request)
    {
        try {
            $query = Content::with(['blocks.media'])
                ->where('status', 'published');

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $contents = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $this->formatContents($contents)
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contents',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function adminindex(Request $request)
    {
        try {
            $query = Content::with(['blocks.media']);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $contents = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $this->formatContents($contents)
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified content
     */
    public function show($slug)
    {
        try {
            $content = Content::with(['blocks.media'])->where('slug', $slug)->first();

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatContent($content)
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch content',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Store a newly created content
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'status' => 'nullable|in:draft,published',
                'blocks' => 'required|array|min:1',
                'blocks.*.heading' => 'nullable|string|max:255',
                'blocks.*.short_description' => 'nullable|string',
                'blocks.*.description' => 'nullable|string',
                'blocks.*.images' => 'nullable|array',
                'blocks.*.images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp',
                'blocks.*.videos' => 'nullable|array',
                'blocks.*.videos.*' => 'nullable|file|mimes:mp4,avi,mov,wmv',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create content
            $content = Content::create([
                'title' => $request->title,
                'slug' => $request->slug ?? null,
                'status' => $request->status ?? 'draft',
                'current_version' => 1,
            ]);

            // Create blocks
            foreach ($request->blocks as $blockData) {
                $block = $content->blocks()->create([
                    'heading' => $blockData['heading'] ?? null,
                    'short_description' => $blockData['short_description'] ?? null,
                    'description' => $blockData['description'] ?? null,
                    'sort_order' => $blockData['sort_order'] ?? 0
                ]);

                // Upload images - Check if images exist and are valid files
                if (isset($blockData['images']) && is_array($blockData['images'])) {
                    foreach ($blockData['images'] as $index => $image) {
                        // Check if it's a valid uploaded file
                        if ($image instanceof \Illuminate\Http\UploadedFile && $image->isValid()) {
                            $path = $image->store('content/images', 'public');
                            $block->media()->create([
                                'type' => 'image',
                                'path' => $path,
                                'is_primary' => $index === 0,
                                'sort_order' => $index
                            ]);
                        }
                    }
                }

                // Upload videos - Check if videos exist and are valid files
                if (isset($blockData['videos']) && is_array($blockData['videos'])) {
                    foreach ($blockData['videos'] as $index => $video) {
                        // Check if it's a valid uploaded file
                        if ($video instanceof \Illuminate\Http\UploadedFile && $video->isValid()) {
                            $path = $video->store('content/videos', 'public');
                            $block->media()->create([
                                'type' => 'video',
                                'path' => $path,
                                'sort_order' => $index
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            // Load relationships
            $content->load(['blocks.media']);

            return response()->json([
                'success' => true,
                'message' => 'Content created successfully',
                'data' => $this->formatContent($content)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create content',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Remove in production
            ], 500);
        }
    }

    /**
     * Update the specified content
     */
    // public function update(Request $request, $id)
    // {
    //     try {
    //         $validator = Validator::make($request->all(), [
    //             'title' => 'nullable|string|max:255',
    //             'status' => 'nullable|in:draft,published',
    //             'blocks' => 'nullable|array|min:1',
    //             'blocks.*.heading' => 'nullable|string|max:255',
    //             'blocks.*.short_description' => 'nullable|string',
    //             'blocks.*.description' => 'nullable|string',
    //             'blocks.*.images' => 'nullable|array',
    //             'blocks.*.images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp',
    //             'blocks.*.videos' => 'nullable|array',
    //             'blocks.*.videos.*' => 'nullable|file|mimes:mp4,avi,mov,wmv',
    //             'blocks.*.existing_images' => 'nullable|array',
    //             'blocks.*.existing_images.*' => 'nullable|exists:content_media,id',
    //             'blocks.*.existing_videos' => 'nullable|array',
    //             'blocks.*.existing_videos.*' => 'nullable|exists:content_media,id',
    //         ]);

    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Validation failed',
    //                 'errors' => $validator->errors()
    //             ], 422);
    //         }

    //         $content = Content::find($id);

    //         if (!$content) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Content not found'
    //             ], 404);
    //         }

    //         DB::beginTransaction();

    //         // Update content
    //         $content->update($request->only(['title', 'status']));

    //         // ONLY process blocks if they are sent in the request
    //         if ($request->has('blocks') && is_array($request->blocks) && count($request->blocks) > 0) {
    //             $processedBlockIds = [];
    //             $blockSortOrder = 0;

    //             foreach ($request->blocks as $blockData) {
    //                 $block = null;

    //                 if (isset($blockData['id'])) {
    //                     // Find existing block
    //                     $block = ContentBlock::find($blockData['id']);

    //                     // Ensure the block belongs to this content
    //                     if ($block && $block->content_id === $content->id) {
    //                         // Update existing block
    //                         $block->update([
    //                             'heading' => $blockData['heading'] ?? null,
    //                             'short_description' => $blockData['short_description'] ?? null,
    //                             'description' => $blockData['description'] ?? null,
    //                             'sort_order' => $blockSortOrder++
    //                         ]);
    //                         $processedBlockIds[] = $block->id;
    //                     }
    //                 } else {
    //                     // Create new block
    //                     $block = $content->blocks()->create([
    //                         'heading' => $blockData['heading'] ?? null,
    //                         'short_description' => $blockData['short_description'] ?? null,
    //                         'description' => $blockData['description'] ?? null,
    //                         'sort_order' => $blockSortOrder++
    //                     ]);
    //                     $processedBlockIds[] = $block->id;
    //                 }

    //                 // Only proceed if we have a valid block
    //                 if (!$block) {
    //                     continue;
    //                 }

    //                 // Handle media deletions
    //                 if (isset($blockData['delete_media']) && is_array($blockData['delete_media'])) {
    //                     foreach ($blockData['delete_media'] as $mediaId) {
    //                         $media = ContentMedia::find($mediaId);
    //                         if ($media && $media->content_block_id === $block->id) {
    //                             Storage::disk('public')->delete($media->path);
    //                             $media->delete();
    //                         }
    //                     }
    //                 }

    //                 // Handle existing images - ONLY if existing_images is sent
    //                 if (isset($blockData['existing_images']) && is_array($blockData['existing_images'])) {
    //                     $keepImageIds = $blockData['existing_images'];

    //                     // Update sort order for existing images
    //                     foreach ($keepImageIds as $sortIndex => $mediaId) {
    //                         $media = ContentMedia::find($mediaId);
    //                         if ($media && $media->content_block_id === $block->id) {
    //                             $media->update([
    //                                 'sort_order' => $sortIndex,
    //                                 'is_primary' => $sortIndex === 0
    //                             ]);
    //                         }
    //                     }

    //                     // Delete images that are not in the keep list
    //                     $block->media()
    //                         ->where('type', 'image')
    //                         ->whereNotIn('id', $keepImageIds)
    //                         ->each(function ($media) {
    //                             Storage::disk('public')->delete($media->path);
    //                             $media->delete();
    //                         });
    //                 }
    //                 // If existing_images is NOT sent, keep all existing images
    //                 // Do nothing - images will be preserved

    //                 // Handle existing videos - ONLY if existing_videos is sent
    //                 if (isset($blockData['existing_videos']) && is_array($blockData['existing_videos'])) {
    //                     $keepVideoIds = $blockData['existing_videos'];

    //                     // Update sort order for existing videos
    //                     foreach ($keepVideoIds as $sortIndex => $mediaId) {
    //                         $media = ContentMedia::find($mediaId);
    //                         if ($media && $media->content_block_id === $block->id) {
    //                             $media->update([
    //                                 'sort_order' => $sortIndex
    //                             ]);
    //                         }
    //                     }

    //                     // Delete videos that are not in the keep list
    //                     $block->media()
    //                         ->where('type', 'video')
    //                         ->whereNotIn('id', $keepVideoIds)
    //                         ->each(function ($media) {
    //                             Storage::disk('public')->delete($media->path);
    //                             $media->delete();
    //                         });
    //                 }
    //                 // If existing_videos is NOT sent, keep all existing videos
    //                 // Do nothing - videos will be preserved

    //                 // Upload new images
    //                 if (isset($blockData['images']) && is_array($blockData['images'])) {
    //                     $currentImageCount = $block->media()->where('type', 'image')->count();

    //                     foreach ($blockData['images'] as $index => $image) {
    //                         if ($image instanceof \Illuminate\Http\UploadedFile && $image->isValid()) {
    //                             $path = $image->store('content/images', 'public');
    //                             $block->media()->create([
    //                                 'type' => 'image',
    //                                 'path' => $path,
    //                                 'is_primary' => $currentImageCount === 0 && $index === 0,
    //                                 'sort_order' => $currentImageCount + $index
    //                             ]);
    //                         }
    //                     }
    //                 }

    //                 // Upload new videos
    //                 if (isset($blockData['videos']) && is_array($blockData['videos'])) {
    //                     $currentVideoCount = $block->media()->where('type', 'video')->count();

    //                     foreach ($blockData['videos'] as $index => $video) {
    //                         if ($video instanceof \Illuminate\Http\UploadedFile && $video->isValid()) {
    //                             $path = $video->store('content/videos', 'public');
    //                             $block->media()->create([
    //                                 'type' => 'video',
    //                                 'path' => $path,
    //                                 'sort_order' => $currentVideoCount + $index
    //                             ]);
    //                         }
    //                     }
    //                 }
    //             }

    //             // Delete blocks that are no longer present
    //             ContentBlock::where('content_id', $content->id)
    //                 ->whereNotIn('id', $processedBlockIds)
    //                 ->each(function ($block) {
    //                     // Delete all associated media files
    //                     foreach ($block->media as $media) {
    //                         Storage::disk('public')->delete($media->path);
    //                         $media->delete();
    //                     }
    //                     // Now delete the block
    //                     $block->delete();
    //                 });
    //         }

    //         DB::commit();

    //         // Load relationships
    //         $content->load(['blocks.media']);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Content updated successfully',
    //             'data' => $this->formatContent($content)
    //         ], 200);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to update content',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    public function update(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'status' => 'nullable|in:draft,published',
                'blocks' => 'nullable|array|min:1',
                'blocks.*.heading' => 'nullable|string|max:255',
                'blocks.*.short_description' => 'nullable|string',
                'blocks.*.description' => 'nullable|string',
                'blocks.*.images' => 'nullable|array',
                'blocks.*.images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp',
                'blocks.*.videos' => 'nullable|array',
                'blocks.*.videos.*' => 'nullable|file|mimes:mp4,avi,mov,wmv',
                'blocks.*.existing_images' => 'nullable|array',
                'blocks.*.existing_images.*' => 'nullable|exists:content_media,id',
                'blocks.*.existing_videos' => 'nullable|array',
                'blocks.*.existing_videos.*' => 'nullable|exists:content_media,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find the content to update
            $originalContent = Content::find($id);
            if (!$originalContent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content not found'
                ], 404);
            }

            DB::beginTransaction();

            // STEP 1: Mark current content as draft
            $originalContent->status = 'draft';
            $originalContent->updated_at = now();
            $originalContent->save();

            // STEP 2: Get the latest version number from all versions with same slug
            $allVersions = Content::where('slug', $originalContent->slug)
                ->orderBy('id', 'desc')
                ->get();

            // Calculate next version
            $latestVersion = $allVersions->first()->current_version ?? '1.0';
            $newVersion = $this->calculateNextVersion($latestVersion);

            // STEP 3: Create new version with published status
            $newContent = $originalContent->replicate();

            // Clear the ID so it creates a new record
            $newContent->id = null;

            // Set new version details
            $newContent->current_version = $newVersion;
            $newContent->status = 'published'; // New version is always published
            $newContent->title = $request->input('title', $originalContent->title);
            $newContent->slug = $originalContent->slug; // Keep the same slug
            $newContent->created_at = now();
            $newContent->updated_at = now();
            $newContent->save();

            // STEP 4: Handle blocks
            if ($request->has('blocks') && is_array($request->blocks) && count($request->blocks) > 0) {
                $blockSortOrder = 0;

                foreach ($request->blocks as $blockData) {
                    $block = null;

                    if (isset($blockData['id'])) {
                        // Find the original block
                        $originalBlock = ContentBlock::find($blockData['id']);

                        if ($originalBlock && $originalBlock->content_id === $originalContent->id) {
                            // Create new block from original
                            $block = $newContent->blocks()->create([
                                'heading' => $blockData['heading'] ?? $originalBlock->heading,
                                'short_description' => $blockData['short_description'] ?? $originalBlock->short_description,
                                'description' => $blockData['description'] ?? $originalBlock->description,
                                'sort_order' => $blockSortOrder++
                            ]);
                            $this->copyMedia($originalBlock, $block);
                        }
                    } else {
                        // Create new block
                        $block = $newContent->blocks()->create([
                            'heading' => $blockData['heading'] ?? null,
                            'short_description' => $blockData['short_description'] ?? null,
                            'description' => $blockData['description'] ?? null,
                            'sort_order' => $blockSortOrder++
                        ]);
                    }

                    if (!$block) {
                        continue;
                    }

                    // Handle media deletions
                    if (isset($blockData['delete_media']) && is_array($blockData['delete_media'])) {
                        foreach ($blockData['delete_media'] as $mediaId) {
                            $media = ContentMedia::find($mediaId);
                            if ($media && $media->content_block_id === $block->id) {
                                Storage::disk('public')->delete($media->path);
                                $media->delete();
                            }
                        }
                    }

                    // Handle existing images
                    if (isset($blockData['existing_images']) && is_array($blockData['existing_images'])) {
                        $keepImageIds = $blockData['existing_images'];

                        foreach ($keepImageIds as $sortIndex => $mediaId) {
                            $media = ContentMedia::find($mediaId);
                            if ($media && $media->content_block_id === $block->id) {
                                $media->update([
                                    'sort_order' => $sortIndex,
                                    'is_primary' => $sortIndex === 0
                                ]);
                            }
                        }

                        $block->media()
                            ->where('type', 'image')
                            ->whereNotIn('id', $keepImageIds)
                            ->each(function ($media) {
                                Storage::disk('public')->delete($media->path);
                                $media->delete();
                            });
                    }

                    // Handle existing videos
                    if (isset($blockData['existing_videos']) && is_array($blockData['existing_videos'])) {
                        $keepVideoIds = $blockData['existing_videos'];

                        foreach ($keepVideoIds as $sortIndex => $mediaId) {
                            $media = ContentMedia::find($mediaId);
                            if ($media && $media->content_block_id === $block->id) {
                                $media->update([
                                    'sort_order' => $sortIndex
                                ]);
                            }
                        }

                        $block->media()
                            ->where('type', 'video')
                            ->whereNotIn('id', $keepVideoIds)
                            ->each(function ($media) {
                                Storage::disk('public')->delete($media->path);
                                $media->delete();
                            });
                    }

                    // Upload new images
                    if (isset($blockData['images']) && is_array($blockData['images'])) {
                        $currentImageCount = $block->media()->where('type', 'image')->count();

                        foreach ($blockData['images'] as $index => $image) {
                            if ($image instanceof \Illuminate\Http\UploadedFile && $image->isValid()) {
                                $path = $image->store('content/images', 'public');
                                $block->media()->create([
                                    'type' => 'image',
                                    'path' => $path,
                                    'is_primary' => $currentImageCount === 0 && $index === 0,
                                    'sort_order' => $currentImageCount + $index
                                ]);
                            }
                        }
                    }

                    // Upload new videos
                    if (isset($blockData['videos']) && is_array($blockData['videos'])) {
                        $currentVideoCount = $block->media()->where('type', 'video')->count();

                        foreach ($blockData['videos'] as $index => $video) {
                            if ($video instanceof \Illuminate\Http\UploadedFile && $video->isValid()) {
                                $path = $video->store('content/videos', 'public');
                                $block->media()->create([
                                    'type' => 'video',
                                    'path' => $path,
                                    'sort_order' => $currentVideoCount + $index
                                ]);
                            }
                        }
                    }
                }
            } else {
                // Copy all blocks from original to new content
                foreach ($originalContent->blocks as $originalBlock) {
                    $newBlock = $newContent->blocks()->create([
                        'heading' => $originalBlock->heading,
                        'short_description' => $originalBlock->short_description,
                        'description' => $originalBlock->description,
                        'sort_order' => $originalBlock->sort_order
                    ]);
                    $this->copyMedia($originalBlock, $newBlock);
                }
            }

            DB::commit();

            $newContent->load(['blocks.media']);

            return response()->json([
                'success' => true,
                'message' => 'Content updated successfully',
                'data' => $this->formatContent($newContent),
                'version' => $newVersion,
                'previous_version_id' => $originalContent->id,
                'previous_version_status' => 'draft'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update content',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate next version number
     */
    private function calculateNextVersion($currentVersion)
    {
        if (empty($currentVersion)) {
            return '1.0';
        }

        $parts = explode('.', $currentVersion);
        $major = (int) $parts[0];
        $minor = isset($parts[1]) ? (int) $parts[1] : 0;

        $minor++;

        if ($minor >= 10) {
            $major++;
            $minor = 0;
        }

        return $major . '.' . $minor;
    }

    /**
     * Copy media from original block to new block
     */
    private function copyMedia($originalBlock, $newBlock)
    {
        foreach ($originalBlock->media as $media) {
            $newBlock->media()->create([
                'type' => $media->type,
                'path' => $media->path,
                'alt_text' => $media->alt_text,
                'is_primary' => $media->is_primary,
                'sort_order' => $media->sort_order
            ]);
        }
    }

    /**
     * Remove the specified content
     */
    public function destroy($id)
    {
        try {
            $content = Content::find($id);

            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Content not found'
                ], 404);
            }

            DB::beginTransaction();

            // Delete all media files
            foreach ($content->blocks as $block) {
                foreach ($block->media as $media) {
                    Storage::disk('public')->delete($media->path);
                }
            }

            // Delete content (cascade will delete blocks and media)
            $content->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Content deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete content',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format content data
     */
    private function formatContent($content)
    {
        return [
            'id' => $content->id,
            'title' => $content->title,
            'slug' => $content->slug,
            'status' => $content->status,
            'version' => $content->current_version,
            'created_at' => $content->created_at->toISOString(),
            'updated_at' => $content->updated_at->toISOString(),
            'blocks' => $content->blocks->map(function ($block) {
                return [
                    'id' => $block->id,
                    'heading' => $block->heading,
                    'short_description' => $block->short_description,
                    'description' => $block->description,
                    'sort_order' => $block->sort_order,
                    'images' => $block->media->where('type', 'image')->map(function ($media) {
                        return [
                            'id' => $media->id,
                            'url' => url($media->path),
                            'alt_text' => $media->alt_text,
                            'is_primary' => (bool) $media->is_primary
                        ];
                    })->values(),
                    'videos' => $block->media->where('type', 'video')->map(function ($media) {
                        return [
                            'id' => $media->id,
                            'url' => url($media->path)
                        ];
                    })->values()
                ];
            })->values()
        ];
    }

    /**
     * Format multiple contents
     */
    private function formatContents($contents)
    {
        return $contents->map(function ($content) {
            return $this->formatContent($content);
        })->values();
    }
}
