<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Exception;

class StockGuardService
{
    /**
     * Resolve the "allowed purchased" flag for a product/variant.
     *
     * NOTE: The DB column is spelled `allowed_purchsed` (typo in schema).
     * We check both spellings so this keeps working after a future rename.
     */
    public function isUnlimited(Product $product, ?ProductVariant $variant = null): bool
    {
        if ($variant) {
            $variantFlag = $this->readFlag($variant);
            if ($variantFlag !== null) {
                return $variantFlag;
            }
        }

        return $this->readFlag($product) ?? false;
    }

    /**
     * Read the flag from a model supporting either spelling.
     * Returns null if the attribute isn't present at all.
     */
    private function readFlag($model): ?bool
    {
        if (!$model) {
            return null;
        }

        // Preferred correct spelling
        if (array_key_exists('allowed_purchased', $model->getAttributes())) {
            return (bool) $model->allowed_purchased;
        }

        // Actual DB column (typo)
        if (array_key_exists('allowed_purchsed', $model->getAttributes())) {
            return (bool) $model->allowed_purchsed;
        }

        // Fallback: accessor, if ever defined
        if (isset($model->allowed_purchased)) {
            return (bool) $model->allowed_purchased;
        }
        if (isset($model->allowed_purchsed)) {
            return (bool) $model->allowed_purchsed;
        }

        return null;
    }

    /**
     * Resolve effective available stock for a product/variant.
     * Returns NULL when allowed_purchased = true (unlimited / bypass).
     * Otherwise returns physical stock - reserved (never negative).
     */
    public function availableStock(Product $product, ?ProductVariant $variant = null): ?int
    {
        if ($this->isUnlimited($product, $variant)) {
            return null;
        }

        if ($variant) {
            $physical = (int) ($variant->stock_quantity ?? 0);
            $reserved = (int) ($variant->reserved_inventory ?? 0);
        } else {
            $physical = (int) ($product->stock_quantity ?? 0);
            $reserved = (int) ($product->reserved_inventory ?? 0);
        }

        return max(0, $physical - $reserved);
    }

    /**
     * Assert requested quantity can be satisfied.
     *
     * @param int $requestedQty   quantity being added / final qty
     * @param int $alreadyInCart  quantity already in cart (exclude current add)
     *
     * @throws Exception
     */
    public function assertCanAdd(
        Product $product,
        ?ProductVariant $variant,
        int $requestedQty,
        int $alreadyInCart = 0
    ): void {
        $available = $this->availableStock($product, $variant);

        if ($available === null) {
            return; // allowed_purchsed = true → no cap
        }

        $totalNeeded = $requestedQty + $alreadyInCart;

        if ($totalNeeded > $available) {
            $name = $product->name . ($variant ? " ({$variant->sku})" : '');
            throw new Exception(
                "Only {$available} unit(s) of {$name} available in stock."
            );
        }
    }
}
