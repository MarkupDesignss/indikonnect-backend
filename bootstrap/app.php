<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\OutboundApiAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Exclude contact routes from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'contact/*',  // Excludes ALL routes starting with /contact/
            // Or specify individually:
            // 'contact/send-request',
            // 'contact/bulk-delete',
            // 'contact/mark-read/*',
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'optional.auth' => \App\Http\Middleware\OptionalAuth::class,
            'permission' => \App\Http\Middleware\AdminPermission::class,
            'outbound.api' => OutboundApiAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
