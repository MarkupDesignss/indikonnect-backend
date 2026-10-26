<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Models\HdfcPaymentAttempt;
use App\Services\PaymentGateway\HdfcPaymentManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class HdfcWebhookController extends Controller
{
    public function __construct(private HdfcPaymentManager $manager) {}

    /** Dashboard webhook username/password must exactly match env for the relevant merchant. */
    private function authenticateWebhook(Request $request): bool
    {
        $auth = (string) $request->header('Authorization', '');
        if (!str_starts_with($auth, 'Basic ')) return false;
        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) return false;
        [$username, $password] = explode(':', $decoded, 2);
        $pairs = [
            [(string) config('services.hdfc.sg.webhook_username'), (string) config('services.hdfc.sg.webhook_password')],
            [(string) config('services.hdfc.ie.webhook_username'), (string) config('services.hdfc.ie.webhook_password')],
        ];
        foreach ($pairs as [$knownUser, $knownPassword]) {
            if ($knownUser !== '' && $knownPassword !== '' &&
                hash_equals($knownUser, $username) && hash_equals($knownPassword, $password)) return true;
        }
        return false;
    }

    private function attemptFromRequest(Request $request): ?HdfcPaymentAttempt
    {
        $ids = [
            $request->input('order_id'), $request->input('order.order_id'),
            $request->input('content.order.order_id'), $request->input('data.order.order_id'),
        ];
        foreach ($ids as $id) {
            if (is_scalar($id) && (string) $id !== '') {
                $attempt = HdfcPaymentAttempt::where('gateway_order_id', (string) $id)->first();
                if ($attempt) return $attempt;
            }
        }
        return null; // Never use udf1/local order reference to decide which attempt was paid.
    }

    public function handle(Request $request): JsonResponse
    {
        if (!$this->authenticateWebhook($request)) return response()->json(['error'=>'unauthorized'], 401);
        $attempt = $this->attemptFromRequest($request);
        if (!$attempt) return response()->json(['error'=>'unrecognized order'], 404);
        try {
            $result = $this->manager->reconcile($attempt);
            return response()->json(['acknowledged'=>true, 'payment_status'=>$result['payment_status']]);
        } catch (\Throwable $e) {
            Log::error('HDFC webhook reconciliation failed', ['attempt_id'=>$attempt->id, 'error'=>$e->getMessage()]);
            // Force gateway retry; never mark order failed from unverified payload.
            return response()->json(['acknowledged'=>false], 503);
        }
    }

    /** Browser return is NOT a source of truth; attempt gateway verification only. */
    public function browserReturn(Request $request): Response
    {
        $attempt = $this->attemptFromRequest($request);
        $result = 'pending';
        if ($attempt) {
            try { $result = $this->manager->reconcile($attempt)['payment_status']; }
            catch (\Throwable $e) { Log::warning('HDFC browser status unverified', ['attempt_id'=>$attempt->id]); }
        }
        // Redirects can be incompatible with certain SmartGateway return_url configurations.
        // See README and test this with your merchant's integration kit.
        $key = $attempt?->gateway === 'hdfc_ie' ? 'ie' : 'sg';
        $url = (string) config('services.hdfc.'.$key.'.frontend_result_url');
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            abort(500, 'Frontend result URL is not configured');
        }
        $query = http_build_query([
            'gateway'=>'hdfc', 'order_reference'=>$attempt?->order?->order_reference ?? '',
            'payment_status'=>$result,
        ]);
        // Frontend query is informational only. For genuine status use authenticated /status endpoint.
        return redirect()->away(rtrim($url, '/').'?'.$query, 303)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
