<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

if (!function_exists('setting')) {
    /**
     * Get a setting value by key with caching.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed    
     */
    function setting(string $key, mixed $default = null): mixed
    {
        // Cache all settings in one query
        $settings = Cache::rememberForever('settings', function () {
            return Setting::all()
                ->mapWithKeys(fn($item) => [$item->key => $item->typed_value])
                ->toArray();
        });

        return $settings[$key] ?? $default;
    }
}
