<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderLine;
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

    private const IGNITE_TIMEZONE = 'Asia/Hong_Kong';

    private const PERMANENT_CODES = [
        ResultCode::VALIDATION_FAILED->value,
        ResultCode::MEMBER_NOT_FOUND->value,
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
        if (!$order) return;

        if ($order->ignite_sync_status === 'posted' && !empty($order->ignite_order_no)) {
            return;
        }

        $user = $order->user;
        if (!$user || empty($user->distributor_id) || $order->order_type !== 'distributor') {
            $order->update([
                'ignite_sync_status' => 'skipped',
                'ignite_sync_error'  => 'Not a distributor order or missing distributor_id.',
            ]);
            return;
        }

        $localCurrency = config('services.ignite.local_currency');
        if (!$localCurrency) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => 'Ignite local_currency config missing.',
            ]);
            return;
        }

        $mainBaId = preg_replace('/-(002|003)$/', '-001', $user->distributor_id);

        try {
            // ── 1. Fetch Catalog ──
            $catalogRes = $ignite->catalog();
            if (($catalogRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("catalog() refused: " . ($catalogRes['msg'] ?? 'unknown'));
            }

            $catalogItems     = collect($catalogRes['data']['items'] ?? []);
            $catalogByRef     = $catalogItems->whereNotNull('partner_product_ref')->keyBy('partner_product_ref');
            $catalogByProduct = $catalogItems->groupBy('product_id');

            // ── 2. MAP ORDER LINES (partial allowed — skipped lines don't block) ──
            $lines         = [];
            $totalCv       = 0.0;
            $eligibleLines = [];
            $skippedLines  = [];

            foreach ($order->lines as $line) {
                if (in_array($line->delivery_status, ['cancelled', 'returned'], true)) {
                    continue;
                }

                $product    = $line->product;
                $igniteItem = null;

                if ($product && !empty($product->partner_product_ref)) {
                    $igniteItem = $catalogByRef->get($product->partner_product_ref);
                }

                if (!$igniteItem && $product && !empty($product->ignite_product_id)) {
                    $candidates = $catalogByProduct->get((int) $product->ignite_product_id, collect());
                    if ($candidates->count() === 1) {
                        $igniteItem = $candidates->first();
                    } elseif ($candidates->count() > 1) {
                        $skippedLines[] = [
                            'line_id'          => $line->id,
                            'product_id'       => $product->id,
                            'product_name'     => $product->name,
                            'reason'           => 'Multiple catalog lines; no partner_product_ref.',
                            'catalog_item_ids' => $candidates->pluck('catalog_item_id')->all(),
                        ];
                        $line->update([
                            'ignite_sync_status' => 'skipped',
                            'ignite_sync_error'  => 'Multiple catalog lines & no partner_product_ref.',
                        ]);
                        continue;
                    }
                }

                if (!$igniteItem || empty($igniteItem['catalog_item_id'])) {
                    $skippedLines[] = [
                        'line_id'      => $line->id,
                        'product_id'   => $product?->id,
                        'product_name' => $product?->name ?? 'Unknown Product',
                        'product_code' => $product?->product_code,
                        'reason'       => 'No catalog_item_id resolvable.',
                    ];
                    $line->update([
                        'ignite_sync_status' => 'skipped',
                        'ignite_sync_error'  => 'No catalog_item_id resolvable.',
                    ]);
                    continue;
                }

                $lineCv = round((float) $igniteItem['cv'] * (int) $line->quantity, 4);

                $lines[] = [
                    'product_id'      => (int) $igniteItem['product_id'],
                    'qty'             => (int) $line->quantity,
                    'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
                ];
                $totalCv         += $lineCv;
                $eligibleLines[] = [
                    'line'            => $line,
                    'cv'              => $lineCv,
                    'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
                ];
            }

            if (!empty($skippedLines)) {
                Log::warning('Ignite job: partial order — some lines skipped', [
                    'order_id' => $order->id,
                    'skipped'  => $skippedLines,
                ]);
            }

            if (empty($lines)) {
                $order->update([
                    'ignite_sync_status' => 'skipped',
                    'ignite_sync_error'  => 'No mapped products found for this order.',
                ]);
                return;
            }

            // ── 3. Fetch Plan, Member & Tree ──
            $planRes = $ignite->planRanks();
            if (($planRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("planRanks() refused: " . ($planRes['msg'] ?? 'unknown'));
            }
            $plan          = $planRes['data']['plan'] ?? [];
            $qualifyCv     = (float) ($plan['qualify_cv'] ?? 0);
            $baseProductCv = (float) ($plan['base_product_cv'] ?? 600);

            $memberRes = $ignite->memberDetails($mainBaId);
            if (($memberRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("memberDetails() refused for {$mainBaId}: " . ($memberRes['msg'] ?? 'unknown'));
            }
            $member = $memberRes['data'];

            $treeRes = $ignite->binaryTree($mainBaId, 1);
            if (($treeRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("binaryTree() refused for {$mainBaId}: " . ($treeRes['msg'] ?? 'unknown'));
            }
            $level1 = collect($treeRes['data']['rows'] ?? [])->where('level', 1);
            $ba002  = $level1->firstWhere('binary_side', 1)['ba_id'] ?? null;
            $ba003  = $level1->firstWhere('binary_side', 2)['ba_id'] ?? null;

            if (!$ba002 || !$ba003) {
                $order->update([
                    'ignite_sync_status' => 'pending_placement',
                    'ignite_sync_error'  => 'Member has no -002/-003 (not placed).',
                ]);
                return;
            }

            // ── 4. Allocations (partial-safe with extra TC handling) ──
            $cv001 = 0.0;
            if (empty($member['qualified_at'])) {
                $cv001 = min(
                    max(0.0, round($qualifyCv - (float) ($member['cv_allocated'] ?? 0), 4)),
                    round($totalCv, 4)
                );
            }
            $cv001     = round($cv001, 4);
            $remaining = round($totalCv - $cv001, 4);

            // Fetch current CV in -002 and -003
            $child002 = $level1->firstWhere('binary_side', 1);
            $child003 = $level1->firstWhere('binary_side', 2);

            $cv002Have = (float) ($child002['cv_left'] ?? 0) + (float) ($child002['cv_right'] ?? 0);
            $cv003Have = (float) ($child003['cv_left'] ?? 0) + (float) ($child003['cv_right'] ?? 0);

            $cv002Need = max(0.0, round($qualifyCv - $cv002Have, 4));
            $cv003Need = max(0.0, round($qualifyCv - $cv003Have, 4));

            // Fill -002 first
            $cv002 = min($remaining, $cv002Need);
            $remaining = round($remaining - $cv002, 4);

            // Then -003
            $cv003 = min($remaining, $cv003Need);
            $remaining = round($remaining - $cv003, 4);

            $allocations = [];
            if ($cv001 > 0) $allocations[] = ['ba_id' => $mainBaId, 'cv' => number_format($cv001, 4, '.', '')];
            if ($cv002 > 0) $allocations[] = ['ba_id' => $ba002,   'cv' => number_format($cv002, 4, '.', '')];
            if ($cv003 > 0) $allocations[] = ['ba_id' => $ba003,   'cv' => number_format($cv003, 4, '.', '')];

            // ── Extra TC handling (if remaining CV exists) ──
            $extraTcs = [];
            if ($remaining > 0.0001 && $baseProductCv > 0) {
                $extraTcCount = (int) floor($remaining / $baseProductCv);
                $extraTcCv    = round($extraTcCount * $baseProductCv, 4);
                $leftover     = round($remaining - $extraTcCv, 4);

                Log::info('Ignite job: extra TC calculation', [
                    'order_id'       => $order->id,
                    'remaining'      => $remaining,
                    'base_product_cv'=> $baseProductCv,
                    'extra_tc_count' => $extraTcCount,
                    'extra_tc_cv'    => $extraTcCv,
                    'leftover'       => $leftover,
                ]);

                if ($extraTcCount > 0) {
                    // Placement parent dhundho: buyer's far_left_id / far_right_id
                    // Simple strategy: alternate sides, use far ends from memberDetails
                    $farLeftId  = $member['far_left_id']  ?? null;
                    $farRightId = $member['far_right_id'] ?? null;

                    // Prefer placing under buyer's own -002/-003 (safer)
                    $placementParentId = $farLeftId ?? $farRightId ?? ($child002['member_id'] ?? null);

                    if (!$placementParentId) {
                        // Fallback: place under -002
                        $placementParentId = $child002['member_id'] ?? null;
                    }

                    if (!$placementParentId) {
                        $order->update([
                            'ignite_sync_status' => 'failed',
                            'ignite_sync_error'  => "Cannot place extra TC — no valid placement parent. Remaining CV: {$remaining}.",
                        ]);
                        Log::error('Ignite job: no valid extra TC placement parent', [
                            'order_id'  => $order->id,
                            'remaining' => $remaining,
                        ]);
                        return;
                    }

                    // Create extra TC requests
                    for ($i = 0; $i < $extraTcCount; $i++) {
                        $extraTcs[] = [
                            'placement_member_id' => (int) $placementParentId,
                            'side'                => ($i % 2 === 0) ? 1 : 2,  // alternate
                        ];
                        $allocations[] = [
                            'extra_tc' => $i,
                            'cv'       => number_format($baseProductCv, 4, '.', ''),
                        ];
                    }
                }

                // Leftover CV — absorb into -001 if unqualified, otherwise -002
                if ($leftover > 0.0001) {
                    if (empty($member['qualified_at']) && $cv001 < $qualifyCv) {
                        // Add leftover to -001
                        $allocations[] = ['ba_id' => $mainBaId, 'cv' => number_format($leftover, 4, '.', '')];
                    } else {
                        // Add leftover to -002
                        $allocations[] = ['ba_id' => $ba002, 'cv' => number_format($leftover, 4, '.', '')];
                    }
                    Log::info('Ignite job: leftover CV absorbed', [
                        'order_id' => $order->id,
                        'leftover' => $leftover,
                    ]);
                }
            } elseif ($remaining > 0.0001) {
                // base_product_cv is 0 — can't compute extra TCs. Fail.
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "Unallocated CV {$remaining} but base_product_cv is 0.",
                ]);
                Log::error('Ignite job: cannot allocate remaining CV', [
                    'order_id'       => $order->id,
                    'remaining'      => $remaining,
                    'base_product_cv'=> $baseProductCv,
                ]);
                return;
            }

            // Verify allocations sum = totalCv (sanity check)
            $allocSum = 0.0;
            foreach ($allocations as $a) {
                $allocSum += (float) $a['cv'];
            }
            $allocSum = round($allocSum, 4);
            if (abs($allocSum - round($totalCv, 4)) > 0.01) {
                Log::warning('Ignite job: allocation sum mismatch', [
                    'order_id'     => $order->id,
                    'total_cv'     => $totalCv,
                    'alloc_sum'    => $allocSum,
                    'diff'         => $allocSum - $totalCv,
                ]);
            }

            // ── 5. Create Order ──
            $igniteTz  = new \DateTimeZone(self::IGNITE_TIMEZONE);
            $orderedAt = $order->confirmed_at
                ? $order->confirmed_at->copy()->setTimezone($igniteTz)
                : now($igniteTz);

            $response = $ignite->createOrder(
                baId:            $mainBaId,
                partnerOrderRef: $order->order_reference,
                orderedAt:       $orderedAt->format('Y-m-d H:i:s'),
                localAmount:     number_format(round((float) $order->total_payable, 2), 2, '.', ''),
                localCurrency:   $localCurrency,
                lines:           $lines,
                allocations:     $allocations,
                extraTcs:        $extraTcs,
            );

            $code = $response['code'] ?? null;
            $msg  = $response['msg'] ?? 'unknown';

            // ── 6. Success ──
            if ($code === ResultCode::OK->value || $code === ResultCode::ORDER_DUPLICATE->value) {
                $data = $response['data'] ?? [];
                if ($code === ResultCode::ORDER_DUPLICATE->value) {
                    $existing = $ignite->getOrder($order->order_reference);
                    $data = $existing['data'] ?? [];
                }
                $this->markOrderPosted($order, $eligibleLines, $data, $totalCv);
                $this->forgetMemberCache($mainBaId);
                Log::info('Ignite order posted (partial if lines skipped)', [
                    'order_id'      => $order->id,
                    'skipped_count' => count($skippedLines),
                    'extra_tcs'     => count($extraTcs),
                ]);
                return;
            }

            // ── 7. PRODUCT_NOT_ON_CATALOG ──
            if ($code === ResultCode::PRODUCT_NOT_ON_CATALOG->value) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "[{$code}] {$msg}",
                ]);
                Log::error('Ignite refused catalog lines', [
                    'order_id'         => $order->id,
                    'lines'            => $response['data']['lines']            ?? [],
                    'product_ids'      => $response['data']['product_ids']      ?? [],
                    'catalog_item_ids' => $response['data']['catalog_item_ids'] ?? [],
                ]);
                return;
            }

            // ── 8. Retryable / permanent ──
            if (in_array($code, self::PERMANENT_CODES, true)) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "[{$code}] {$msg}",
                ]);
                return;
            }

            if ($code === ResultCode::NETWORK_BUSY->value) { $this->release(30); return; }
            if ($code === ResultCode::PLACEMENT_REFUSED->value) { $this->release(60); return; }
            if ($code === ResultCode::ORDER_WEEK_UNKNOWN->value) { $this->release(3600); return; }

            throw new \Exception("Ignite Error [{$code}]: {$msg}");

        } catch (\Throwable $e) {
            Log::error("Ignite Job Failed: Order {$order->order_reference}", [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
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
                'ignite_catalog_item_id'      => $entry['catalog_item_id'] ?? null,
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