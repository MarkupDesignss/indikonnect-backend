<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class WarehouseStockController extends Controller
{
    // ============================================================
    // LIST ALL (with filters + pagination)
    // GET /api/warehouse-stocks
    // ============================================================
    public function index(Request $request, int $warehouseId)
    {
        // ============================================================
        // WAREHOUSE
        // ============================================================

        $warehouse = Warehouse::findOrFail($warehouseId);


        // ============================================================
        // BASE QUERY (scoped to warehouse)
        // ============================================================

        $query = Product::with([
            'category',
            'subcategory',
            'taxCategory',
            'brand',
            'images',
            'variants',
        ])
            ->whereHas('brand', function ($q) {
                $q->where('status', true);
            })
            // Only products that exist in this warehouse
            ->whereHas('warehouseStocks', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });


        // ============================================================
        // CATEGORY FILTER
        // ============================================================

        if ($request->has('category_ids') && $request->category_ids) {

            $categoryIds = is_array($request->category_ids)
                ? $request->category_ids
                : explode(',', $request->category_ids);

            $categoryIds = array_filter($categoryIds);

            if (!empty($categoryIds)) {
                $query->whereIn('category_id', $categoryIds);
            }
        }


        // ============================================================
        // SUBCATEGORY FILTER
        // ============================================================

        if ($request->has('subcategory_ids') && $request->subcategory_ids) {

            $subcategoryIds = is_array($request->subcategory_ids)
                ? $request->subcategory_ids
                : explode(',', $request->subcategory_ids);

            $subcategoryIds = array_filter($subcategoryIds);

            if (!empty($subcategoryIds)) {
                $query->whereIn('subcategory_id', $subcategoryIds);
            }
        }


        // ============================================================
        // SINGLE CATEGORY FILTER (backward compatibility)
        // ============================================================

        if (
            $request->has('category_id') &&
            $request->category_id &&
            !$request->has('category_ids')
        ) {
            $query->where('category_id', $request->category_id);
        }


        // ============================================================
        // BRAND FILTER
        // ============================================================

        if ($request->has('brand_ids') && $request->brand_ids) {

            $brandIds = is_array($request->brand_ids)
                ? $request->brand_ids
                : explode(',', $request->brand_ids);

            $brandIds = array_filter($brandIds);

            if (!empty($brandIds)) {
                $query->whereIn('brand_id', $brandIds);
            }
        }


        // ============================================================
        // TAX CATEGORY FILTER
        // ============================================================

        if ($request->has('tax_category_ids') && $request->tax_category_ids) {

            $taxIds = is_array($request->tax_category_ids)
                ? $request->tax_category_ids
                : explode(',', $request->tax_category_ids);

            $taxIds = array_filter($taxIds);

            if (!empty($taxIds)) {
                $query->whereIn('tax_category_id', $taxIds);
            }
        }


        // ============================================================
        // PRICE FILTER
        // ============================================================

        if (
            $request->has('min_price') &&
            $request->min_price !== null &&
            $request->min_price !== '' &&
            is_numeric($request->min_price)
        ) {
            $query->where('retail_price', '>=', $request->min_price);
        }

        if (
            $request->has('max_price') &&
            $request->max_price !== null &&
            $request->max_price !== '' &&
            is_numeric($request->max_price)
        ) {
            $query->where('retail_price', '<=', $request->max_price);
        }


        // ============================================================
        // PUBLISHED STATUS FILTER
        // ============================================================

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }


        // ============================================================
        // WAREHOUSE STOCK STATUS FILTER
        // ============================================================

        if ($request->has('stock_status') && $request->stock_status) {

            $stockStatus = $request->stock_status;

            $lowThreshold = (int) $request->get('low_stock_threshold', 10);

            if (is_array($stockStatus)) {

                $query->where(function ($q) use ($stockStatus, $warehouseId, $lowThreshold) {

                    if (in_array('in_stock', $stockStatus)) {
                        $q->orWhereHas('warehouseStocks', function ($wq) use ($warehouseId, $lowThreshold) {
                            $wq->where('warehouse_id', $warehouseId)
                                ->where('quantity', '>', $lowThreshold);
                        });
                    }

                    if (in_array('low_stock', $stockStatus)) {
                        $q->orWhereHas('warehouseStocks', function ($wq) use ($warehouseId, $lowThreshold) {
                            $wq->where('warehouse_id', $warehouseId)
                                ->where('quantity', '>', 0)
                                ->where('quantity', '<=', $lowThreshold);
                        });
                    }

                    if (in_array('out_of_stock', $stockStatus)) {
                        $q->orWhereHas('warehouseStocks', function ($wq) use ($warehouseId) {
                            $wq->where('warehouse_id', $warehouseId)
                                ->where('quantity', 0);
                        });
                    }
                });
            } else {

                switch ($stockStatus) {

                    case 'in_stock':
                        $query->whereHas('warehouseStocks', function ($wq) use ($warehouseId, $lowThreshold) {
                            $wq->where('warehouse_id', $warehouseId)
                                ->where('quantity', '>', $lowThreshold);
                        });
                        break;

                    case 'low_stock':
                        $query->whereHas('warehouseStocks', function ($wq) use ($warehouseId, $lowThreshold) {
                            $wq->where('warehouse_id', $warehouseId)
                                ->where('quantity', '>', 0)
                                ->where('quantity', '<=', $lowThreshold);
                        });
                        break;

                    case 'out_of_stock':
                        $query->whereHas('warehouseStocks', function ($wq) use ($warehouseId) {
                            $wq->where('warehouse_id', $warehouseId)
                                ->where('quantity', 0);
                        });
                        break;
                }
            }
        }


        // ============================================================
        // SEARCH
        // ============================================================

        if ($request->has('search') && $request->search) {

            $search = trim($request->search);

            $query->where(function ($q) use ($search) {

                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('product_code', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhereHas('variants', function ($variantQuery) use ($search) {
                        $variantQuery->where('sku', 'LIKE', "%{$search}%");
                    });
            });
        }


        // ============================================================
        // SORT
        // ============================================================

        // In-stock (in this warehouse) first, out-of-stock last
        $query->orderByRaw(
            'CASE
            WHEN (SELECT COALESCE(SUM(quantity), 0)
                  FROM warehouse_stocks
                  WHERE warehouse_stocks.product_id = products.id
                    AND warehouse_stocks.warehouse_id = ?) = 0
            THEN 1 ELSE 0 END ASC',
            [$warehouseId]
        );

        $sortParam = $request->get('sort');

        if ($sortParam === 'price-low') {

            $query->orderBy('retail_price', 'asc');
        } elseif ($sortParam === 'price-high') {

            $query->orderBy('retail_price', 'desc');
        } else {

            $sortField = $request->get('sort_by', 'created_at');
            $sortDirection = strtolower($request->get('sort_direction', 'desc'));

            $allowedSortFields = [
                'id',
                'name',
                'product_code',
                'retail_price',
                'distributor_price',
                'created_at',
                'updated_at',
            ];

            if (!in_array($sortField, $allowedSortFields)) {
                $sortField = 'created_at';
            }

            if (!in_array($sortDirection, ['asc', 'desc'])) {
                $sortDirection = 'desc';
            }

            $query->orderBy($sortField, $sortDirection);
        }


        // ============================================================
        // PAGINATION
        // ============================================================

        $perPage = (int) $request->get('per_page', 25);

        if ($perPage < 1) {
            $perPage = 25;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $products = $query->paginate($perPage);


        // ============================================================
        // WAREHOUSE STOCK MAP (for this page)
        // ============================================================

        $productIds = $products->pluck('id')->all();

        $warehouseStockMap = WarehouseStock::where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');


        // ============================================================
        // FORMAT (admin-friendly, no reviews / wishlist)
        // ============================================================

        $formatted = $products->getCollection()->map(function ($product) use ($warehouseStockMap, $warehouseId) {

            $stocks = $warehouseStockMap->get($product->id, collect());

            $totalQuantity = (int) $stocks->sum('quantity');

            $stockStatus = $totalQuantity <= 0
                ? 'out_of_stock'
                : ($totalQuantity <= (int) $product->low_stock_threshold ? 'low_stock' : 'in_stock');

            return [
                'id'           => $product->id,
                'product_code' => $product->product_code,
                'name'         => $product->name,
                'slug'         => $product->slug,
                'description'  => $product->description,
                'specification' => $product->specification,

                'brand_id'     => $product->brand_id,
                'brand'        => $product->brand ? [
                    'id'     => $product->brand->id,
                    'title'  => $product->brand->title,
                    'slug'   => $product->brand->slug ?? null,
                    'logo'   => $product->brand->logo
                        ? asset('storage/' . $product->brand->logo)
                        : null,
                    'banner' => $product->brand->banner
                        ? asset('storage/' . $product->brand->banner)
                        : null,
                    'status' => (bool) $product->brand->status,
                ] : null,

                'category_id'  => $product->category_id,
                'category'     => $product->category ? [
                    'id'    => $product->category->id,
                    'title' => $product->category->title,
                    'slug'  => $product->category->slug,
                ] : null,

                'subcategory_id' => $product->subcategory_id,
                'subcategory'    => $product->subcategory ? [
                    'id'          => $product->subcategory->id,
                    'category_id' => $product->subcategory->category_id,
                    'name'        => $product->subcategory->name,
                    'slug'        => $product->subcategory->slug,
                ] : null,

                'tax_category_id' => $product->tax_category_id,
                'tax_category'    => $product->taxCategory ? [
                    'id'   => $product->taxCategory->id,
                    'name' => $product->taxCategory->name,
                    'rate' => $product->taxCategory->rate,
                ] : null,

                // Pricing
                'retail_mrp'           => $product->retail_mrp,
                'retail_price'         => $product->retail_price,
                'distributor_mrp'      => $product->distributor_mrp,
                'distributor_price'    => $product->distributor_price,
                'commission_value'     => $product->commission_value ?? null,

                // Flags
                'is_published'         => (bool) $product->is_published,
                'is_trending'          => (bool) $product->is_trending,
                'is_deal_of_the_day'   => (bool) $product->is_deal_of_the_day,
                'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                'deal_of_the_day_ends_at'   => $product->deal_of_the_day_ends_at?->toISOString(),
                'sale_type'            => $product->sale_type,

                // Product-level stock (global)
                'stock_quantity'       => (int) $product->stock_quantity,
                'low_stock_threshold'  => (int) $product->low_stock_threshold,

                // Warehouse-specific stock
                'warehouse_stock' => [
                    'warehouse_id'   => $warehouseId,
                    'total_quantity' => $totalQuantity,
                    'stock_status'   => $stockStatus,
                    'entries'        => $stocks->map(function ($s) {
                        return [
                            'id'         => $s->id,
                            'variant_id' => $s->variant_id,
                            'quantity'   => (int) $s->quantity,
                        ];
                    })->values()->all(),
                ],

                // Images
                'images' => $product->images->map(function ($image) {
                    return [
                        'id'         => $image->id,
                        'image_url'  => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                        'sort_order' => (int) $image->sort_order,
                    ];
                })->values()->all(),

                'primary_image_url' => optional(
                    $product->images->where('is_primary', true)->first()
                        ?? $product->images->first()
                )->image
                    ? asset('storage/' . (
                        $product->images->where('is_primary', true)->first()->image
                        ?? $product->images->first()->image
                    ))
                    : null,

                // Variants
                'variants' => $product->variants->map(function ($variant) {
                    $attributes = $variant->attributes;
                    if (is_string($attributes)) {
                        $attributes = json_decode($attributes, true);
                    }

                    return [
                        'id'                        => $variant->id,
                        'product_id'                => $variant->product_id,
                        'sku'                       => $variant->sku,
                        'attributes'                => $attributes,
                        'retail_price'              => $variant->retail_price,
                        'retail_mrp'                => $variant->retail_mrp,
                        'distributor_price'         => $variant->distributor_price,
                        'distributor_mrp'           => $variant->distributor_mrp,
                        'stock_quantity'            => (int) $variant->stock_quantity,
                        'low_stock_threshold'       => (int) $variant->low_stock_threshold,
                        'sort_order'                => (int) $variant->sort_order,
                        'is_active'                 => (bool) $variant->is_active,
                    ];
                })->values()->all(),

                'created_at' => optional($product->created_at)->toDateTimeString(),
                'updated_at' => optional($product->updated_at)->toDateTimeString(),
            ];
        })->values()->all();


        // ============================================================
        // RESPONSE
        // ============================================================

        return response()->json([

            'warehouse' => [
                'id'   => $warehouse->id,
                'name' => $warehouse->name ?? null,
            ],

            'data' => $formatted,

            'pagination' => [
                'total'        => $products->total(),
                'per_page'     => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'from'         => $products->firstItem(),
                'to'           => $products->lastItem(),
            ],

            'meta' => [
                'warehouse_id' => $warehouseId,
                'sort'         => $sortParam,
            ],
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
            'id',
            'warehouse_id',
            'product_id',
            'variant_id',
            'quantity',
            'created_at',
            'updated_at',
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
        return $collection->map(fn($stock) => $this->formatSingle($stock))->values()->all();
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

    public function updateStock(Request $request, int $warehouseId)
    {
        try {
            // ============ VALIDATION ============
            $validator = \Illuminate\Support\Facades\Validator::make(
                $request->all(),
                [
                    'product_id' => 'required|exists:products,id',

                    // Optional: variant-wise updates
                    'variants' => 'nullable|array',
                    'variants.*.id' => 'required_with:variants|exists:product_variants,id',
                    'variants.*.quantity' => 'required_with:variants|integer|min:0',

                    // Optional: direct product (non-variant) update
                    'quantity' => 'nullable|integer|min:0',

                    // Operation type
                    'operation' => 'required|in:set,add,subtract',
                ],
                [
                    'product_id.required' => 'Product ID is required',
                    'product_id.exists' => 'Product not found',
                    'variants.*.id.exists' => 'One or more variants not found',
                    'variants.*.quantity.min' => 'Variant quantity cannot be negative',
                    'quantity.min' => 'Quantity cannot be negative',
                    'operation.required' => 'Operation is required',
                    'operation.in' => 'Operation must be set, add, or subtract',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            $warehouse = Warehouse::findOrFail($warehouseId);
            $product = Product::findOrFail($validated['product_id']);

            $operation = $validated['operation'];

            DB::beginTransaction();

            // ============================================================
            // CASE 1: VARIANT-WISE UPDATE
            // ============================================================
            if (isset($validated['variants']) && !empty($validated['variants'])) {

                $variantsData = $validated['variants'];
                $variantIds = collect($variantsData)->pluck('id')->toArray();

                // Verify all variants belong to this product
                $existingVariants = ProductVariant::whereIn('id', $variantIds)
                    ->where('product_id', $product->id)
                    ->get()
                    ->keyBy('id');

                if ($existingVariants->count() != count($variantsData)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more variants do not belong to this product',
                    ], 400);
                }

                $updatedVariants = [];

                foreach ($variantsData as $variantData) {

                    $variant = $existingVariants[$variantData['id']];
                    $quantity = $variantData['quantity'];

                    // ----------------------------------------------------
                    // 1. Warehouse stock row (may or may not exist)
                    // ----------------------------------------------------
                    $warehouseStock = WarehouseStock::firstOrCreate(
                        [
                            'warehouse_id' => $warehouseId,
                            'product_id'   => $product->id,
                            'variant_id'   => $variant->id,
                        ],
                        [
                            'quantity' => 0,
                        ]
                    );

                    $oldWarehouseQty = $warehouseStock->quantity;

                    $newWarehouseQty = $this->applyOperation($oldWarehouseQty, $quantity, $operation);

                    if ($newWarehouseQty < 0) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Warehouse stock cannot be negative for variant ID {$variant->id}. Current: {$oldWarehouseQty}, Operation: {$operation}, Quantity: {$quantity}",
                        ], 400);
                    }

                    $warehouseStock->quantity = $newWarehouseQty;
                    $warehouseStock->save();

                    // ----------------------------------------------------
                    // 2. Variant global stock
                    // ----------------------------------------------------
                    $oldVariantQty = $variant->stock_quantity;

                    $newVariantQty = $this->applyOperation($oldVariantQty, $quantity, $operation);

                    if ($newVariantQty < 0) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Variant stock cannot be negative for variant ID {$variant->id}. Current: {$oldVariantQty}, Operation: {$operation}, Quantity: {$quantity}",
                        ], 400);
                    }

                    $variant->stock_quantity = $newVariantQty;
                    $variant->save();

                    $updatedVariants[] = [
                        'id'                 => $variant->id,
                        'sku'                => $variant->sku,
                        'attributes'         => $variant->attributes,
                        'operation'          => $operation,
                        'quantity'           => $quantity,
                        'warehouse_old_qty'  => $oldWarehouseQty,
                        'warehouse_new_qty'  => $newWarehouseQty,
                        'variant_old_stock'  => $oldVariantQty,
                        'variant_new_stock'  => $newVariantQty,
                    ];
                }

                // --------------------------------------------------------
                // 3. Recalculate parent product global stock (sum of variants)
                // --------------------------------------------------------
                $totalProductStock = ProductVariant::where('product_id', $product->id)
                    ->sum('stock_quantity');

                $product->stock_quantity = (int) $totalProductStock;
                $product->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Variant stocks updated successfully',
                    'data' => [
                        'warehouse' => [
                            'id'   => $warehouse->id,
                            'name' => $warehouse->name ?? null,
                        ],
                        'product' => [
                            'id'           => $product->id,
                            'name'         => $product->name,
                            'total_stock'  => (int) $product->stock_quantity,
                            'has_variants' => true,
                        ],
                        'operation'        => $operation,
                        'total_updated'    => count($updatedVariants),
                        'updated_variants' => $updatedVariants,
                    ],
                    'timestamp' => now()->toISOString(),
                ]);
            }

            // ============================================================
            // CASE 2: NON-VARIANT PRODUCT UPDATE
            // ============================================================
            if (!isset($validated['quantity'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Quantity is required when updating product directly',
                ], 400);
            }

            $quantity = $validated['quantity'];

            // ------------------------------------------------------------
            // 1. Warehouse stock row
            // ------------------------------------------------------------
            $warehouseStock = WarehouseStock::firstOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'product_id'   => $product->id,
                    'variant_id'   => null,
                ],
                [
                    'quantity' => 0,
                ]
            );

            $oldWarehouseQty = $warehouseStock->quantity;

            $newWarehouseQty = $this->applyOperation($oldWarehouseQty, $quantity, $operation);

            if ($newWarehouseQty < 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Warehouse stock cannot be negative. Current: {$oldWarehouseQty}, Operation: {$operation}, Quantity: {$quantity}",
                ], 400);
            }

            $warehouseStock->quantity = $newWarehouseQty;
            $warehouseStock->save();

            // ------------------------------------------------------------
            // 2. Parent product global stock
            // ------------------------------------------------------------
            $oldProductQty = $product->stock_quantity;

            $newProductQty = $this->applyOperation($oldProductQty, $quantity, $operation);

            if ($newProductQty < 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Product stock cannot be negative. Current: {$oldProductQty}, Operation: {$operation}, Quantity: {$quantity}",
                ], 400);
            }

            $product->stock_quantity = $newProductQty;
            $product->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product stock updated successfully',
                'data' => [
                    'warehouse' => [
                        'id'   => $warehouse->id,
                        'name' => $warehouse->name ?? null,
                    ],
                    'product' => [
                        'id'          => $product->id,
                        'name'        => $product->name,
                        'old_stock'   => $oldProductQty,
                        'new_stock'   => $newProductQty,
                        'operation'   => $operation,
                        'quantity'    => $quantity,
                        'has_variants' => $product->variants()->count() > 0,
                    ],
                    'warehouse_stock' => [
                        'old_quantity' => $oldWarehouseQty,
                        'new_quantity' => $newWarehouseQty,
                    ],
                ],
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Warehouse stock update failed: ' . $e->getMessage(), [
                'trace'        => $e->getTraceAsString(),
                'warehouse_id' => $warehouseId,
                'request'      => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }


    // ================================================================
    // HELPER: Apply operation (set / add / subtract)
    // ================================================================
    protected function applyOperation(int $current, int $value, string $operation): int
    {
        switch ($operation) {
            case 'add':
                return $current + $value;

            case 'subtract':
                return $current - $value;

            case 'set':
            default:
                return $value;
        }
    }
}