<?php

namespace App\Services\Commission;

class ReversalPayload
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $action,
        public readonly string $orderReference,
        public readonly string $reason,
        public readonly array $lines,
        public readonly float $reversedValue,
        public readonly float $originalCv,
    ) {}
}