<?php

namespace App\Services\PaymentGateway;

use App\Models\HdfcPaymentAttempt;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class HdfcPaymentManager
{
    private const SUCCESS = ['CHARGED', 'CAPTURED', 'PAID', 'SUCCESS', 'TXN_SUCCESS'];
    private const FAILURE = ['AUTHORIZATION_FAILED', 'FAILED', 'DECLINED', 'JUSPAY_DECLINED', 'PAYMENT_FAILURE', 'AUTHENTICATION_FAILED', 'TXN_FAILURE'];
    private const CANCELLATION = ['CANCELLED', 'CANCELED', 'USER_DROPPED', 'USER_CANCELLED', 'EXPIRED'];

    public function __construct(private CheckoutService $checkoutService) {}

    private function gateway(Order $order): HdfcService
    {
        if (!in_array($order->payment_gateway, ['hdfc_sg','hdfc_ie'], true)) {
            throw new RuntimeException('This order is not configured for HDFC');
        }
        $key = $order->payment_gateway === 'hdfc_ie' ? 'ie' : 'sg';
        return new HdfcService((array) config('services.hdfc.'.$key, []), $order->payment_gateway);
    }

    /** Diagnostics without API keys, HTTP payloads or customer details. */
    private function diagnostic(string $stage, Order $order, ?HdfcPaymentAttempt $attempt, \Throwable $e): void
    {
        $context = [
            'stage' => $stage,
            'order_id' => $order->id,
            'attempt_id' => $attempt?->id,
            'gateway' => $order->payment_gateway,
            'exception_class' => get_class($e),
            'error' => substr($e->getMessage(), 0, 350),
            'source' => basename($e->getFile()),
            'line' => $e->getLine(),
        ];
        try {
            Log::error('HDFC PAYMENT DIAGNOSTIC', $context);
        } catch (\Throwable $ignored) {
            // The dedicated file and PHP error log below are fallback channels.
        }
        $line = date('c').' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
        $filename = storage_path('logs/hdfc-payment-debug.log');
        try {
            if (@file_put_contents($filename, $line, FILE_APPEND | LOCK_EX) === false) {
                error_log('HDFC PAYMENT DIAGNOSTIC '.$line);
            }
        } catch (\Throwable $ignored) {
            error_log('HDFC PAYMENT DIAGNOSTIC '.$line);
        }
    }

    private function withOrderLock(int $id, callable $callback)
    {
        return Cache::lock('hdfc-order-'.$id, 90)->block(12, $callback);
    }

    private function ensureOwner(Order $order, int $userId): void
    {
        if ((int) $order->user_id !== $userId) abort(403, 'Not your order');
    }

    private function paymentAllowed(Order $order): void
    {
        if ($order->confirmed_at || $order->status === 'confirmed') {
            abort(409, 'Order already confirmed');
        }
        if (!in_array($order->status, ['pending','payment_failed'], true)) {
            abort(409, 'Order cannot accept a payment');
        }
        if (round((float) $order->total_payable, 2) <= 0) abort(422, 'Invalid order amount');
        // IMPORTANT: invoke your existing stock/price/coupon/expiry validation here
        // before enabling payment on a previously failed order.
    }

    /** Generates/reuses only for authenticated owner. Does not expose the raw gateway URL to other users. */
    public function initiate(Order $order, int $userId): array
    {
        $this->ensureOwner($order, $userId);
        return $this->withOrderLock($order->id, function () use ($order, $userId) {
            $order = Order::with('user')->findOrFail($order->id);
            $this->ensureOwner($order, $userId);
            $this->paymentAllowed($order);
            $existing = HdfcPaymentAttempt::where('order_id', $order->id)->latest('id')->first();
            if ($existing && in_array($existing->status, ['creating','active','cancel_requested','unknown'], true)) {
                // Do NOT issue a second payable link when the first is uncertain.
                if ($existing->status === 'active' && $existing->payment_link) {
                    // Never create a second payment attempt to resume. Verify the
                    // existing bank order, then return its original URL if approved.
                    $verified = $this->latest($order, $userId);
                    if (($verified['payment_status'] ?? null) === 'paid') {
                        abort(409, 'Payment is already completed. Open order confirmation.');
                    }
                    if (($verified['can_resume'] ?? false) === true && !empty($verified['payment_url'])) {
                        return [
                            'success' => true,
                            'attempt_id' => $existing->id,
                            'order_id' => $order->id,
                            'payment_url' => $verified['payment_url'],
                            'status' => 'active',
                            'payment_status' => 'pending',
                            'resumed' => true,
                        ];
                    }
                }
                // Unknown, cancellation-requested and unverified attempts cannot
                // be replaced by new sessions without reconciliation.
                abort(409, 'Previous payment session unresolved. Verify or cancel it first.');
            }
            // Validate configuration and local order data BEFORE persisting an attempt.
            // No gateway call is made here; errors cannot represent an unknown bank session.
            try {
                $gateway = $this->gateway($order);
                $gateway->validateCreateOrder($order);
            } catch (\Throwable $e) {
                $this->diagnostic('preflight', $order, null, $e);
                throw new RuntimeException('HDFC payment configuration or order validation failed; contact support', 0, $e);
            }
            $gatewayOrderId = 'H'.strtoupper(Str::random(19)); // max 20 chars, per-attempt unique
            $attempt = HdfcPaymentAttempt::create([
                'order_id' => $order->id, 'user_id' => $userId,
                'gateway' => $order->payment_gateway, 'gateway_order_id' => $gatewayOrderId,
                'status' => 'creating', 'amount' => $order->total_payable,
                'currency' => 'INR',
            ]);
            $stage = 'resolve-gateway';
            try {
                $stage = 'create-gateway-session';
                $session = $gateway->createOrder($order, $gatewayOrderId);
                $stage = 'save-payment-session';
                $attempt->update([
                    'session_id' => $session['id'], 'payment_link' => $session['payment_link'],
                    'status' => 'active', 'gateway_status' => $session['status'],
                    'expires_at' => now()->addMinutes((int) config('services.hdfc.local_link_ttl_minutes', 15)),
                ]);
                return $this->publicSession($attempt->fresh());
            } catch (\Throwable $e) {
                // Request may have reached bank even when our network timed out.
                $this->diagnostic($stage, $order, $attempt, $e);
                try {
                    $attempt->update(['status' => 'unknown']);
                } catch (\Throwable $saveError) {
                    $this->diagnostic('mark-attempt-unknown', $order, $attempt, $saveError);
                }
                throw new RuntimeException('Payment initiation could not be verified; do not retry until reconciled', 0, $e);
            }
        });
    }

    public function publicSession(HdfcPaymentAttempt $attempt): array
    {
        return [
            'success' => true, 'attempt_id' => $attempt->id,
            'order_id' => $attempt->order_id,
            'payment_url' => $attempt->payment_link,
            'status' => $attempt->status,
            'local_link_expires_at' => optional($attempt->expires_at)->toIso8601String(),
        ];
    }

    public function latest(Order $order, int $userId): array
    {
        $this->ensureOwner($order, $userId);
        $attempt = HdfcPaymentAttempt::where('order_id', $order->id)->latest('id')->first();
        if (!$attempt) return ['success' => true, 'order_id' => $order->id, 'payment_status' => 'not_started'];
        // IMPORTANT: orders.id is LOCAL; only gateway_order_id goes to HDFC.
        $result = $this->reconcile($attempt);
        // A local 15-minute TTL is NOT proof that the BANK has expired
        // its hosted link. Only provide the SAME already-issued link to the
        // authenticated owner after a successful fresh bank-side status check.
        // Never offer a link once cancellation was requested, or for unknown
        // / unverified / terminal attempts. HDFC may still reject an old link;
        // in that case the customer must request bank-side cancellation.
        $attempt->refresh();
        $bankStatus = strtoupper((string) ($result['gateway_status'] ?? ''));
        $canResume = ($result['payment_status'] ?? null) === 'pending'
            && $attempt->status === 'active'
            && !empty($attempt->payment_link)
            && in_array($bankStatus, ['NEW', 'PENDING', 'CREATED', 'STARTED'], true);
        $result['can_resume'] = $canResume;
        $result['order_id'] = $order->id;
        if ($canResume) {
            $result['payment_url'] = $attempt->payment_link;
            $result['attempt_id'] = $attempt->id;
            $result['local_link_expires_at'] = optional($attempt->expires_at)->toIso8601String();
            $result['local_ttl_elapsed'] = (bool) ($attempt->expires_at && $attempt->expires_at->isPast());
        }
        return $result;
    }

    public function cancel(Order $order, int $userId): array
    {
        $this->ensureOwner($order, $userId);
        return $this->withOrderLock($order->id, function () use ($order, $userId) {
            $order = Order::findOrFail($order->id);
            $this->ensureOwner($order, $userId);
            $attempt = HdfcPaymentAttempt::where('order_id', $order->id)->latest('id')->firstOrFail();
            if (in_array($attempt->status, ['paid','cancelled','failed'], true)) {
                return ['success' => true, 'payment_status' => $attempt->status];
            }
            // Request cancel first; never mark as cancelled merely because user closed page.
            $attempt->update(['status' => 'cancel_requested', 'cancel_requested_at' => now()]);
            try {
                $this->gateway($order)->cancelOrder($attempt->gateway_order_id, (string) $attempt->user_id);
                // Successful API response confirms cancellation request accepted, but verify
                // actual gateway terminal status before locally finalizing.
                return ['success' => true, 'payment_status' => 'cancel_requested', 'message' => 'Cancellation accepted; confirming with gateway'];
            } catch (\Throwable $e) {
                $this->diagnostic('cancel-gateway-order', $order, $attempt, $e);
                return ['success' => false, 'payment_status' => 'cancel_requested', 'message' => 'Cancellation is not confirmed. Payment may still be active.'];
            }
        });
    }

    /** This must be invoked by both authenticated polling and verified webhook/return. */
    public function reconcile(HdfcPaymentAttempt $attempt): array
    {
        $order = Order::findOrFail($attempt->order_id);
        // A locally confirmed payment needs no remote status request.
        if ($attempt->status === 'paid' && ($order->confirmed_at || $order->status === 'confirmed')) {
            return ['success' => true, 'order_id' => $order->id, 'payment_status' => 'paid'];
        }
        try {
            // $order->id (e.g. 9) is NEVER used as the HDFC gateway order ID.
            $data = $this->gateway($order)->getOrderStatus(
                (string) $attempt->gateway_order_id,
                (string) $attempt->user_id
            );
        } catch (\Throwable $e) {
            $this->diagnostic('get-gateway-status', $order, $attempt, $e);
            // Preserve unresolved status, do not invent FAILED/CANCELLED based on timeout or 404.
            throw new RuntimeException('Unable to verify gateway payment status', 0, $e);
        }
        $gatewayId = (string) (data_get($data, 'order_id') ?? data_get($data, 'order.order_id') ?? '');
        if ($gatewayId !== $attempt->gateway_order_id) throw new RuntimeException('HDFC status order ID mismatch');
        $status = strtoupper((string) (data_get($data, 'status') ?? data_get($data, 'order.status') ?? ''));
        if (!$status) throw new RuntimeException('Missing HDFC order status');
        if (in_array($status, self::SUCCESS, true)) {
            $amount = data_get($data, 'amount') ?? data_get($data, 'order.amount');
            $currency = strtoupper((string) (data_get($data, 'currency') ?? data_get($data, 'order.currency') ?? ''));
            if ($amount === null || abs((float) $amount - (float) $attempt->amount) > 0.009 || $currency !== 'INR') {
                throw new RuntimeException('Payment amount/currency mismatch');
            }
            $txnId = (string) (data_get($data, 'txn_id') ?? data_get($data, 'transaction_id') ?? data_get($data, 'id') ?? '');
            if (!$txnId) throw new RuntimeException('Missing transaction id; reconcile manually');
            return $this->withOrderLock($order->id, function () use ($attempt, $order, $txnId, $status, $amount) {
                // Synchronize with gateway initiated cancellation: a real PAID must still be recognized.
                $attempt = HdfcPaymentAttempt::findOrFail($attempt->id);
                $order = Order::findOrFail($order->id);
                if (!$order->confirmed_at && $order->status !== 'confirmed') {
                    if (!in_array($order->status, ['pending','payment_failed'], true)) {
                        throw new RuntimeException('Cannot auto-confirm unexpected order state');
                    }
                    // CheckoutService must be idempotent and atomically lock the order.
                    // It must accept the LOCAL order reference, not HDFC attempt reference.
                    $this->checkoutService->confirmOrder($order->order_reference, [
                        'gateway' => $order->payment_gateway, 'transaction_id' => $txnId,
                        'amount' => (float) $amount, 'method' => 'hdfc', 'status' => 'captured',
                    ]);
                }
                $attempt->update(['status' => 'paid', 'gateway_status' => $status,
                    'transaction_id' => $txnId, 'completed_at' => now()]);
                // Another prior attempt could be payable: flag it for review, never assume cancelled.
                return ['success' => true, 'payment_status' => 'paid'];
            });
        }
        if (in_array($status, array_merge(self::FAILURE, self::CANCELLATION), true)) {
            return $this->withOrderLock($order->id, function () use ($attempt, $order, $status) {
                $attempt = HdfcPaymentAttempt::findOrFail($attempt->id);
                $isCancel = in_array($status, self::CANCELLATION, true);
                $attempt->update(['status' => $isCancel ? 'cancelled' : 'failed',
                    'gateway_status' => $status, 'completed_at' => now()]);
                // Only mark LOCAL order payment_failed if no other paid/active/unknown attempts.
                $hasOtherLive = HdfcPaymentAttempt::where('order_id', $order->id)
                    ->where('id', '!=', $attempt->id)
                    ->whereIn('status', ['creating','active','cancel_requested','unknown','paid'])
                    ->exists();
                $fresh = Order::findOrFail($order->id);
                if (!$hasOtherLive && !$fresh->confirmed_at && $fresh->status === 'pending') {
                    DB::transaction(function () use ($fresh) {
                        $fresh = Order::whereKey($fresh->id)->lockForUpdate()->firstOrFail();
                        if ($fresh->status === 'pending' && !$fresh->confirmed_at) {
                            $fresh->update(['status' => 'payment_failed']);
                            // Update OrderLine delivery_status here ONLY if your existing business
                            // model treats payment_failed as a valid delivery_status.
                        }
                    });
                }
                return ['success' => true, 'payment_status' => $isCancel ? 'cancelled' : 'failed'];
            });
        }
        $attempt->update(['gateway_status' => $status]);
        return ['success' => true, 'payment_status' => 'pending', 'gateway_status' => $status];
    }
}
