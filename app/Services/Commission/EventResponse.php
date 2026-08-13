<?php

namespace App\Services\Commission;

class EventResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?array $data = null,
    ) {}
}