<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FAQ;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class FAQController extends Controller
{
    /**
     * List FAQs.
     *
     * Supports query params:
     *   ?section=Section1,Section2
     *   ?is_active=1
     *   ?search=keyword
     *   ?group_by_section=1
     *   ?sort_by=section|order|question|is_active|created_at
     *   ?sort_direction=asc|desc
     *   ?per_page=15&page=1
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FAQ::query();

            // Filter by section (single or multiple)
            if ($request->filled('section')) {
                $sections = is_array($request->section)
                    ? $request->section
                    : explode(',', $request->section);

                $query->whereIn('section', $sections);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where(
                    'is_active',
                    filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                );
            }

            // Search
            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {
                    $q->where('question', 'LIKE', "%{$search}%")
                        ->orWhere('answer', 'LIKE', "%{$search}%");
                });
            }

            // Sorting
            $sortField     = $request->get('sort_by', 'section');
            $sortDirection = strtolower($request->get('sort_direction', 'asc'));

            $allowedSorts = ['section', 'order', 'question', 'is_active', 'created_at'];

            if (!in_array($sortField, $allowedSorts, true)) {
                $sortField = 'section';
            }
            if (!in_array($sortDirection, ['asc', 'desc'], true)) {
                $sortDirection = 'asc';
            }

            $query->orderBy($sortField, $sortDirection);

            // Secondary sort by order for stable grouping
            if ($sortField !== 'order') {
                $query->orderBy('order', 'asc');
            }

            // ---------------------------------------------------------
            // Grouped response (section-wise)
            // ---------------------------------------------------------
            if ($request->boolean('group_by_section')) {
                $faqs = $query->get()->groupBy('section');

                $groupedData = $faqs->map(function ($sectionFaqs, $section) {
                    return [
                        'section' => $section,
                        'faqs'    => $sectionFaqs->map(function ($faq) {
                            return [
                                'id'         => $faq->id,
                                'question'   => $faq->question,
                                'answer'     => $faq->answer,
                                'order'      => $faq->order,
                                'is_active'  => $faq->is_active,
                                'created_at' => $faq->created_at,
                                'updated_at' => $faq->updated_at,
                            ];
                        })->values(),
                    ];
                })->values();

                return response()->json([
                    'success' => true,
                    'data'    => $groupedData,
                ], 200);
            }

            // ---------------------------------------------------------
            // Paginated flat response
            // ---------------------------------------------------------
            $perPage = (int) $request->get('per_page', 15);
            $faqs    = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => $faqs->items(),
                'meta'    => [
                    'current_page' => $faqs->currentPage(),
                    'per_page'     => $faqs->perPage(),
                    'total'        => $faqs->total(),
                    'last_page'    => $faqs->lastPage(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch FAQs',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store multiple FAQs under one section.
     *
     * Payload:
     * {
     *   "section": "Company Legality & Incorporation",
     *   "is_active": true,
     *   "faqs": [
     *     { "question": "...", "answer": "...", "order": 1, "is_active": true },
     *     { "question": "...", "answer": "...", "order": 2, "is_active": true }
     *   ]
     * }
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'section'          => 'required|string|max:255',
                'is_active'        => 'nullable|boolean',
                'faqs'             => 'required|array|min:1',
                'faqs.*.question'  => 'required|string|max:255|distinct',
                'faqs.*.answer'    => 'required|string',
                'faqs.*.order'     => 'nullable|integer|min:0',
                'faqs.*.is_active' => 'nullable|boolean',
            ]);

            $sectionName   = $validated['section'];
            $sectionActive = $validated['is_active'] ?? true;

            // ---- Duplicate check (question globally unique) ----
            $questions = collect($validated['faqs'])->pluck('question')->all();

            $existing = FAQ::whereIn('question', $questions)->pluck('question')->all();

            if (!empty($existing)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some questions already exist',
                    'errors'  => ['duplicates' => array_values($existing)],
                ], 422);
            }

            // ---- Insert all FAQs of this section in one transaction ----
            $created = DB::transaction(function () use ($validated, $sectionName, $sectionActive) {
                $created = [];

                foreach ($validated['faqs'] as $index => $faqData) {
                    $created[] = FAQ::create([
                        'section'   => $sectionName,
                        'question'  => $faqData['question'],
                        'answer'    => $faqData['answer'],
                        'order'     => $faqData['order'] ?? ($index + 1),
                        'is_active' => $faqData['is_active'] ?? $sectionActive,
                    ]);
                }

                return $created;
            });

            return response()->json([
                'success' => true,
                'message' => count($created) . ' FAQ(s) created successfully in section: ' . $sectionName,
                'data'    => $created,
                'meta'    => [
                    'section'    => $sectionName,
                    'faqs_count' => count($created),
                ],
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
                'message' => 'Failed to create FAQs',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show a single FAQ
     */
    public function show($id): JsonResponse
    {
        try {
            $faq = FAQ::findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $faq,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch FAQ',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update FAQ
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $faq = FAQ::findOrFail($id);

            $validated = $request->validate([
                'section'   => 'sometimes|required|string|max:255',
                'question'  => [
                    'sometimes',
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('faqs', 'question')->ignore($id),
                ],
                'answer'    => 'sometimes|required|string',
                'order'     => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
            ]);

            $faq->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'FAQ updated successfully',
                'data'    => $faq->fresh(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found',
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
                'message' => 'Failed to update FAQ',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a single FAQ
     */
    public function destroy($id): JsonResponse
    {
        try {
            $faq = FAQ::findOrFail($id);
            $faq->delete();

            return response()->json([
                'success' => true,
                'message' => 'FAQ deleted successfully',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'FAQ not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete FAQ',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
