<?php

namespace App\Services\Commission;

class OrderPayload
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $action,
        public readonly string $orderReference,
        public readonly string $purchaserIdentifier,
        public readonly string $accountType,
        public readonly string $eventTimestamp,
        public readonly array $lines,
        public readonly float $totalOrderValue,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}