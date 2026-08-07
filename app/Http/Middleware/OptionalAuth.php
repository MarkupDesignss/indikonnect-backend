<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class OptionalAuth
{
    public function handle(Request $request, Closure $next, ...$guards)
    {
        // Try to authenticate but don't require it
        if (empty($guards)) {
            $guards = ['sanctum'];
        }

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                Auth::shouldUse($guard);
                break;
            }
        }

        return $next($request);
    }
}
