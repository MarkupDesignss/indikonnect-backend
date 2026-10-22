<?php
// app/Services/InventoryReservationService.php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryReservationService
{
    /**
     * Reserve stock atomically. Throws if insufficient.
     * MUST be called inside an existing DB::transaction.
     */
    public function reserve(int $orderId, array $items, int $ttlMinutes = 20): array
    {
        $token     = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttlMinutes);
        $created   = [];

        foreach ($items as $item) {
            $qty       = (int) $item['quantity'];
            $productId = (int) $item['product_id'];

            if ($qty <= 0) {
                throw new \Exception("Invalid quantity for product #{$productId}");
            }

            // stock_quantity bhi minus, reserved_inventory bhi plus
            $affected = DB::table('products')
                ->where('id', $productId)
                ->where('stock_quantity', '>=', $qty)          // available check
                ->update([
                    'stock_quantity'     => DB::raw('stock_quantity - ' . $qty),
                    'reserved_inventory' => DB::raw('reserved_inventory + ' . $qty),
                ]);

            if ($affected === 0) {
                throw new \Exception("Insufficient stock for product #{$productId}");
            }

            $created[] = InventoryReservation::create([
                'reservation_token' => $token,
                'order_id'          => $orderId,
                'product_id'        => $productId,
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
            // stock_quantity already reserve() me minus ho chuki hai — sirf reserved_inventory release karo
            $affected = DB::table('products')
                ->where('id', $res->product_id)
                ->where('reserved_inventory', '>=', $res->quantity)
                ->update([
                    'reserved_inventory' => DB::raw('reserved_inventory - ' . $res->quantity),
                ]);

            if ($affected === 0) {
                throw new \Exception("Reservation mismatch consuming product #{$res->product_id} for order #{$orderId}");
            }

            $res->update(['status' => 'consumed', 'consumed_at' => now()]);
        }

        return true;
    }
    public function hasAvailability(int $productId, int $qty): bool
    {
        return Product::where('id', $productId)
            ->where('stock_quantity', '>=', $qty)
            ->exists();
    }

    /**
     * Release a single held reservation. MUST be inside DB::transaction.
     */
    public function release(InventoryReservation $res): void
    {
        if ($res->status !== 'held') {
            return;
        }

        DB::table('products')
            ->where('id', $res->product_id)
            ->update([
                'stock_quantity'     => DB::raw('stock_quantity + ' . $res->quantity),
                'reserved_inventory' => DB::raw('GREATEST(reserved_inventory - ' . $res->quantity . ', 0)'),
            ]);

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
