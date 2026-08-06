<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class TaxCategoryController extends Controller
{
    /**
     * Get all tax categories
     */
    public function index(Request $request)
    {
        $query = TaxCategory::query();

        // Search by name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'LIKE', "%{$search}%");
        }

        // Sort
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        // Validate sort field
        $allowedSortFields = ['id', 'name', 'rate', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }

        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $taxCategories = $query->paginate($perPage);

        // Format data manually
        $formattedData = $this->formatTaxCategoryCollection($taxCategories);

        return response()->json([
            'data' => $formattedData,
            'pagination' => [
                'total' => $taxCategories->total(),
                'per_page' => $taxCategories->perPage(),
                'current_page' => $taxCategories->currentPage(),
                'last_page' => $taxCategories->lastPage(),
                'from' => $taxCategories->firstItem(),
                'to' => $taxCategories->lastItem(),
            ],
        ]);
    }

    /**
     * Get all tax categories (without pagination)
     */
    public function all()
    {
        $taxCategories = TaxCategory::all();
        $formattedData = $this->formatTaxCategoryCollection($taxCategories);

        return response()->json([
            'data' => $formattedData,
        ]);
    }

    /**
     * Get a single tax category by ID
     */
    public function show($id)
    {
        $taxCategory = TaxCategory::findOrFail($id);
        $formattedData = $this->formatTaxCategory($taxCategory);

        return response()->json($formattedData);
    }

    /**
     * Create a new tax category
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('tax_categories')],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $taxCategory = TaxCategory::create($validated);
        $formattedData = $this->formatTaxCategory($taxCategory);

        return response()->json($formattedData, 201);
    }

    /**
     * Update an existing tax category
     */
    public function update(Request $request, $id)
    {
        $taxCategory = TaxCategory::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('tax_categories')->ignore($taxCategory->id)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $taxCategory->update($validated);
        $formattedData = $this->formatTaxCategory($taxCategory);

        return response()->json($formattedData);
    }

    /**
     * Delete a tax category
     */
    public function destroy($id)
    {
        $taxCategory = TaxCategory::findOrFail($id);

        // Check if tax category is being used by any product
        // Uncomment if you have a relationship with products
        // if ($taxCategory->products()->exists()) {
        //     return response()->json([
        //         'message' => 'Cannot delete tax category as it is being used by products'
        //     ], 409);
        // }

        $taxCategory->delete();

        return response()->json([
            'message' => 'Tax category deleted successfully',
        ], 200);
    }

    /**
     * Bulk delete tax categories
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array'],
            'ids.*' => ['exists:tax_categories,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if any tax category is being used
        // Uncomment if you have a relationship with products
        // $usedCategories = TaxCategory::whereIn('id', $request->ids)
        //     ->whereHas('products')
        //     ->pluck('id');
        //
        // if ($usedCategories->isNotEmpty()) {
        //     return response()->json([
        //         'message' => 'Cannot delete tax categories that are being used by products',
        //         'used_ids' => $usedCategories
        //     ], 409);
        // }

        TaxCategory::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Tax categories deleted successfully',
        ], 200);
    }

    /**
     * Get tax category statistics
     */
    public function stats()
    {
        $stats = [
            'total' => TaxCategory::count(),
            'average_rate' => TaxCategory::avg('rate'),
            'highest_rate' => TaxCategory::max('rate'),
            'lowest_rate' => TaxCategory::min('rate'),
        ];

        return response()->json($stats);
    }

    /**
     * Format a single tax category
     */
    protected function formatTaxCategory($taxCategory)
    {
        return [
            'id' => $taxCategory->id,
            'name' => $taxCategory->name,
            'rate' => (float) $taxCategory->rate,
            'rate_formatted' => number_format($taxCategory->rate, 2) . '%',
            'created_at' => $taxCategory->created_at ? $taxCategory->created_at->toISOString() : null,
            'updated_at' => $taxCategory->updated_at ? $taxCategory->updated_at->toISOString() : null,
        ];
    }

    /**
     * Format tax category collection
     */
    protected function formatTaxCategoryCollection($taxCategories)
    {
        $formatted = [];
        foreach ($taxCategories as $taxCategory) {
            $formatted[] = $this->formatTaxCategory($taxCategory);
        }
        return $formatted;
    }
}
