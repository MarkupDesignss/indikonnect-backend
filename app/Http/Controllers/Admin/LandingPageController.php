<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LandingPageController extends Controller
{
    /**
     * Get all landing page sections
     */
    public function index()
    {
        $sections = LandingPage::ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $sections
        ]);
    }

    /**
     * Get single section by ID
     */
    public function show($id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $section
        ]);
    }

    /**
     * Create new section with all data
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'section' => 'required|string|unique:landing_pages,section',
            'section_title' => 'nullable|string',
            'section_subtitle' => 'nullable|string',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'string',
            'color' => 'nullable|string',
            'content_data' => 'nullable|array',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $section = LandingPage::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Section created successfully',
            'data' => $section
        ], 201);
    }

    /**
     * Update complete section with all data
     */
    public function update(Request $request, $id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'section' => 'nullable|string|unique:landing_pages,section,' . $id,
            'section_title' => 'nullable|string',
            'section_subtitle' => 'nullable|string',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'string',
            'color' => 'nullable|string',
            'content_data' => 'nullable|array',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $section->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Section updated successfully',
            'data' => $section
        ]);
    }

    /**
     * Delete section
     */
    public function destroy($id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $section->delete();

        return response()->json([
            'success' => true,
            'message' => 'Section deleted successfully'
        ]);
    }

    /**
     * Bulk update all sections data
     */
    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sections' => 'required|array',
            'sections.*.id' => 'required|exists:landing_pages,id',
            'sections.*.section' => 'nullable|string',
            'sections.*.section_title' => 'nullable|string',
            'sections.*.section_subtitle' => 'nullable|string',
            'sections.*.description' => 'nullable|string',
            'sections.*.images' => 'nullable|array',
            'sections.*.color' => 'nullable|string',
            'sections.*.content_data' => 'nullable|array',
            'sections.*.order' => 'nullable|integer',
            'sections.*.is_active' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $updatedSections = [];
        foreach ($request->sections as $sectionData) {
            $section = LandingPage::find($sectionData['id']);
            $section->update($sectionData);
            $updatedSections[] = $section;
        }

        return response()->json([
            'success' => true,
            'message' => 'All sections updated successfully',
            'data' => $updatedSections
        ]);
    }

    /**
     * Update specific section data with all fields
     */
    public function updateSectionData(Request $request, $id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'section' => 'nullable|string|unique:landing_pages,section,' . $id,
            'section_title' => 'nullable|string',
            'section_subtitle' => 'nullable|string',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'string',
            'color' => 'nullable|string',
            'content_data' => 'nullable|array',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $section->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Section data updated successfully',
            'data' => $section
        ]);
    }

    /**
     * Add image to section
     */
    public function addImage(Request $request, $id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'image' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $images = $section->images ?? [];
        $images[] = $request->image;
        $section->images = $images;
        $section->save();

        return response()->json([
            'success' => true,
            'message' => 'Image added successfully',
            'data' => $section
        ]);
    }

    /**
     * Remove image from section
     */
    public function removeImage(Request $request, $id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'image_index' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $images = $section->images ?? [];
        $index = $request->image_index;

        if (!isset($images[$index])) {
            return response()->json([
                'success' => false,
                'message' => 'Image not found at this index'
            ], 404);
        }

        unset($images[$index]);
        $section->images = array_values($images);
        $section->save();

        return response()->json([
            'success' => true,
            'message' => 'Image removed successfully',
            'data' => $section
        ]);
    }

    /**
     * Update content_data completely
     */
    public function updateContentData(Request $request, $id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'content_data' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $section->content_data = $request->content_data;
        $section->save();

        return response()->json([
            'success' => true,
            'message' => 'Content data updated successfully',
            'data' => $section
        ]);
    }

    /**
     * Update a specific field in content_data
     */
    public function updateContentField(Request $request, $id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'key' => 'required|string',
            'value' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $contentData = $section->content_data ?? [];
        $contentData[$request->key] = $request->value;
        $section->content_data = $contentData;
        $section->save();

        return response()->json([
            'success' => true,
            'message' => 'Content field updated successfully',
            'data' => $section
        ]);
    }

    /**
     * Update order of sections
     */
    public function updateOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sections' => 'required|array',
            'sections.*.id' => 'required|exists:landing_pages,id',
            'sections.*.order' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->sections as $item) {
            LandingPage::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order updated successfully'
        ]);
    }

    /**
     * Toggle section active status
     */
    public function toggleStatus($id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $section->is_active = !$section->is_active;
        $section->save();

        return response()->json([
            'success' => true,
            'message' => 'Status toggled successfully',
            'data' => $section
        ]);
    }

    /**
     * Replace all images at once
     */
    public function replaceImages(Request $request, $id)
    {
        $section = LandingPage::find($id);

        if (!$section) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'images' => 'required|array',
            'images.*' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $section->images = $request->images;
        $section->save();

        return response()->json([
            'success' => true,
            'message' => 'Images replaced successfully',
            'data' => $section
        ]);
    }
}
