<?php

namespace App\Services;

use App\Models\Product;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Support\Facades\Log;

class IgniteProductService
{
    public function __construct(
        protected IgniteClient $ignite,
    ) {}

    /**
     * Sync a local product to Ignite in one shot:
     *   1. Create or update the product itself
     *   2. Push its pricing into the Ignite catalog (may go to approval queue)
     *
     * Never throws — logs failures and returns a structured status.
     */
    public function syncProduct(Product $product): array
    {
        if (empty($product->product_code)) {
            return [
                'product' => 'skipped',
                'catalog' => 'skipped',
                'warning' => 'Product has no product_code.',
            ];
        }

        $result = [
            'product' => null,
            'catalog' => null,
        ];

        // -------------------------------------------------------------
        // STEP 1 — product itself
        // -------------------------------------------------------------
        $result['product'] = $this->syncProductRecord($product);

        if (!$product->ignite_product_id) {
            $result['catalog'] = 'skipped:product_not_synced';
            if ($result['product'] === 'unreachable') {
                $result['warning'] = 'Ignite unreachable — product not synced. Retry later.';
            }
            return $result;
        }

        // -------------------------------------------------------------
        // STEP 2 — catalog pricing
        // -------------------------------------------------------------
        $catalog = $this->syncCatalogPricing($product);

        $result['catalog']            = $catalog['status'];
        $result['catalog_request_id'] = $catalog['request_id'] ?? null;

        if (!empty($catalog['errors'])) {
            $result['catalog_errors'] = $catalog['errors'];
        }

        if ($catalog['status'] === 'unreachable') {
            $result['warning'] = 'Product synced to Ignite, but catalog pricing could not be pushed. Retry later.';
        }

        return $result;
    }

    /**
     * Create or update the Ignite product record.
     * Returns: created | already_exists | updated | refused:<code> | unreachable
     */
    protected function syncProductRecord(Product $product): string
    {
        $payload = [
            // SDK comment #1: partner_product_ref is the primary mapping key.
            'partner_product_ref' => $product->product_code,
            'name'                => $product->name,
            'title_en'            => $product->name,
            'desc_en'             => $product->description,
            'is_physical'         => (bool) ($product->is_physical ?? false),
            'weight'              => $product->is_physical
                ? (string) ($product->weight ?? '0.000')
                : null,
            'is_visible'          => (bool) $product->is_published,
            'sort_order'          => (int) ($product->sort_order ?? 0),
        ];

        try {
            if ($product->ignite_product_id) {
                $res = $this->ignite->updateProduct($product->ignite_product_id, $payload);

                if ($res['code'] === ResultCode::OK->value) {
                    return 'updated';
                }

                if ($res['code'] === ResultCode::PRODUCT_NOT_FOUND->value) {
                    return $this->createProductRecord($product, $payload);
                }

                Log::warning('Ignite updateProduct refused', [
                    'product_id' => $product->id,
                    'code'       => $res['code'],
                    'msg'        => $res['msg'],
                    'data'       => $res['data'] ?? null,
                ]);

                return 'refused:' . $res['code'];
            }

            return $this->createProductRecord($product, $payload);

        } catch (\Throwable $e) {
            Log::error('Ignite unreachable during product sync', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);

            return 'unreachable';
        }
    }

    /**
     * createProduct is idempotent on partner_product_ref —
     * a repeat returns the existing product.
     */
    protected function createProductRecord(Product $product, array $payload): string
    {
        $res = $this->ignite->createProduct($payload);

        if ($res['code'] === ResultCode::OK->value) {
            $product->update([
                'ignite_product_id' => $res['data']['product_id'],
            ]);

            return $res['data']['created'] ? 'created' : 'already_exists';
        }

        Log::warning('Ignite createProduct refused', [
            'product_id' => $product->id,
            'code'       => $res['code'],
            'msg'        => $res['msg'],
            'data'       => $res['data'] ?? null,
        ]);

        return 'refused:' . $res['code'];
    }

    /**
     * Push the product's price / CV into the Ignite catalog.
     *
     * Fix: we now look up existing catalog_item_id via catalogLines()
     * and pass it in the payload. If missing, we pass product_id to
     * create a new line — otherwise Ignite refuses with 7002
     * (partner_line_ref already used).
     */
    protected function syncCatalogPricing(Product $product): array
    {
        $batchRef = 'PB-' . $product->product_code . '-' . now()->format('YmdHis');
        $lineRef  = 'CL-' . $product->product_code;

        // -----------------------------------------------------------------
        // FIX: resolve existing catalog_item_id for this partner_line_ref
        // -----------------------------------------------------------------
        $existingCatalogItemId = $this->findExistingCatalogItemId($lineRef);

        $line = [
            'partner_line_ref' => $lineRef,
            'retail_price'     => $this->fmtAmount($product->retail_price),
            'member_price'     => $this->fmtAmount($product->distributor_price ?? $product->retail_price),
            'cv'               => $this->fmtAmount($product->commission_value ?? 0),
            'green_coin'       => $this->fmtAmount($product->green_coin ?? 0),
            'blue_coin'        => $this->fmtAmount($product->blue_coin ?? 0),
            'visible_start_at' => null,
            'visible_end_at'   => null,
            'is_enabled'       => (bool) $product->is_published,
        ];

        if ($existingCatalogItemId) {
            // Update existing line — DO NOT send product_id
            $line['catalog_item_id'] = $existingCatalogItemId;
        } else {
            // First-time create for this line — send product_id
            $line['product_id'] = (int) $product->ignite_product_id;
        }

        try {
            $res = $this->ignite->saveCatalogLines([$line], $batchRef);
        } catch (\Throwable $e) {
            Log::error('Ignite unreachable during catalog sync', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);
            return ['status' => 'unreachable'];
        }

        if ($res['code'] === ResultCode::OK->value) {
            return [
                'status'     => $res['data']['status'] ?? 'approved',
                'request_id' => $res['data']['request_id'] ?? null,
            ];
        }

        if ($res['code'] === ResultCode::CATALOG_LINES_REFUSED->value) {
            Log::warning('Ignite catalog lines refused', [
                'product_id' => $product->id,
                'code'       => $res['code'],
                'msg'        => $res['msg'],
                'data'       => $res['data'] ?? null,
            ]);

            return [
                'status' => 'refused',
                'errors' => $res['data']['lines'] ?? [],
            ];
        }

        Log::warning('Ignite saveCatalogLines refused', [
            'product_id' => $product->id,
            'code'       => $res['code'],
            'msg'        => $res['msg'],
        ]);

        return ['status' => 'refused'];
    }

    /**
     * Look up an existing catalog_item_id by partner_line_ref.
     * Returns null if none — meaning we should create a new line.
     */
    protected function findExistingCatalogItemId(string $lineRef): ?int
    {
        try {
            $res = $this->ignite->catalogLines();

            if (($res['code'] ?? null) !== ResultCode::OK->value) {
                Log::warning('catalogLines() refused during catalog sync lookup', [
                    'line_ref' => $lineRef,
                    'code'     => $res['code'] ?? null,
                    'msg'      => $res['msg'] ?? null,
                ]);
                return null;
            }

            $existing = collect($res['data']['lines'] ?? [])
                ->firstWhere('partner_line_ref', $lineRef);

            return $existing ? (int) $existing['catalog_item_id'] : null;

        } catch (\Throwable $e) {
            Log::warning('catalogLines() threw during catalog sync lookup', [
                'line_ref' => $lineRef,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Ignite amounts are decimal strings with 4 places: "120.5000"
     */
    protected function fmtAmount($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}