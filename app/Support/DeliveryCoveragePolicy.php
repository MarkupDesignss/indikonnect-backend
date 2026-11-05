<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * IndieKonnect delivery coverage: India-wide except Telangana.
 * Must be called using the server-validated shipping address, not solely browser input.
 */
final class DeliveryCoveragePolicy
{
    public static function isBlocked(?string $state): bool
    {
        $value = strtolower(trim((string) $state));
        $value = preg_replace('/[^a-z]/', '', $value) ?? '';

        return in_array($value, [
            'telangana', 'telengana', 'telingana', 'ts', 'tg',
        ], true);
    }

    /** Call before creating an order, calculating final shipping or taking payment. */
    public static function ensureDeliverable(?string $state): void
    {
        if (trim((string) $state) === '') {
            throw ValidationException::withMessages([
                'shipping_address' => 'Please select a valid delivery address.',
            ]);
        }

        if (self::isBlocked($state)) {
            throw ValidationException::withMessages([
                'shipping_address' => 'Sorry, we currently do not deliver to Telangana. Please choose an address in another state.',
            ]);
        }
    }
}
