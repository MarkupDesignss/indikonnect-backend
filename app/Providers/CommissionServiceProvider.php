<?php

namespace App\Providers;

use App\Services\Commission\CommissionServiceInterface;
use App\Services\Commission\MockCommissionService;
use Illuminate\Support\ServiceProvider;

class CommissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CommissionServiceInterface::class, function ($app) {
            $provider = config('commission.provider', 'mock');
            if ($provider === 'real') {
                return $app->make(\App\Services\Commission\RealCommissionService::class);
            }
            return $app->make(MockCommissionService::class);
        });
    }

    public function boot(): void
    {
        //
    }
}