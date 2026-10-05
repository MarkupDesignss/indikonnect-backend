<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WarehouseStockController extends Controller
{
        // ============================================================
    // LIST ALL (with filters + pagination)
    // GET /api/warehouse-stocks
    // ============================================================
    public function index(Request $request): JsonResponse
    {
        $query = WarehouseStock::with(['warehouse', 'product', 'variant']);

        // Filter by warehouse_id
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        // Filter by product_id
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by variant_id
        if ($request->filled('variant_id')) {
            $query->where('variant_id', $request->variant_id);
        }

        // Search by product name / code / sku
        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->whereHas('product', function ($pq) use ($search) {
                    $pq->where('name', 'LIKE', "%{$search}%")
                       ->orWhere('product_code', 'LIKE', "%{$search}%");
                })->orWhereHas('variant', function ($vq) use ($search) {
                    $vq->where('sku', 'LIKE', "%{$search}%");
                });
            });
        }

        // Low stock filter
        if ($request->filled('low_stock') && $request->boolean('low_stock')) {
            $threshold = (int) $request->get('low_stock_threshold', 10);
            $query->where('quantity', '>', 0)->where('quantity', '<=', $threshold);
        }

        // Out of stock
        if ($request->filled('out_of_stock') && $request->boolean('out_of_stock')) {
            $query->where('quantity', 0);
        }

        // In stock
        if ($request->filled('in_stock') && $request->boolean('in_stock')) {
            $query->where('quantity', '>', 0);
        }

        // Sorting
        [$sortField, $sortDirection] = $this->resolveSort($request);

        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $this->resolvePerPage($request);

        $stocks = $query->paginate($perPage);

        return response()->json([
            'data' => $this->formatCollection($stocks->getCollection()),
            'pagination' => $this->paginationMeta($stocks),
        ]);
    }

    // ============================================================
    // GET STOCKS BY WAREHOUSE ID
    // GET /api/warehouses/{warehouseId}/stocks
    // ============================================================
    public function byWarehouse(Request $request, int $warehouseId): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        $query = WarehouseStock::with(['product', 'variant'])
            ->where('warehouse_id', $warehouseId);

        // Only in-stock items
        if ($request->filled('in_stock') && $request->boolean('in_stock')) {
            $query->where('quantity', '>', 0);
        }

        // Out of stock only
        if ($request->filled('out_of_stock') && $request->boolean('out_of_stock')) {
            $query->where('quantity', 0);
        }

        // Low stock
        if ($request->filled('low_stock') && $request->boolean('low_stock')) {
            $threshold = (int) $request->get('low_stock_threshold', 10);
            $query->where('quantity', '>', 0)->where('quantity', '<=', $threshold);
        }

        // Search
        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->whereHas('product', function ($pq) use ($search) {
                    $pq->where('name', 'LIKE', "%{$search}%")
                       ->orWhere('product_code', 'LIKE', "%{$search}%");
                })->orWhereHas('variant', function ($vq) use ($search) {
                    $vq->where('sku', 'LIKE', "%{$search}%");
                });
            });
        }

        // Sorting
        [$sortField, $sortDirection] = $this->resolveSort($request);

        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $this->resolvePerPage($request);

        $stocks = $query->paginate($perPage);

        // Summary (computed separately so it's not affected by pagination)
        $baseQuery = WarehouseStock::where('warehouse_id', $warehouseId);

        $summary = [
            'total_items'    => (clone $baseQuery)->count(),
            'total_quantity' => (int) (clone $baseQuery)->sum('quantity'),
            'out_of_stock'   => (clone $baseQuery)->where('quantity', 0)->count(),
            'low_stock'      => (clone $baseQuery)->where('quantity', '>', 0)
                                                    ->where('quantity', '<=', 10)
                                                    ->count(),
        ];

        return response()->json([
            'warehouse' => [
                'id'   => $warehouse->id,
                'name' => $warehouse->name ?? null,
            ],
            'summary' => $summary,
            'data'    => $this->formatCollection($stocks->getCollection()),
            'pagination' => $this->paginationMeta($stocks),
        ]);
    }

    // ============================================================
    // SHOW SINGLE
    // GET /api/warehouse-stocks/{id}
    // ============================================================
    public function show(int $id): JsonResponse
    {
        $stock = WarehouseStock::with(['warehouse', 'product', 'variant'])->findOrFail($id);

        return response()->json([
            'data' => $this->formatSingle($stock),
        ]);
    }

    // ============================================================
    // STORE
    // POST /api/warehouse-stocks
    // ============================================================
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'product_id'   => ['required', 'exists:products,id'],
            'variant_id'   => ['nullable', 'exists:product_variants,id'],
            'quantity'     => ['required', 'integer', 'min:0'],
        ]);

        // Prevent duplicate (warehouse + product + variant)
        $exists = WarehouseStock::where('warehouse_id', $validated['warehouse_id'])
            ->where('product_id', $validated['product_id'])
            ->where('variant_id', $validated['variant_id'] ?? null)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This warehouse/product/variant combination already exists.',
            ], 422);
        }

        $stock = WarehouseStock::create($validated);
        $stock->load(['warehouse', 'product', 'variant']);

        return response()->json([
            'message' => 'Warehouse stock created successfully.',
            'data'    => $this->formatSingle($stock),
        ], 201);
    }

    // ============================================================
    // UPDATE
    // PUT/PATCH /api/warehouse-stocks/{id}
    // ============================================================
    public function update(Request $request, int $id): JsonResponse
    {
        $stock = WarehouseStock::findOrFail($id);

        $validated = $request->validate([
            'warehouse_id' => ['sometimes', 'required', 'exists:warehouses,id'],
            'product_id'   => ['sometimes', 'required', 'exists:products,id'],
            'variant_id'   => ['nullable', 'exists:product_variants,id'],
            'quantity'     => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        // Resolve final values for uniqueness check
        $warehouseId = $validated['warehouse_id'] ?? $stock->warehouse_id;
        $productId   = $validated['product_id']   ?? $stock->product_id;
        $variantId   = array_key_exists('variant_id', $validated)
            ? $validated['variant_id']
            : $stock->variant_id;

        $duplicate = WarehouseStock::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->where('id', '!=', $stock->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Another stock entry already uses this warehouse/product/variant combination.',
            ], 422);
        }

        $stock->update($validated);
        $stock->load(['warehouse', 'product', 'variant']);

        return response()->json([
            'message' => 'Warehouse stock updated successfully.',
            'data'    => $this->formatSingle($stock),
        ]);
    }

    // ============================================================
    // DELETE
    // DELETE /api/warehouse-stocks/{id}
    // ============================================================
    public function destroy(int $id): JsonResponse
    {
        $stock = WarehouseStock::findOrFail($id);
        $stock->delete();

        return response()->json([
            'message' => 'Warehouse stock deleted successfully.',
        ]);
    }

    // ============================================================
    // BULK UPSERT
    // POST /api/warehouse-stocks/bulk
    // ============================================================
    public function bulkUpsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id'       => ['required', 'exists:warehouses,id'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity'   => ['required', 'integer', 'min:0'],
        ]);

        $warehouseId = $validated['warehouse_id'];
        $items = $validated['items'];

        $upserted = DB::transaction(function () use ($warehouseId, $items) {
            $results = [];

            foreach ($items as $item) {
                $results[] = WarehouseStock::updateOrCreate(
                    [
                        'warehouse_id' => $warehouseId,
                        'product_id'   => $item['product_id'],
                        'variant_id'   => $item['variant_id'] ?? null,
                    ],
                    [
                        'quantity' => $item['quantity'],
                    ]
                );
            }

            return $results;
        });

        // Reload with relations
        $ids = collect($upserted)->pluck('id')->all();
        $fresh = WarehouseStock::with(['warehouse', 'product', 'variant'])
            ->whereIn('id', $ids)
            ->get();

        return response()->json([
            'message' => 'Warehouse stocks upserted successfully.',
            'count'   => $fresh->count(),
            'data'    => $this->formatCollection($fresh),
        ]);
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Resolve sort field + direction from request.
     */
    protected function resolveSort(Request $request): array
    {
        $allowedFields = [
            'id', 'warehouse_id', 'product_id', 'variant_id',
            'quantity', 'created_at', 'updated_at',
        ];

        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = strtolower($request->get('sort_direction', 'desc'));

        if (!in_array($sortField, $allowedFields, true)) {
            $sortField = 'created_at';
        }

        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        return [$sortField, $sortDirection];
    }

    /**
     * Resolve per-page with sane bounds.
     */
    protected function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->get('per_page', 25);

        if ($perPage < 1) {
            $perPage = 25;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        return $perPage;
    }

    /**
     * Format a single stock entry.
     */
    protected function formatSingle(WarehouseStock $stock): array
    {
        return [
            'id'           => $stock->id,
            'warehouse_id' => $stock->warehouse_id,
            'product_id'   => $stock->product_id,
            'variant_id'   => $stock->variant_id,
            'quantity'     => $stock->quantity,

            'warehouse' => $stock->relationLoaded('warehouse') && $stock->warehouse ? [
                'id'   => $stock->warehouse->id,
                'name' => $stock->warehouse->name ?? null,
            ] : null,

            'product' => $stock->relationLoaded('product') && $stock->product ? [
                'id'           => $stock->product->id,
                'name'         => $stock->product->name ?? null,
                'product_code' => $stock->product->product_code ?? null,
            ] : null,

            'variant' => $stock->relationLoaded('variant') && $stock->variant ? [
                'id'  => $stock->variant->id,
                'sku' => $stock->variant->sku ?? null,
            ] : null,

            'created_at' => optional($stock->created_at)->toDateTimeString(),
            'updated_at' => optional($stock->updated_at)->toDateTimeString(),
        ];
    }

    /**
     * Format a collection of stock entries.
     */
    protected function formatCollection($collection): array
    {
        return $collection->map(fn ($stock) => $this->formatSingle($stock))->values()->all();
    }

    /**
     * Standard pagination metadata.
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }
}