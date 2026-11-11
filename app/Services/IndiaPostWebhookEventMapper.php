<?php

namespace App\Services;

class IndiaPostWebhookEventMapper
{
    /**
     * Only verified positive lifecycle events change the mapped status.
     * Hold, RTS, failed delivery, BAG_CLOSE etc. are recorded but not mapped.
     * Verify DOP's complete production event code list before expanding.
     */
    public static function map(?string $code, ?string $description): ?string
    {
        $code = strtoupper(trim((string) $code));
        $description = strtolower(trim((string) $description));

        // Exact description matches avoid falsely interpreting a negated event.
        return match (true) {
            in_array($code, ['ITEM_DELIVERED', 'DELIVERED'], true),
                $description === 'item delivered',
                $description === 'delivered' => 'delivered',
            in_array($code, ['OUT_FOR_DELIVERY', 'ITEM_OUT_FOR_DELIVERY'], true),
                $description === 'taken out for delivery',
                $description === 'out for delivery' => 'out_for_delivery',
            in_array($code, ['ITEM_RECEIVED', 'BAG_RECEIVED'], true),
                $description === 'item received',
                $description === 'bag received',
                $description === 'item received at destination' => 'in_transit',
            in_array($code, ['ITEM_DISPATCHED', 'PICKED_UP', 'PICKUP_COMPLETED'], true),
                $description === 'item dispatched',
                $description === 'item picked up' => 'picked_up',
            in_array($code, ['ITEM_BOOKED', 'BOOKED'], true),
                $description === 'item booked' => 'booked',
            default => null,
        };
    }

    public static function rank(?string $status): int
    {
        return ['booked' => 1, 'picked_up' => 2, 'in_transit' => 3,
            'out_for_delivery' => 4, 'delivered' => 5][$status ?? ''] ?? 0;
    }
}
