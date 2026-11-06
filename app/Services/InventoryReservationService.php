<?php
// app/Services/InventoryReservationService.php

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
     * Reserve stock atomically. Throws if insufficient.
     * MUST be called inside an existing DB::transaction.
     *
     * For allowed_purchsed = true items:
     *   → no stock decrement, no reserved_inventory increment,
     *     no InventoryReservation row (nothing to release later).
     *
     * For allowed_purchsed = false items:
     *   → lock row, verify available (stock - reserved) >= qty,
     *     then decrement stock_quantity AND increment reserved_inventory,
     *     and create a "held" reservation row.
     *
     * @return array{token:string, expires_at:\Carbon\Carbon, reservations:array}
     */
    // public function reserve(int $orderId, array $items, int $ttlMinutes = 1): array
    // {
    //     $token     = (string) Str::uuid();
    //     $expiresAt = now()->addMinutes($ttlMinutes);
    //     $created   = [];

    //     foreach ($items as $item) {
    //         $qty       = (int) $item['quantity'];
    //         $productId = (int) $item['product_id'];
    //         $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;

    //         if ($qty <= 0) {
    //             throw new \Exception("Invalid quantity for product #{$productId}");
    //         }

    //         // ---- lock the row (product or variant) ----
    //         $product = Product::where('id', $productId)->lockForUpdate()->first();
    //         if (!$product) {
    //             throw new \Exception("Product not found: #{$productId}");
    //         }

    //         $variant = null;
    //         if ($variantId) {
    //             $variant = ProductVariant::where('id', $variantId)->lockForUpdate()->first();
    //             if (!$variant) {
    //                 throw new \Exception("Variant not found: #{$variantId}");
    //             }
    //         }

    //         // ---- unlimited? skip reservation entirely ----
    //         if ($this->stockGuard->isUnlimited($product, $variant)) {
    //             continue;
    //         }

    //         // ---- strict stock check under lock ----
    //         $this->stockGuard->assertCanAdd($product, $variant, $qty);

    //         if ($variant) {
    //             $affected = DB::table('product_variants')
    //                 ->where('id', $variant->id)
    //                 ->whereRaw('(stock_quantity - reserved_inventory) >= ?', [$qty])
    //                 ->update([
    //                     'stock_quantity'     => DB::raw('stock_quantity - ' . $qty),
    //                     'reserved_inventory' => DB::raw('reserved_inventory + ' . $qty),
    //                 ]);

    //             if ($affected === 0) {
    //                 throw new \Exception("Insufficient stock for variant #{$variant->id}");
    //             }
    //         } else {
    //             $affected = DB::table('products')
    //                 ->where('id', $product->id)
    //                 ->whereRaw('(stock_quantity - reserved_inventory) >= ?', [$qty])
    //                 ->update([
    //                     'stock_quantity'     => DB::raw('stock_quantity - ' . $qty),
    //                     'reserved_inventory' => DB::raw('reserved_inventory + ' . $qty),
    //                 ]);

    //             if ($affected === 0) {
    //                 throw new \Exception("Insufficient stock for product #{$product->id}");
    //             }
    //         }

    //         $created[] = InventoryReservation::create([
    //             'reservation_token' => $token,
    //             'order_id'          => $orderId,
    //             'product_id'        => $product->id,
    //             'item_reference_id' => $item['item_reference_id'] ?? null,
    //             'quantity'          => $qty,
    //             'status'            => 'held',
    //             'expires_at'        => $expiresAt,
    //         ]);
    //     }

    //     return ['token' => $token, 'expires_at' => $expiresAt, 'reservations' => $created];
    // }
    public function reserve(int $orderId, array $items, int $ttlMinutes = 1): array
    {
        $token     = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttlMinutes);
        $created   = [];

        foreach ($items as $item) {
            $qty       = (int) $item['quantity'];
            $productId = (int) $item['product_id'];
            $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;

            if ($qty <= 0) {
                throw new \Exception("Invalid quantity for product #{$productId}");
            }

            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if (!$product) {
                throw new \Exception("Product not found: #{$productId}");
            }

            $variant = null;
            if ($variantId) {
                $variant = ProductVariant::where('id', $variantId)->lockForUpdate()->first();
                if (!$variant) {
                    throw new \Exception("Variant not found: #{$variantId}");
                }
            }

            // unlimited products → skip reservation entirely
            if ($this->stockGuard->isUnlimited($product, $variant)) {
                continue;
            }

            // strict stock check under lock (stock - reserved >= qty)
            $this->stockGuard->assertCanAdd($product, $variant, $qty);

            // ============================================================
            // 🔑 SIRF reserved_inventory BADHAO — stock_quantity MAT CHHEDO
            // ============================================================
            if ($variant) {
                $affected = DB::table('product_variants')
                    ->where('id', $variant->id)
                    ->whereRaw('(stock_quantity - reserved_inventory) >= ?', [$qty])
                    ->update([
                        'reserved_inventory' => DB::raw('reserved_inventory + ' . $qty),
                        // ❌ stock_quantity ko touch NA karo
                    ]);

                if ($affected === 0) {
                    throw new \Exception("Insufficient stock for variant #{$variant->id}");
                }
            } else {
                $affected = DB::table('products')
                    ->where('id', $product->id)
                    ->whereRaw('(stock_quantity - reserved_inventory) >= ?', [$qty])
                    ->update([
                        'reserved_inventory' => DB::raw('reserved_inventory + ' . $qty),
                        // ❌ stock_quantity ko touch NA karo
                    ]);

                if ($affected === 0) {
                    throw new \Exception("Insufficient stock for product #{$product->id}");
                }
            }

            $created[] = InventoryReservation::create([
                'reservation_token' => $token,
                'order_id'          => $orderId,
                'product_id'        => $product->id,
                'variant_id'        => $variant ? $variant->id : null,
                'item_reference_id' => $item['item_reference_id'] ?? null,
                'quantity'          => $qty,
                'status'            => 'held',
                'expires_at'        => $expiresAt,
            ]);
        }

        return ['token' => $token, 'expires_at' => $expiresAt, 'reservations' => $created];
    }

    /**
     * Consume reservations for an order (payment success).
     * Returns true if consumed, false if no held reservation found.
     * MUST be inside DB::transaction.
     *
     * stock_quantity was already decremented in reserve(), so here we only
     * release reserved_inventory — and only for rows we actually created.
     */
    // public function consume(int $orderId): bool
    // {
    //     $reservations = InventoryReservation::where('order_id', $orderId)
    //         ->where('status', 'held')
    //         ->lockForUpdate()
    //         ->get();

    //     if ($reservations->isEmpty()) {
    //         return false;
    //     }

    //     foreach ($reservations as $res) {
    //         if ($res->variant_id) {
    //             $affected = DB::table('product_variants')
    //                 ->where('id', $res->variant_id)
    //                 ->where('reserved_inventory', '>=', $res->quantity)
    //                 ->update([
    //                     'reserved_inventory' => DB::raw('reserved_inventory - ' . $res->quantity),
    //                 ]);
    //         } else {
    //             $affected = DB::table('products')
    //                 ->where('id', $res->product_id)
    //                 ->where('reserved_inventory', '>=', $res->quantity)
    //                 ->update([
    //                     'reserved_inventory' => DB::raw('reserved_inventory - ' . $res->quantity),
    //                 ]);
    //         }

    //         if ($affected === 0) {
    //             throw new \Exception(
    //                 "Reservation mismatch consuming product #{$res->product_id} for order #{$orderId}"
    //             );
    //         }

    //         $res->update(['status' => 'consumed', 'consumed_at' => now()]);
    //     }

    //     return true;
    // }
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
                // ✅ VARIANT: stock_quantity -qty, reserved_inventory -qty
                $affected = DB::table('product_variants')
                    ->where('id', $res->variant_id)
                    ->where('reserved_inventory', '>=', $res->quantity)
                    ->where('stock_quantity', '>=', $res->quantity)
                    ->update([
                        'stock_quantity'     => DB::raw('stock_quantity - ' . $res->quantity),
                        'reserved_inventory' => DB::raw('reserved_inventory - ' . $res->quantity),
                    ]);
            } else {
                //  PRODUCT: stock_quantity -qty, reserved_inventory -qty
                $affected = DB::table('products')
                    ->where('id', $res->product_id)
                    ->where('reserved_inventory', '>=', $res->quantity)
                    ->where('stock_quantity', '>=', $res->quantity)
                    ->update([
                        'stock_quantity'     => DB::raw('stock_quantity - ' . $res->quantity),
                        'reserved_inventory' => DB::raw('reserved_inventory - ' . $res->quantity),
                    ]);
            }

            if ($affected === 0) {
                throw new \Exception(
                    "Reservation mismatch consuming product #{$res->product_id} for order #{$orderId}"
                );
            }

            $res->update(['status' => 'consumed', 'consumed_at' => now()]);
        }

        return true;
    }

    /**
     * Quick availability check.
     * Returns TRUE for allowed_purchsed products (unlimited),
     * otherwise physical - reserved >= qty.
     */
    public function hasAvailability(int $productId, int $qty, ?int $variantId = null): bool
    {
        $product = Product::find($productId);
        if (!$product) {
            return false;
        }

        $variant = $variantId ? ProductVariant::find($variantId) : null;

        if ($this->stockGuard->isUnlimited($product, $variant)) {
            return true;
        }

        $available = $this->stockGuard->availableStock($product, $variant);
        return $available !== null && $available >= $qty;
    }

    /**
     * Release a single held reservation. MUST be inside DB::transaction.
     *
     * Reverses reserve() for allowed_purchsed=false rows only.
     */
    // public function release(InventoryReservation $res): void
    // {
    //     if ($res->status !== 'held') {
    //         return;
    //     }

    //     if ($res->variant_id) {
    //         DB::table('product_variants')
    //             ->where('id', $res->variant_id)
    //             ->update([
    //                 'stock_quantity'     => DB::raw('stock_quantity + ' . $res->quantity),
    //                 'reserved_inventory' => DB::raw('GREATEST(reserved_inventory - ' . $res->quantity . ', 0)'),
    //             ]);
    //     } else {
    //         DB::table('products')
    //             ->where('id', $res->product_id)
    //             ->update([
    //                 'stock_quantity'     => DB::raw('stock_quantity + ' . $res->quantity),
    //                 'reserved_inventory' => DB::raw('GREATEST(reserved_inventory - ' . $res->quantity . ', 0)'),
    //             ]);
    //     }

    //     $res->update(['status' => 'released', 'released_at' => now()]);
    // }
    public function release(InventoryReservation $res): void
    {
        if ($res->status !== 'held') {
            return;
        }

        if ($res->variant_id) {
            DB::table('product_variants')
                ->where('id', $res->variant_id)
                ->update([
                    // ❌ stock_quantity ko touch NA karo (reserve() ne ghataya hi nahi)
                    'reserved_inventory' => DB::raw('GREATEST(reserved_inventory - ' . $res->quantity . ', 0)'),
                ]);
        } else {
            DB::table('products')
                ->where('id', $res->product_id)
                ->update([
                    // ❌ stock_quantity ko touch NA karo
                    'reserved_inventory' => DB::raw('GREATEST(reserved_inventory - ' . $res->quantity . ', 0)'),
                ]);
        }

        $res->update(['status' => 'released', 'released_at' => now()]);
    }

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
