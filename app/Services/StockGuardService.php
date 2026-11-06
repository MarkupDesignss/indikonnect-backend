<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Exception;

class StockGuardService
{
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
     * True if allowed_purchased flag is enabled (stock cap bypassed).
     */
    public function isUnlimited(Product $product, ?ProductVariant $variant = null): bool
    {
        if ($variant && isset($variant->allowed_purchased)) {
            return (bool) $variant->allowed_purchased;
        }

        return (bool) ($product->allowed_purchased ?? false);
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
            return; // allowed_purchased = true → no cap
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
