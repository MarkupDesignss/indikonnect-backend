<?php

namespace App\Jobs;

use App\Models\Order;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PostOrderToIgniteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 120;
    public int $timeout = 60;

    public array $backoff = [30, 60, 120, 300, 600];

    /** Refusals retrying will never fix. */
    private const PERMANENT_CODES = [
        ResultCode::VALIDATION_FAILED->value,
        ResultCode::MEMBER_NOT_FOUND->value,
        ResultCode::PRODUCT_NOT_ON_CATALOG->value,
        ResultCode::CV_ALLOCATION_MISMATCH->value,
        ResultCode::ALLOCATION_TARGET_INVALID->value,
        ResultCode::CV_001_MISMATCH->value,
        ResultCode::EXTRA_TC_OVER_ENTITLEMENT->value,
        ResultCode::EXTRA_TC_PARENT_INVALID->value,
        ResultCode::ORDER_NO_CV->value,
        ResultCode::CV_ORIGINALS_FIRST->value,
        ResultCode::ORDER_DISABLED->value,
    ];

    public function __construct(public int $orderId)
    {
        $this->onQueue('ignite-sync');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    public function handle(IgniteClient $ignite): void
    {
        Log::info('Ignite order job started', [
            'order_id' => $this->orderId,
            'attempt'  => $this->attempts(),
        ]);

        $order = Order::with(['lines.product', 'user'])->find($this->orderId);

        if (!$order) {
            Log::warning('Ignite job: order not found', ['order_id' => $this->orderId]);
            return;
        }

        // Idempotency: already posted
        if ($order->ignite_sync_status === 'posted' && !empty($order->ignite_order_no)) {
            Log::info('Ignite job: already posted, skipping', [
                'order_id'        => $order->id,
                'ignite_order_no' => $order->ignite_order_no,
            ]);
            return;
        }

        $user = $order->user;
        if (!$user || empty($user->distributor_id) || $order->order_type !== 'distributor') {
            $order->update([
                'ignite_sync_status' => 'skipped',
                'ignite_sync_error'  => 'Not a distributor order or missing distributor_id.',
            ]);
            Log::info('Ignite job: skipped', [
                'order_id' => $order->id,
                'reason'   => 'not_distributor_or_no_id',
                'has_user' => (bool) $user,
                'has_did'  => !empty($user?->distributor_id),
                'type'     => $order->order_type,
            ]);
            return;
        }

        // Config
        $localCurrency = config('services.ignite.local_currency');
        $timezone      = config('services.ignite.timezone');

        if (!$localCurrency || !$timezone) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => 'Ignite local_currency/timezone not configured.',
            ]);
            Log::error('Ignite job: config missing');
            return;
        }

        // Timezone must be a valid PHP tz — no offset assertion.
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => "Invalid Ignite timezone: {$timezone}",
            ]);
            Log::error('Ignite job: invalid timezone', ['tz' => $timezone, 'error' => $e->getMessage()]);
            return;
        }

        // Normalize to -001
        $mainBaId = $user->distributor_id;
        if (preg_match('/-(002|003)$/', $mainBaId)) {
            $mainBaId = preg_replace('/-(002|003)$/', '-001', $mainBaId);
        }

        if (empty($order->order_reference)) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => 'order_reference is empty; cannot post to Ignite.',
            ]);
            Log::error('Ignite job: empty order_reference', ['order_id' => $order->id]);
            return;
        }

        try {
            // ── Catalog ──
            $catalogRes = $ignite->catalog();
            if (($catalogRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception(
                    "catalog() refused: {$catalogRes['code']} " . ($catalogRes['msg'] ?? 'unknown')
                );
            }
            // product_id => Collection<catalog line>
            $catalogByProduct = collect($catalogRes['data']['items'] ?? [])->groupBy('product_id');

            // ── Pre-flight (all-or-nothing) ──
            $lines         = [];
            $totalCv       = 0.0;
            $lineErrors    = [];
            $eligibleLines = [];

            foreach ($order->lines as $line) {
                if (in_array($line->delivery_status, ['cancelled', 'returned'], true)) {
                    Log::info('Ignite job: skipping cancelled/returned line', [
                        'order_id'        => $order->id,
                        'line_id'         => $line->id,
                        'delivery_status' => $line->delivery_status,
                    ]);
                    continue;
                }

                $product = $line->product;
                if (!$product || empty($product->ignite_product_id)) {
                    $lineErrors[] = "Line {$line->id}: product missing ignite_product_id";
                    continue;
                }

                $candidates = $catalogByProduct->get((int) $product->ignite_product_id, collect());
                if ($candidates->isEmpty()) {
                    $lineErrors[] = "Line {$line->id}: product {$product->ignite_product_id} not in Ignite catalog";
                    continue;
                }

                if ($candidates->count() > 1) {
                    $ids = $candidates->pluck('catalog_item_id')->implode(', ');
                    $lineErrors[] = "Line {$line->id}: product {$product->ignite_product_id} has {$candidates->count()} catalog lines on sale ({$ids}); cannot pick one";
                    continue;
                }

                $igniteItem = $candidates->first();
                if (empty($igniteItem['catalog_item_id'])) {
                    $lineErrors[] = "Line {$line->id}: product {$product->ignite_product_id} has no catalog_item_id";
                    continue;
                }

                $lineCv = round((float) $igniteItem['cv'] * (int) $line->quantity, 4);

                $lines[] = [
                    'product_id'      => (int) $product->ignite_product_id,
                    'qty'             => (int) $line->quantity,
                    'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
                ];
                $totalCv += $lineCv;
                $eligibleLines[] = ['line' => $line, 'cv' => $lineCv];
            }

            if (!empty($lineErrors)) {
                $order->update([
                    'ignite_sync_status' => 'blocked',
                    'ignite_sync_error'  => implode(' | ', $lineErrors),
                ]);
                Log::error('Ignite job: pre-flight failed, order blocked', [
                    'order_id' => $order->id,
                    'errors'   => $lineErrors,
                ]);
                return;
            }

            if (empty($lines)) {
                $order->update([
                    'ignite_sync_status' => 'skipped',
                    'ignite_sync_error'  => 'No eligible lines to post.',
                ]);
                Log::info('Ignite job: no eligible lines', ['order_id' => $order->id]);
                return;
            }

            // ── Plan figures ──
            $planRes = $ignite->planRanks();
            if (($planRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception(
                    "planRanks() refused: {$planRes['code']} " . ($planRes['msg'] ?? 'unknown')
                );
            }
            $qualifyCv = (float) ($planRes['data']['plan']['qualify_cv'] ?? 0);
            if ($qualifyCv <= 0) {
                throw new \Exception('planRanks returned qualify_cv <= 0; misconfig on Ignite.');
            }

            // ── Member ──
            $memberRes = $ignite->memberDetails($mainBaId);
            if (($memberRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception(
                    "memberDetails({$mainBaId}) refused: {$memberRes['code']} " . ($memberRes['msg'] ?? 'unknown')
                );
            }
            $member = $memberRes['data'] ?? [];

            // ── Binary tree (need -002 / -003 under -001) ──
            $treeRes = $ignite->binaryTree($mainBaId, 1);
            if (($treeRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception(
                    "binaryTree({$mainBaId}) refused: {$treeRes['code']} " . ($treeRes['msg'] ?? 'unknown')
                );
            }
            $level1 = collect($treeRes['data']['rows'] ?? [])->where('level', 1);
            $ba002  = $level1->firstWhere('binary_side', 1)['ba_id'] ?? null;
            $ba003  = $level1->firstWhere('binary_side', 2)['ba_id'] ?? null;

            if (!$ba002 || !$ba003) {
                $order->update([
                    'ignite_sync_status' => 'pending_placement',
                    'ignite_sync_error'  => 'Member has no -002/-003 (not yet placed).',
                ]);
                Log::warning('Ignite job: member not placed, parked', ['order_id' => $order->id]);
                return;
            }

            // ── Allocations (4-dec internally; residual on -003) ──
            $cv001 = 0.0;
            if (empty($member['qualified_at'])) {
                $cv001 = min(
                    max(0.0, round($qualifyCv - (float) ($member['cv_allocated'] ?? 0), 4)),
                    round($totalCv, 4)
                );
            }
            $cv001 = round($cv001, 4);

            $remaining = round($totalCv - $cv001, 4);
            $cv002     = round($remaining / 2, 4);
            $cv003     = round($remaining - $cv002, 4);

            $allocations = [];
            if ($cv001 > 0) $allocations[] = ['ba_id' => $mainBaId, 'cv' => number_format($cv001, 4, '.', '')];
            if ($cv002 > 0) $allocations[] = ['ba_id' => $ba002,   'cv' => number_format($cv002, 4, '.', '')];
            if ($cv003 > 0) $allocations[] = ['ba_id' => $ba003,   'cv' => number_format($cv003, 4, '.', '')];

            $allocSum = round(array_sum(array_map(fn($a) => (float) $a['cv'], $allocations)), 4);
            if ($allocSum !== round($totalCv, 4)) {
                throw new \Exception("Internal allocation mismatch: allocated={$allocSum}, totalCv=" . round($totalCv, 4));
            }

            // ── ordered_at: convert to configured tz, clamp if in future ──
            $nowInTz = now()->setTimezone($timezone);
            $orderedAt = $order->confirmed_at
                ? $order->confirmed_at->copy()->setTimezone($timezone)
                : $nowInTz->copy();

            if ($orderedAt->greaterThan($nowInTz)) {
                Log::warning('Ignite job: ordered_at clamped from future', [
                    'order_id' => $order->id,
                    'original' => $orderedAt->format('Y-m-d H:i:s'),
                    'clamped'  => $nowInTz->format('Y-m-d H:i:s'),
                ]);
                $orderedAt = $nowInTz->copy();
            }
            $orderedAtStr = $orderedAt->format('Y-m-d H:i:s');

            // ── createOrder ──
            $response = $ignite->createOrder(
                baId:            $mainBaId,
                partnerOrderRef: $order->order_reference,
                orderedAt:       $orderedAtStr,
                localAmount:     number_format(round((float) $order->total_payable, 2), 2, '.', ''),
                localCurrency:   $localCurrency,
                lines:           $lines,
                allocations:     $allocations,
                extraTcs:        [],
            );

            $code = $response['code'] ?? null;
            $msg  = $response['msg'] ?? 'unknown';

            // ── SUCCESS ──
            if ($code === ResultCode::OK->value) {
                $this->markOrderPosted($order, $eligibleLines, $response['data'] ?? [], $totalCv);
                $this->forgetMemberCache($mainBaId);

                Log::info('Ignite order posted', [
                    'order_id'        => $order->id,
                    'ref'             => $order->order_reference,
                    'ignite_order_no' => $response['data']['order_no'] ?? null,
                    'total_cv'        => $response['data']['total_cv'] ?? $totalCv,
                    'sales_week'      => $response['data']['sales_week'] ?? null,
                    'settlement_week' => $response['data']['settlement_week'] ?? null,
                    'allocations'     => $allocations,
                ]);
                return;
            }

            // ── DUPLICATE (5001) → success; getOrder best-effort ──
            if ($code === ResultCode::ORDER_DUPLICATE->value) {
                $data = [];
                try {
                    $existing = $ignite->getOrder($order->order_reference);
                    if (($existing['code'] ?? null) === ResultCode::OK->value) {
                        $data = $existing['data'] ?? [];
                    } else {
                        Log::warning('Ignite duplicate: getOrder non-OK, marking posted anyway', [
                            'order_id' => $order->id,
                            'ref'      => $order->order_reference,
                            'code'     => $existing['code'] ?? null,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Ignite duplicate: getOrder threw, marking posted anyway', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }

                $cv = (float) ($data['total_cv'] ?? $totalCv);
                $this->markOrderPosted($order, $eligibleLines, $data, $cv);
                $this->forgetMemberCache($mainBaId);

                Log::info('Ignite order duplicate resolved', [
                    'order_id'        => $order->id,
                    'ref'             => $order->order_reference,
                    'ignite_order_no' => $data['order_no'] ?? null,
                    'total_cv'        => $cv,
                ]);
                return;
            }

            // ── Permanent refusals ──
            if (in_array($code, self::PERMANENT_CODES, true)) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "[{$code}] {$msg}",
                ]);
                Log::error('Ignite order permanently refused', [
                    'order_id' => $order->id,
                    'code'     => $code,
                    'msg'      => $msg,
                ]);
                return;
            }

            // ── Retryable ──
            if ($code === ResultCode::NETWORK_BUSY->value) {
                Log::info('Ignite order: NETWORK_BUSY, releasing 30s', ['order_id' => $order->id]);
                $this->release(30);
                return;
            }
            if ($code === ResultCode::PLACEMENT_REFUSED->value) {
                Log::info('Ignite order: PLACEMENT_REFUSED, releasing 60s', ['order_id' => $order->id]);
                $this->release(60);
                return;
            }
            if ($code === ResultCode::ORDER_WEEK_UNKNOWN->value) {
                Log::warning('Ignite order: ORDER_WEEK_UNKNOWN, releasing 1h', ['order_id' => $order->id]);
                $this->release(3600);
                return;
            }

            throw new \Exception("Ignite Error [{$code}]: {$msg}");

        } catch (\Throwable $e) {
            Log::error("Ignite Job Failed: Order {$order->order_reference}", [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
                'attempt'  => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries || !$this->hasTimeLeft()) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => $e->getMessage(),
                ]);
                return;
            }

            throw $e; // let $backoff / retryUntil drive the next attempt
        }
    }

    private function hasTimeLeft(): bool
    {
        return now()->lt($this->retryUntil());
    }

    protected function markOrderPosted(
        Order $order,
        array $eligibleLines,
        array $respData,
        float $fallbackCv
    ): void {
        $igniteOrderNo = $respData['order_no'] ?? null;
        $totalCv       = round((float) ($respData['total_cv'] ?? $fallbackCv), 2);

        foreach ($eligibleLines as $entry) {
            $entry['line']->update([
                'ignite_order_no'             => $igniteOrderNo,
                'ignite_sync_status'          => 'posted',
                'ignite_sync_error'           => null,
                'ignite_cv_posted'            => round((float) $entry['cv'], 2),
                'ignite_sales_week_year'      => $respData['sales_week']['year']         ?? null,
                'ignite_sales_week_no'        => $respData['sales_week']['week_no']      ?? null,
                'ignite_settlement_week_year' => $respData['settlement_week']['year']    ?? null,
                'ignite_settlement_week_no'   => $respData['settlement_week']['week_no'] ?? null,
            ]);
        }

        $order->update([
            'ignite_order_no'       => $igniteOrderNo,
            'ignite_sync_status'    => 'posted',
            'ignite_sync_error'     => null,
            'commissionable_volume' => $totalCv,
            'sales_week_year'       => $respData['sales_week']['year']         ?? null,
            'sales_week_no'         => $respData['sales_week']['week_no']      ?? null,
            'settlement_week_year'  => $respData['settlement_week']['year']    ?? null,
            'settlement_week_no'    => $respData['settlement_week']['week_no'] ?? null,
        ]);
    }

    protected function forgetMemberCache(string $mainBaId): void
    {
        Cache::forget("ignite_member_{$mainBaId}");
        Cache::forget("ignite_binary_tree_{$mainBaId}");
    }
}