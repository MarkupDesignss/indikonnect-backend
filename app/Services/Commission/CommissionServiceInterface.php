<?php

namespace App\Services\Commission;

interface CommissionServiceInterface
{
    public function postOrderEvent(OrderPayload $payload): EventResponse;
    public function postReversalEvent(ReversalPayload $payload): EventResponse;
    public function healthCheck(): bool;
}