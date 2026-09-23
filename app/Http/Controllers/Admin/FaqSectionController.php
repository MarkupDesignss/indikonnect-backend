<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaqSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;

class FaqSectionController extends Controller
{
    /**
     * List all sections (used for admin table + dropdowns).
     *
     * Query params:
     *   ?is_active=1
     *   ?search=keyword
     *   ?with_faqs=1
     *   ?per_page=15&page=1
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FaqSection::query();

            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            if ($request->boolean('with_faqs')) {
                $query->with(['faqs' => fn($q) => $q->orderBy('order')]);
            }

            $query->ordered();

            $perPage  = (int) $request->get('per_page', 15);
            $sections = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => $sections->items(),
                'meta'    => [
                    'current_page' => $sections->currentPage(),
                    'per_page'     => $sections->perPage(),
                    'total'        => $sections->total(),
                    'last_page'    => $sections->lastPage(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sections',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lightweight list for dropdowns (no pagination).
     * GET /admin/faq-sections/dropdown
     */
    public function dropdown(): JsonResponse
    {
        try {
            $sections = FaqSection::active()
                ->ordered()
                ->get(['id', 'name', 'slug']);

            return response()->json([
                'success' => true,
                'data'    => $sections,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sections',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:255|unique:faq_sections,name',
                'description' => 'nullable|string',
                'order'       => 'nullable|integer|min:0',
                'is_active'   => 'nullable|boolean',
            ]);

            $section = FaqSection::create([
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
                'order'       => $validated['order'] ?? 0,
                'is_active'   => $validated['is_active'] ?? true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Section created successfully',
                'data'    => $section,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create section',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $section = FaqSection::with(['faqs' => fn($q) => $q->orderBy('order')])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $section,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch section',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $section = FaqSection::findOrFail($id);

            $validated = $request->validate([
                'name'        => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('faq_sections', 'name')->ignore($section->id),
                ],
                'description' => 'nullable|string',
                'order'       => 'nullable|integer|min:0',
                'is_active'   => 'nullable|boolean',
            ]);

            $section->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Section updated successfully',
                'data'    => $section->fresh(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update section',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $section = FaqSection::findOrFail($id);

            // Guard: prevent deleting a section that still has FAQs
            if ($section->faqs()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete a section that contains FAQs. Move or delete them first.',
                ], 409);
            }

            $section->delete();

            return response()->json([
                'success' => true,
                'message' => 'Section deleted successfully',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Section not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete section',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
