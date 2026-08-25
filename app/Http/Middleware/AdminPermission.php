<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPermission
{
    public function handle(Request $request, Closure $next, $permission)
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login as admin.'
            ], 401);
        }
        if (!$admin->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => "Forbidden. You don't have permission to access this resource.",
                'required_permission' => $permission
            ], 403);
        }

        return $next($request);
    }

    // For multiple permissions check
    public function handleAny(Request $request, Closure $next, ...$permissions)
    {
        $admin = $request->user('admin');

        if (!$admin) {
            return response()->json([
                'message' => 'Unauthorized. Please login as admin.'
            ], 401);
        }

        foreach ($permissions as $permission) {
            if ($admin->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Forbidden. You don\'t have any of the required permissions.'
        ], 403);
    }
}
