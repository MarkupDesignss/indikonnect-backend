<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    // public function boot(): void
    // {
    //     Schema::defaultStringLength(191);

    //     try {
    //         if (Schema::hasTable('settings')) {
    //             $timeout = (int) setting(
    //                 'customer_session_timeout_minutes',
    //                 env('SANCTUM_EXPIRATION', 1000)
    //             );

    //             Config::set('sanctum.expiration', $timeout);
    //         }
    //     } catch (\Throwable $e) {
    //         // Keep Laravel booting if settings table/query is unavailable.
    //     }
    // }
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        try {
            if (Schema::hasTable('settings')) {
                $timeout = (int) setting(
                    'customer_session_timeout_minutes',
                    env('SANCTUM_EXPIRATION', 1000)
                );

                Config::set('sanctum.expiration', $timeout);
            }
        } catch (\Throwable $e) {
        }

        RateLimiter::for('distributor-login', function (Request $request) {
            return Limit::perMinute(1)->by(
                'distributor-login'
            );
        });
    }
}
