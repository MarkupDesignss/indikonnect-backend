<?php

namespace App\Services;

use App\Models\Product;
use Exception;

class StockGuardService
{
    /**
     * Resolve the "allowed purchased" flag for a product.
     *
     * NOTE: The DB column is spelled `allowed_purchsed` (typo in schema).
     * We check both spellings so this keeps working after a future rename.
     */
    public function isUnlimited(Product $product): bool
    {
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
     * Resolve effective available stock for a product.
     * Returns NULL when allowed_purchased = true (unlimited / bypass).
     * Otherwise returns physical stock - reserved (never negative).
     */
    public function availableStock(Product $product): ?int
    {
        if ($this->isUnlimited($product)) {
            return null;
        }

        $physical = (int) ($product->stock_quantity ?? 0);
        $reserved = (int) ($product->reserved_inventory ?? 0);

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
        int $requestedQty,
        int $alreadyInCart = 0
    ): void {
        $available = $this->availableStock($product);

        if ($available === null) {
            return; // allowed_purchsed = true → no cap
        }

        $totalNeeded = $requestedQty + $alreadyInCart;

        if ($totalNeeded > $available) {
            throw new Exception(
                "Only {$available} unit(s) of {$product->name} available in stock."
            );
        }
    }
}
