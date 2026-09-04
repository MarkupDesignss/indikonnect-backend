<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FAQ;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FAQController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FAQ::query();

            // Filter by category
            if ($request->has('category') && $request->category) {
                $query->where('category', $request->category);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            // Search in question and answer
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('question', 'LIKE', "%{$search}%")
                        ->orWhere('answer', 'LIKE', "%{$search}%");
                });
            }

            // Sort
            $sortField = $request->get('sort_by', 'order');
            $sortDirection = $request->get('sort_direction', 'asc');
            $query->orderBy($sortField, $sortDirection);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $faqs = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $faqs->items(),
                'meta' => [
                    'current_page' => $faqs->currentPage(),
                    'per_page' => $faqs->perPage(),
                    'total' => $faqs->total(),
                    'last_page' => $faqs->lastPage()
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch FAQs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created FAQ
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validation
            $validated = $request->validate([
                'question' => 'required|string|max:255|unique:faqs,question',
                'answer' => 'required|string',
                'order' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean'
            ]);

            // Set default values
            $validated['order'] = $validated['order'] ?? 0;
            $validated['is_active'] = $validated['is_active'] ?? true;

            // Create FAQ
            $faq = FAQ::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'FAQ created successfully',
                'data' => $faq
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create FAQ',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified FAQ
     */
    public function show($id): JsonResponse
    {
        try {
            $faq = FAQ::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $faq
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch FAQ',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified FAQ
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $faq = FAQ::findOrFail($id);

            // Validation
            $validated = $request->validate([
                'question' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('faqs', 'question')->ignore($id)
                ],
                'answer' => 'sometimes|required|string',
                'order' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean'
            ]);

            // Update FAQ
            $faq->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'FAQ updated successfully',
                'data' => $faq->fresh()
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update FAQ',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified FAQ
     */
    public function destroy($id): JsonResponse
    {
        try {
            $faq = FAQ::findOrFail($id);
            $faq->delete();

            return response()->json([
                'success' => true,
                'message' => 'FAQ deleted successfully'
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete FAQ',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete FAQs
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'required|integer|exists:faqs,id'
            ]);

            $deleted = FAQ::whereIn('id', $validated['ids'])->delete();

            return response()->json([
                'success' => true,
                'message' => "{$deleted} FAQ(s) deleted successfully",
                'deleted_count' => $deleted
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete FAQs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
