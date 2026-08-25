<?php

namespace App\Http\Middleware;

use App\Models\OutboundApiClient;
use Closure;
use Illuminate\Http\Request;

class OutboundApiAuth
{
    public function handle(Request $request, Closure $next, ...$scopes)
    {
        $apiKey = $request->header('X-API-Key') ?? $request->bearerToken();

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'API key is required. Please provide X-API-Key header or Bearer token.'
            ], 401);
        }

        $client = OutboundApiClient::active()
            ->where('api_key', $apiKey)
            ->first();

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or inactive API key.'
            ], 401);
        }

        $client->update(['last_used_at' => now()]);

        if (!empty($scopes)) {
            $hasScope = false;
            foreach ($scopes as $scope) {
                if ($client->hasScope($scope)) {
                    $hasScope = true;
                    break;
                }
            }
            if (!$hasScope) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient permissions. Required scopes: ' . implode(', ', $scopes)
                ], 403);
            }
        }

        $request->merge(['api_client' => $client]);
        return $next($request);
    }
}