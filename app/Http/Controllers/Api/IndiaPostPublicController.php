<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safe read-only frontend gateway to the existing protected Node.js India Post routes.
 * Do NOT expose POST /booking/submit, /barcode, /session/check, or /label publicly.
 */
class IndiaPostPublicController extends Controller
{
    public function pincode(string $pincode): JsonResponse
    {
        if (!preg_match('/^[0-9]{6}$/D', $pincode)) {
            return response()->json(['success' => false, 'message' => 'Enter a valid 6-digit PIN code.'], 422);
        }

        return $this->nodeGet('pincode/' . $pincode);
    }

    public function businessParcelTariff(Request $request): JsonResponse
    {
        // Do not present UAT prices to live customers by accident.
        if (!(bool) config('services.indiapost_node.frontend_tariff_enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping charges will be calculated at checkout.',
            ], 503);
        }

        $data = $request->validate([
            'destination_pincode' => ['required', 'regex:/^[0-9]{6}$/'],
            'weight' => ['required', 'integer', 'min:1', 'max:35000'], // grams
            'length' => ['required', 'numeric', 'gt:0', 'lte:200'], // cm
            'width' => ['required', 'numeric', 'gt:0', 'lte:200'],
            'height' => ['required', 'numeric', 'gt:0', 'lte:200'],
        ]);

        // Warehouse PIN belongs to your backend config, never the customer/browser.
        $origin = (string) config('services.indiapost_node.origin_pincode', '');
        if (!preg_match('/^[0-9]{6}$/D', $origin)) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping origin is not configured.',
            ], 503);
        }

        return $this->nodeGet('tariff/business-parcel', [
            'source-pincode' => $origin,
            'destination-pincode' => $data['destination_pincode'],
            'weight' => $data['weight'],
            'length' => $data['length'],
            'width' => $data['width'],
            'height' => $data['height'],
        ]);
    }

    private function nodeGet(string $path, array $query = []): JsonResponse
    {
        $enabled = (bool) config('services.indiapost_node.enabled', false);
        $baseUrl = rtrim((string) config('services.indiapost_node.url', ''), '/');
        $key = (string) config('services.indiapost_node.api_key', '');
        if (!$enabled || !$baseUrl || !$key) {
            return response()->json(['success' => false, 'message' => 'India Post lookup is currently unavailable.'], 503);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-internal-api-key' => $key])
                ->connectTimeout(5)
                ->timeout(15)
                ->get($baseUrl . '/api/indiapost/' . $path, $query);
        } catch (Throwable $e) {
            Log::warning('India Post frontend proxy request failed', [
                'endpoint' => $path,
                'exception_type' => get_class($e),
            ]);
            return response()->json(['success' => false, 'message' => 'India Post service temporarily unavailable.'], 502);
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Invalid India Post response.'], 502);
        }
        if (!$response->successful() || ($payload['success'] ?? false) !== true) {
            Log::warning('India Post frontend proxy upstream returned an error', [
                'endpoint' => $path,
                'status' => $response->status(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Could not retrieve India Post information.',
            ], $response->status() === 422 ? 422 : 502);
        }

        // Only read-only postcode/tariff response data is returned to the browser.
        return response()->json($payload);
    }
}
