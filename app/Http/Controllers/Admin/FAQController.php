<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FAQ;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\FaqSection;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class FAQController extends Controller
{
    /**
     * List FAQs.
     *
     * Query params:
     *   ?section_id=1,2,3         (comma separated or array)
     *   ?is_active=1
     *   ?search=keyword
     *   ?group_by_section=1
     *   ?sort_by=order|question|is_active|created_at
     *   ?sort_direction=asc|desc
     *   ?per_page=15&page=1
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FAQ::with('section:id,name,slug');

            // Filter by section(s)
            if ($request->filled('section_id')) {
                $sectionIds = is_array($request->section_id)
                    ? $request->section_id
                    : explode(',', $request->section_id);

                $query->whereIn('section_id', $sectionIds);
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
            $sortField     = $request->get('sort_by', 'order');
            $sortDirection = strtolower($request->get('sort_direction', 'asc'));

            $allowedSorts = ['order', 'question', 'is_active', 'created_at', 'section_id'];

            if (!in_array($sortField, $allowedSorts, true)) {
                $sortField = 'order';
            }
            if (!in_array($sortDirection, ['asc', 'desc'], true)) {
                $sortDirection = 'asc';
            }

            $query->orderBy($sortField, $sortDirection);

            // Stable secondary sort within a section
            if ($sortField !== 'order') {
                $query->orderBy('order', 'asc');
            }

            // ---------------------------------------------------------
            // Grouped response (section-wise)
            // ---------------------------------------------------------
            if ($request->boolean('group_by_section')) {
                $faqs = $query->get()->groupBy('section_id');

                $groupedData = $faqs->map(function ($sectionFaqs, $sectionId) {
                    $section = $sectionFaqs->first()->section;

                    return [
                        'section_id'   => (int) $sectionId,
                        'section_name' => $section->name ?? null,
                        'section_slug' => $section->slug ?? null,
                        'faqs'         => $sectionFaqs->map(fn($faq) => [
                            'id'         => $faq->id,
                            'question'   => $faq->question,
                            'answer'     => $faq->answer,
                            'order'      => $faq->order,
                            'is_active'  => $faq->is_active,
                            'created_at' => $faq->created_at,
                            'updated_at' => $faq->updated_at,
                        ])->values(),
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
     *   "section_id": 5,
     *   "is_active": true,
     *   "faqs": [
     *     { "question": "...", "answer": "...", "order": 1, "is_active": true },
     *     ...
     *   ]
     * }
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'section_id'       => 'required|integer|exists:faq_sections,id',
                'is_active'        => 'nullable|boolean',
                'faqs'             => 'required|array|min:1',
                'faqs.*.question'  => 'required|string|max:255|distinct',
                'faqs.*.answer'    => 'required|string',
                'faqs.*.order'     => 'nullable|integer|min:0',
                'faqs.*.is_active' => 'nullable|boolean',
            ]);

            $sectionId     = $validated['section_id'];
            $sectionActive = $validated['is_active'] ?? true;

            // Duplicate check (question unique within a section)
            $questions = collect($validated['faqs'])->pluck('question')->all();

            $existing = FAQ::where('section_id', $sectionId)
                ->whereIn('question', $questions)
                ->pluck('question')
                ->all();

            if (!empty($existing)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some questions already exist in this section',
                    'errors'  => ['duplicates' => array_values($existing)],
                ], 422);
            }

            $created = DB::transaction(function () use ($validated, $sectionId, $sectionActive) {
                $created = [];
                foreach ($validated['faqs'] as $index => $faqData) {
                    $created[] = FAQ::create([
                        'section_id' => $sectionId,
                        'question'   => $faqData['question'],
                        'answer'     => $faqData['answer'],
                        'order'      => $faqData['order'] ?? ($index + 1),
                        'is_active'  => $faqData['is_active'] ?? $sectionActive,
                    ]);
                }
                return $created;
            });

            $section = FaqSection::find($sectionId);

            return response()->json([
                'success' => true,
                'message' => count($created) . ' FAQ(s) created successfully in section: ' . $section->name,
                'data'    => $created,
                'meta'    => [
                    'section_id'   => $sectionId,
                    'section_name' => $section->name,
                    'faqs_count'   => count($created),
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

    public function show($id): JsonResponse
    {
        try {
            $faq = FAQ::with('section:id,name,slug')->findOrFail($id);

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
     * Update all FAQs under a section (bulk upsert + optional delete-missing).
     *
     * Payload:
     * {
     *   "section_id": 5,
     *   "is_active": true,
     *   "keep_missing": false,
     *   "faqs": [ { "question": "...", "answer": "...", ... } ]
     * }
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'question'   => 'required|string|max:255',
                'answer'     => 'required|string',
                'order'      => 'nullable|integer|min:0',
                'is_active'  => 'nullable|boolean',
            ]);

            // ---- Find the FAQ by route param id ----
            $faq = FAQ::findOrFail($id);

            // ---- Update only this FAQ ----
            $faq->update([
                'question'  => $validated['question'],
                'answer'    => $validated['answer'],
                'order'     => $validated['order'] ?? $faq->order,
                'is_active' => $validated['is_active'] ?? $faq->is_active,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FAQ updated successfully',
                'data'    => $faq->fresh()->load('section:id,name,slug'),
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
                'errors'   => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update FAQ',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
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
