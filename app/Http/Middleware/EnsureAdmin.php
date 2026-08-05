<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user instanceof Admin) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Admin access only.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}