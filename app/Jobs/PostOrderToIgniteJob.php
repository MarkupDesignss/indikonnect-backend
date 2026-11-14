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

        if ($order->ignite_sync_status === 'posted' && !empty($order->ignite_order_no)) {
            Log::info('Ignite job: already posted, skipping', ['order_id' => $order->id]);
            return;
        }

        $user = $order->user;
        if (!$user || empty($user->distributor_id) || $order->order_type !== 'distributor') {
            $order->update([
                'ignite_sync_status' => 'skipped',
                'ignite_sync_error'  => 'Not a distributor order or missing distributor_id.',
            ]);
            Log::info('Ignite job: skipped', ['order_id' => $order->id]);
            return;
        }

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

        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => "Invalid Ignite timezone: {$timezone}",
            ]);
            Log::error('Ignite job: invalid timezone', ['tz' => $timezone]);
            return;
        }

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
                throw new \Exception("catalog() refused: {$catalogRes['code']} " . ($catalogRes['msg'] ?? 'unknown'));
            }
            $catalogByProduct = collect($catalogRes['data']['items'] ?? [])->groupBy('product_id');

            // ── Pre-flight: build eligible lines; skip unmapped/multi/unsellable ──
            $lines         = [];
            $totalCv       = 0.0;
            $skippedLines  = [];
            $eligibleLines = [];

            foreach ($order->lines as $line) {
                if (in_array($line->delivery_status, ['cancelled', 'returned'], true)) {
                    $skippedLines[] = ['line_id' => $line->id, 'reason' => 'cancelled_or_returned'];
                    continue;
                }

                $product = $line->product;
                if (!$product || empty($product->ignite_product_id)) {
                    $skippedLines[] = ['line_id' => $line->id, 'reason' => 'no_ignite_product_id'];
                    continue;
                }

                $candidates = $catalogByProduct->get((int) $product->ignite_product_id, collect());
                if ($candidates->isEmpty()) {
                    $skippedLines[] = [
                        'line_id'           => $line->id,
                        'reason'            => 'not_in_ignite_catalog',
                        'ignite_product_id' => $product->ignite_product_id,
                    ];
                    continue;
                }

                if ($candidates->count() > 1) {
                    $skippedLines[] = [
                        'line_id'           => $line->id,
                        'reason'            => 'multiple_catalog_lines',
                        'ignite_product_id' => $product->ignite_product_id,
                        'catalog_item_ids'  => $candidates->pluck('catalog_item_id')->all(),
                    ];
                    continue;
                }

                $igniteItem = $candidates->first();
                if (empty($igniteItem['catalog_item_id'])) {
                    $skippedLines[] = ['line_id' => $line->id, 'reason' => 'no_catalog_item_id'];
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

            if (!empty($skippedLines)) {
                Log::warning('Ignite job: some lines skipped', [
                    'order_id' => $order->id,
                    'skipped'  => $skippedLines,
                ]);
            }

            // ── No eligible lines at all → mark skipped, do not post ──
            if (empty($lines)) {
                $order->update([
                    'ignite_sync_status' => 'skipped',
                    'ignite_sync_error'  => 'No eligible lines to post to Ignite.',
                ]);
                Log::warning('Ignite job: no postable lines, order skipped', ['order_id' => $order->id]);
                return;
            }

            // ── Plan figures ──
            $planRes = $ignite->planRanks();
            if (($planRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("planRanks() refused: {$planRes['code']} " . ($planRes['msg'] ?? 'unknown'));
            }
            $qualifyCv = (float) ($planRes['data']['plan']['qualify_cv'] ?? 0);
            if ($qualifyCv <= 0) {
                throw new \Exception('planRanks returned qualify_cv <= 0.');
            }

            // ── Member ──
            $memberRes = $ignite->memberDetails($mainBaId);
            if (($memberRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("memberDetails({$mainBaId}) refused: {$memberRes['code']} " . ($memberRes['msg'] ?? 'unknown'));
            }
            $member = $memberRes['data'] ?? [];

            // ── Binary tree ──
            $treeRes = $ignite->binaryTree($mainBaId, 1);
            if (($treeRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("binaryTree({$mainBaId}) refused: {$treeRes['code']} " . ($treeRes['msg'] ?? 'unknown'));
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

            // ── Allocations ──
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

            // ── ordered_at ──
            $nowInTz = now()->setTimezone($timezone);
            $orderedAt = $order->confirmed_at
                ? $order->confirmed_at->copy()->setTimezone($timezone)
                : $nowInTz->copy();
            if ($orderedAt->greaterThan($nowInTz)) {
                Log::warning('Ignite job: ordered_at clamped from future', ['order_id' => $order->id]);
                $orderedAt = $nowInTz->copy();
            }

            // ── createOrder ──
            $response = $ignite->createOrder(
                baId:            $mainBaId,
                partnerOrderRef: $order->order_reference,
                orderedAt:       $orderedAt->format('Y-m-d H:i:s'),
                localAmount:     number_format(round((float) $order->total_payable, 2), 2, '.', ''),
                localCurrency:   $localCurrency,
                lines:           $lines,
                allocations:     $allocations,
                extraTcs:        [],
            );

            $code = $response['code'] ?? null;
            $msg  = $response['msg'] ?? 'unknown';

            if ($code === ResultCode::OK->value) {
                $this->markOrderPosted($order, $eligibleLines, $response['data'] ?? [], $totalCv);
                $this->forgetMemberCache($mainBaId);

                Log::info('Ignite order posted (partial if lines skipped)', [
                    'order_id'         => $order->id,
                    'ref'              => $order->order_reference,
                    'ignite_order_no'  => $response['data']['order_no'] ?? null,
                    'total_cv'         => $response['data']['total_cv'] ?? $totalCv,
                    'posted_lines'     => count($lines),
                    'skipped_lines'    => $skippedLines,
                ]);
                return;
            }

            // Duplicate → treat as success
            if ($code === ResultCode::ORDER_DUPLICATE->value) {
                $data = [];
                try {
                    $existing = $ignite->getOrder($order->order_reference);
                    if (($existing['code'] ?? null) === ResultCode::OK->value) {
                        $data = $existing['data'] ?? [];
                    }
                } catch (\Throwable $e) {
                    Log::warning('Ignite duplicate: getOrder threw', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }

                $cv = (float) ($data['total_cv'] ?? $totalCv);
                $this->markOrderPosted($order, $eligibleLines, $data, $cv);
                $this->forgetMemberCache($mainBaId);

                Log::info('Ignite order duplicate resolved', ['order_id' => $order->id]);
                return;
            }

            if (in_array($code, self::PERMANENT_CODES, true)) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "[{$code}] {$msg}",
                ]);
                Log::error('Ignite order permanently refused', ['order_id' => $order->id, 'code' => $code, 'msg' => $msg]);
                return;
            }

            if ($code === ResultCode::NETWORK_BUSY->value) {
                $this->release(30);
                return;
            }
            if ($code === ResultCode::PLACEMENT_REFUSED->value) {
                $this->release(60);
                return;
            }
            if ($code === ResultCode::ORDER_WEEK_UNKNOWN->value) {
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

            if ($this->attempts() >= $this->tries || !now()->lt($this->retryUntil())) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => $e->getMessage(),
                ]);
                return;
            }

            throw $e;
        }
    }

    protected function markOrderPosted(Order $order, array $eligibleLines, array $respData, float $fallbackCv): void
    {
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