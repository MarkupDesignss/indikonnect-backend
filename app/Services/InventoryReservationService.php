<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryReservationService
{
    public function __construct(
        protected StockGuardService $stockGuard
    ) {}

    /**
     * Reserve stock atomically.
     *
     * Place Order:
     * stock_quantity     = stock_quantity - quantity
     * reserved_inventory = reserved_inventory + quantity
     *
     * StockGuardService is intentionally not used to block deduction.
     * Negative stock is allowed.
     */
    public function reserve(
        int $orderId,
        array $items,
        int $ttlMinutes = 1
    ): array {

        $token = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttlMinutes);
        $created = [];

        foreach ($items as $item) {

            $qty = (int) $item['quantity'];
            $productId = (int) $item['product_id'];

            $variantId = !empty($item['variant_id'])
                ? (int) $item['variant_id']
                : null;

            if ($qty <= 0) {
                throw new \Exception(
                    "Invalid quantity for product #{$productId}"
                );
            }

            // Lock product
            $product = Product::where('id', $productId)
                ->lockForUpdate()
                ->first();

            if (!$product) {
                throw new \Exception(
                    "Product not found: #{$productId}"
                );
            }

            // Lock variant if available
            $variant = null;

            if ($variantId) {

                $variant = ProductVariant::where('id', $variantId)
                    ->where('item_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if (!$variant) {
                    throw new \Exception(
                        "Variant not found: #{$variantId}"
                    );
                }
            }

            /*
             * ALWAYS deduct stock.
             * No StockGuardService checks.
             * Negative stock is allowed.
             */

            if ($variant) {

                DB::table('product_variants')
                    ->where('id', $variant->id)
                    ->update([
                        'stock_quantity' => DB::raw(
                            'stock_quantity - ' . $qty
                        ),
                        'reserved_inventory' => DB::raw(
                            'reserved_inventory + ' . $qty
                        ),
                    ]);
            } else {

                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'stock_quantity' => DB::raw(
                            'stock_quantity - ' . $qty
                        ),
                        'reserved_inventory' => DB::raw(
                            'reserved_inventory + ' . $qty
                        ),
                    ]);
            }

            // Always create reservation entry
            $created[] = InventoryReservation::create([
                'reservation_token' => $token,
                'order_id'          => $orderId,
                'product_id'        => $product->id,
                'item_reference_id' => $item['item_reference_id'] ?? null,
                'quantity'          => $qty,
                'status'            => 'held',
                'expires_at'        => $expiresAt,
            ]);
        }

        return [
            'token'        => $token,
            'expires_at'   => $expiresAt,
            'reservations' => $created,
        ];
    }

    /**
     * Payment successful.
     *
     * Stock is already deducted during reserve().
     * Only reserved_inventory will decrease.
     */
    public function consume(int $orderId): bool
    {
        $reservations = InventoryReservation::where('order_id', $orderId)
            ->where('status', 'held')
            ->lockForUpdate()
            ->get();

        if ($reservations->isEmpty()) {
            return false;
        }

        foreach ($reservations as $res) {

            if ($res->variant_id) {

                $affected = DB::table('product_variants')
                    ->where('id', $res->variant_id)
                    ->where('reserved_inventory', '>=', $res->quantity)
                    ->update([
                        'reserved_inventory' => DB::raw(
                            'reserved_inventory - ' . (int) $res->quantity
                        ),
                    ]);
            } else {

                $affected = DB::table('products')
                    ->where('id', $res->product_id)
                    ->where('reserved_inventory', '>=', $res->quantity)
                    ->update([
                        'reserved_inventory' => DB::raw(
                            'reserved_inventory - ' . (int) $res->quantity
                        ),
                    ]);
            }

            if (!$affected) {
                throw new \Exception(
                    "Reservation mismatch consuming product #{$res->product_id} for order #{$orderId}"
                );
            }

            $res->update([
                'status'      => 'consumed',
                'consumed_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Availability check.
     *
     * Kept for existing callers.
     * Does not affect reserve() or stock deduction.
     */
    public function hasAvailability(
        int $productId,
        int $qty,
        ?int $variantId = null
    ): bool {

        $product = Product::find($productId);

        if (!$product) {
            return false;
        }

        $variant = $variantId
            ? ProductVariant::find($variantId)
            : null;

        if ($variantId && !$variant) {
            return false;
        }

        if ($this->stockGuard->isUnlimited($product, $variant)) {
            return true;
        }

        $available = $this->stockGuard->availableStock(
            $product,
            $variant
        );

        return $available !== null && $available >= $qty;
    }

    /**
     * Payment failed / reservation expired.
     *
     * Restore stock_quantity.
     * Decrease reserved_inventory.
     */
    public function release(InventoryReservation $res): void
    {
        if ($res->status !== 'held') {
            return;
        }

        if ($res->variant_id) {

            $affected = DB::table('product_variants')
                ->where('id', $res->variant_id)
                ->where('reserved_inventory', '>=', $res->quantity)
                ->update([
                    'stock_quantity' => DB::raw(
                        'stock_quantity + ' . (int) $res->quantity
                    ),
                    'reserved_inventory' => DB::raw(
                        'reserved_inventory - ' . (int) $res->quantity
                    ),
                ]);
        } else {

            $affected = DB::table('products')
                ->where('id', $res->product_id)
                ->where('reserved_inventory', '>=', $res->quantity)
                ->update([
                    'stock_quantity' => DB::raw(
                        'stock_quantity + ' . (int) $res->quantity
                    ),
                    'reserved_inventory' => DB::raw(
                        'reserved_inventory - ' . (int) $res->quantity
                    ),
                ]);
        }

        if (!$affected) {
            throw new \Exception(
                "Unable to release reservation #{$res->id}"
            );
        }

        $res->update([
            'status'      => 'released',
            'released_at' => now(),
        ]);
    }

    /**
     * Release all held reservations for an order.
     */
    public function releaseForOrder(int $orderId): int
    {
        $held = InventoryReservation::where('order_id', $orderId)
            ->where('status', 'held')
            ->lockForUpdate()
            ->get();

        foreach ($held as $res) {
            $this->release($res);
        }

        return $held->count();
    }
}
