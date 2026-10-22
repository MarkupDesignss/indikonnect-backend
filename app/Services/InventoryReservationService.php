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

            $affected = DB::table('products')
                ->where('id', $productId)
                ->whereRaw('(stock_quantity - reserved_inventory) >= ?', [$qty])
                ->update(['reserved_inventory' => DB::raw('reserved_inventory + ' . $qty)]);

            if ($affected === 0) {
                throw new \Exception("Insufficient stock for product #{$productId}");
            }

            $created[] = InventoryReservation::create([
                'reservation_token' => $token,
                'order_id'          => $orderId,
                'product_id'        => $productId,
                'variant_id'        => $item['variant_id'] ?? null,
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
            $affected = DB::table('products')
                ->where('id', $res->product_id)
                ->where('stock_quantity', '>=', $res->quantity)
                ->where('reserved_inventory', '>=', $res->quantity)
                ->update([
                    'stock_quantity'     => DB::raw('stock_quantity - ' . $res->quantity),
                    'reserved_inventory' => DB::raw('reserved_inventory - ' . $res->quantity),
                ]);

            if ($affected === 0) {
                throw new \Exception("Stock mismatch consuming product #{$res->product_id} for order #{$orderId}");
            }

            $res->update(['status' => 'consumed', 'consumed_at' => now()]);
        }

        return true;
    }

    public function hasAvailability(int $productId, int $qty): bool
    {
        return Product::where('id', $productId)
            ->whereRaw('(stock_quantity - reserved_inventory) >= ?', [$qty])
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
            ->where('reserved_inventory', '>=', $res->quantity)
            ->update(['reserved_inventory' => DB::raw('reserved_inventory - ' . $res->quantity)]);

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