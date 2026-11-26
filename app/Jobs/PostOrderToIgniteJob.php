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
        // ORDER_DISABLED retryable (handled below)
    ];

    public function __construct(public int $orderId)
    {
        $this->onQueue('ignite-sync');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addDays(7);
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

        // FIX: confirmed_at mandatory — no now() fallback
        if (empty($order->confirmed_at)) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => 'confirmed_at missing; cannot compute ordered_at.',
            ]);
            return;
        }

        // FIX: serialize per member to avoid stale cv_allocated race
        $lock = Cache::lock("ignite:alloc:{$mainBaId}", 180);
        if (!$lock->get()) {
            $this->release(30);
            return;
        }

        try {
            $this->process($ignite, $order, $mainBaId, $localCurrency);
        } finally {
            $lock->release();
        }
    }

    private function process(IgniteClient $ignite, Order $order, string $mainBaId, string $localCurrency): void
    {
        try {
            // ── 1. Fetch Catalog ──
            $catalogRes = $ignite->catalog();
            if (($catalogRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("catalog() refused: " . ($catalogRes['msg'] ?? 'unknown'));
            }

            $catalogItems     = collect($catalogRes['data']['items'] ?? []);
            $catalogByRef     = $catalogItems->whereNotNull('partner_product_ref')->groupBy('partner_product_ref');
            $catalogByProduct = $catalogItems->groupBy('product_id');
            $catalogById      = $catalogItems->keyBy('catalog_item_id');

            // ── 2. Map order lines ──
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

                // FIX: prefer snapshot saved at checkout (handles ended promos)
                if (!empty($line->ignite_catalog_item_id)) {
                    $igniteItem = $catalogById->get((int) $line->ignite_catalog_item_id);
                }

                if (!$igniteItem && $product && !empty($product->partner_product_ref)) {
                    $candidates = $catalogByRef->get($product->partner_product_ref, collect());
                    if ($candidates->count() === 1) {
                        $igniteItem = $candidates->first();
                    } elseif ($candidates->count() > 1) {
                        $skippedLines[] = [
                            'line_id'          => $line->id,
                            'product_id'       => $product->id,
                            'reason'           => 'Ambiguous partner_product_ref.',
                            'catalog_item_ids' => $candidates->pluck('catalog_item_id')->all(),
                        ];
                        $line->update([
                            'ignite_sync_status' => 'skipped',
                            'ignite_sync_error'  => 'Ambiguous partner_product_ref.',
                        ]);
                        continue;
                    }
                }

                if (!$igniteItem && $product && !empty($product->ignite_product_id)) {
                    $candidates = $catalogByProduct->get((int) $product->ignite_product_id, collect());
                    if ($candidates->count() === 1) {
                        $igniteItem = $candidates->first();
                    } elseif ($candidates->count() > 1) {
                        $skippedLines[] = [
                            'line_id'          => $line->id,
                            'product_id'       => $product->id,
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

            if (empty($lines) || $totalCv <= 0) {
                $order->update([
                    'ignite_sync_status' => 'skipped',
                    'ignite_sync_error'  => 'No mapped products or zero CV.',
                ]);
                return;
            }

            // ── 3. Plan + Member + Tree ──
            $planRes = $ignite->planRanks();
            if (($planRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("planRanks() refused: " . ($planRes['msg'] ?? 'unknown'));
            }
            $plan          = $planRes['data']['plan'] ?? [];
            $qualifyCv     = (float) ($plan['qualify_cv'] ?? 0);
            $baseProductCv = (float) ($plan['base_product_cv'] ?? 0);

            if ($qualifyCv <= 0) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => 'plan.qualify_cv missing or 0.',
                ]);
                return;
            }

            $memberRes = $ignite->memberDetails($mainBaId);
            if (($memberRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("memberDetails() refused for {$mainBaId}: " . ($memberRes['msg'] ?? 'unknown'));
            }
            $member = $memberRes['data'];

            $treeRes = $ignite->binaryTree($mainBaId, 3);
            if (($treeRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("binaryTree() refused for {$mainBaId}: " . ($treeRes['msg'] ?? 'unknown'));
            }
            $treeRows = collect($treeRes['data']['rows'] ?? []);
            $level1   = $treeRows->where('level', 1);

            $child002 = $level1->firstWhere('binary_side', 1);
            $child003 = $level1->firstWhere('binary_side', 2);

            $ba002 = $child002['ba_id'] ?? null;
            $ba003 = $child003['ba_id'] ?? null;

            if (!$ba002 || !$ba003) {
                $order->update([
                    'ignite_sync_status' => 'pending_placement',
                    'ignite_sync_error'  => 'Member has no -002/-003 (not placed).',
                ]);
                return;
            }

            // ── 4. Allocations ──
            // FIX: use cv_allocated (own-order CV), not cv_left/cv_right (downline CV)
            $mem002Res = $ignite->memberDetails($ba002);
            $mem003Res = $ignite->memberDetails($ba003);
            $mem002    = ($mem002Res['code'] ?? null) === ResultCode::OK->value ? $mem002Res['data'] : [];
            $mem003    = ($mem003Res['code'] ?? null) === ResultCode::OK->value ? $mem003Res['data'] : [];

            $cv001Have = (float) ($member['cv_allocated'] ?? 0);
            $cv002Have = (float) ($mem002['cv_allocated'] ?? 0);
            $cv003Have = (float) ($mem003['cv_allocated'] ?? 0);

            $cv001 = 0.0;
            if (empty($member['qualified_at'])) {
                $cv001 = min(
                    max(0.0, round($qualifyCv - $cv001Have, 4)),
                    round($totalCv, 4)
                );
            }
            $cv001     = round($cv001, 4);
            $remaining = round($totalCv - $cv001, 4);

            $cv002Need = max(0.0, round($qualifyCv - $cv002Have, 4));
            $cv003Need = max(0.0, round($qualifyCv - $cv003Have, 4));

            $cv002     = min($remaining, $cv002Need);
            $remaining = round($remaining - $cv002, 4);

            $cv003     = min($remaining, $cv003Need);
            $remaining = round($remaining - $cv003, 4);

            $allocations = [];
            if ($cv001 > 0.0001) $allocations[] = ['ba_id' => $mainBaId, 'cv' => $this->fmt($cv001)];
            if ($cv002 > 0.0001) $allocations[] = ['ba_id' => $ba002,   'cv' => $this->fmt($cv002)];
            if ($cv003 > 0.0001) $allocations[] = ['ba_id' => $ba003,   'cv' => $this->fmt($cv003)];

            // ── Extra TCs ──
            $extraTcs = [];
            if ($remaining > 0.0001 && $baseProductCv > 0) {
                $extraTcCount = (int) floor($remaining / $baseProductCv);
                $extraTcCv    = round($extraTcCount * $baseProductCv, 4);
                $leftover     = round($remaining - $extraTcCv, 4);

                Log::info('Ignite job: extra TC calc', [
                    'order_id'        => $order->id,
                    'remaining'       => $remaining,
                    'base_product_cv' => $baseProductCv,
                    'extra_tc_count'  => $extraTcCount,
                    'leftover'        => $leftover,
                ]);

                if ($extraTcCount > 0) {
                    // FIX: scan tree for free slots — never blind far_left/right
                    $freeSlots = [];
                    foreach ($treeRows as $row) {
                        if (empty($row['far_left_id'])) {
                            $freeSlots[] = ['placement_member_id' => (int) $row['member_id'], 'side' => 1];
                        }
                        if (empty($row['far_right_id'])) {
                            $freeSlots[] = ['placement_member_id' => (int) $row['member_id'], 'side' => 2];
                        }
                    }

                    if (count($freeSlots) < $extraTcCount) {
                        $order->update([
                            'ignite_sync_status' => 'failed',
                            'ignite_sync_error'  => "Only " . count($freeSlots) . " free slots for {$extraTcCount} TCs (tree depth 3).",
                        ]);
                        return;
                    }

                    for ($i = 0; $i < $extraTcCount; $i++) {
                        $extraTcs[] = $freeSlots[$i];
                        $allocations[] = [
                            'extra_tc' => $i,
                            'cv'       => $this->fmt($baseProductCv),
                        ];
                    }
                }

                // FIX: leftover CV — only allocate if -002/-003 still has room
                if ($leftover > 0.0001) {
                    $placed = false;
                    if ($cv002Have + $cv002 + $leftover <= $qualifyCv + 0.0001) {
                        $allocations[] = ['ba_id' => $ba002, 'cv' => $this->fmt($leftover)];
                        $placed = true;
                    } elseif ($cv003Have + $cv003 + $leftover <= $qualifyCv + 0.0001) {
                        $allocations[] = ['ba_id' => $ba003, 'cv' => $this->fmt($leftover)];
                        $placed = true;
                    }

                    if (!$placed) {
                        $order->update([
                            'ignite_sync_status' => 'failed',
                            'ignite_sync_error'  => "Leftover CV {$leftover} cannot be allocated.",
                        ]);
                        Log::error('Ignite job: leftover CV unallocatable', [
                            'order_id'   => $order->id,
                            'leftover'   => $leftover,
                            'cv002_have' => $cv002Have, 'cv002' => $cv002,
                            'cv003_have' => $cv003Have, 'cv003' => $cv003,
                            'qualify'    => $qualifyCv,
                        ]);
                        return;
                    }
                }
            } elseif ($remaining > 0.0001) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "Unallocated CV {$remaining} but base_product_cv is 0.",
                ]);
                return;
            }

            // FIX: hard-fail on sum mismatch — don't call createOrder
            $allocSum = 0.0;
            foreach ($allocations as $a) $allocSum += (float) $a['cv'];
            $allocSum = round($allocSum, 4);
            if (abs($allocSum - round($totalCv, 4)) > 0.0001) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "Local allocation sum {$allocSum} != order CV {$totalCv}.",
                ]);
                return;
            }

            // ── 5. Sales-week pre-validate ──
            $igniteTz     = new \DateTimeZone(self::IGNITE_TIMEZONE);
            $orderedAt    = $order->confirmed_at->copy()->setTimezone($igniteTz);
            $orderedAtStr = $orderedAt->format('Y-m-d H:i:s');

            if (!$this->validateSalesWeek($ignite, $orderedAt)) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "ordered_at {$orderedAtStr} falls in no sales week.",
                ]);
                return;
            }

            // ── 6. Create Order ──
            $response = $ignite->createOrder(
                baId:            $mainBaId,
                partnerOrderRef: $order->order_reference,
                orderedAt:       $orderedAtStr,
                localAmount:     number_format(round((float) $order->total_payable, 2), 2, '.', ''),
                localCurrency:   $localCurrency,
                lines:           $lines,
                allocations:     $allocations,
                extraTcs:        $extraTcs,
            );

            $code = $response['code'] ?? null;
            $msg  = $response['msg'] ?? 'unknown';

            // ── 7. Success ──
            if ($code === ResultCode::OK->value || $code === ResultCode::ORDER_DUPLICATE->value) {
                $data = $response['data'] ?? [];

                // FIX: trust data.order_no; getOrder only as last resort
                if ($code === ResultCode::ORDER_DUPLICATE->value && empty($data['order_no'])) {
                    $existing = $ignite->getOrder($order->order_reference);
                    if (($existing['code'] ?? null) === ResultCode::OK->value
                        && !empty($existing['data']['order_no'])) {
                        $data = $existing['data'];
                    } else {
                        $this->release(300);
                        return;
                    }
                }

                if (empty($data['order_no'])) {
                    $order->update([
                        'ignite_sync_status' => 'failed',
                        'ignite_sync_error'  => 'Ignite replied success without order_no.',
                    ]);
                    return;
                }

                $this->markOrderPosted($order, $eligibleLines, $data, $totalCv);
                $this->forgetMemberCache($mainBaId);
                Log::info('Ignite order posted', [
                    'order_id'      => $order->id,
                    'skipped_count' => count($skippedLines),
                    'extra_tcs'     => count($extraTcs),
                ]);
                return;
            }

            // ── 8. PRODUCT_NOT_ON_CATALOG ──
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

            // ── 9. Permanent ──
            if (in_array($code, self::PERMANENT_CODES, true)) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "[{$code}] {$msg}",
                ]);
                return;
            }

            // ── 10. Retryable ──
            if ($code === ResultCode::NETWORK_BUSY->value)       { $this->release(30);   return; }
            if ($code === ResultCode::PLACEMENT_REFUSED->value)  { $this->release(60);   return; }
            if ($code === ResultCode::ORDER_WEEK_UNKNOWN->value) { $this->release(3600); return; }
            if ($code === ResultCode::ORDER_DISABLED->value)     { $this->release(3600); return; }

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

    private function validateSalesWeek(IgniteClient $ignite, \DateTimeInterface $orderedAt): bool
    {
        $year   = (int) $orderedAt->format('Y');
        $result = $ignite->salesCalendar($year);

        if (($result['code'] ?? null) !== ResultCode::OK->value) {
            return true; // don't block on calendar failure
        }

        $orderedAtStr = $orderedAt->format('Y-m-d H:i:s');

        foreach ($result['data']['months'] ?? [] as $month) {
            foreach ($month['weeks'] ?? [] as $week) {
                if ($orderedAtStr >= $week['start_at'] && $orderedAtStr < $week['end_at']) {
                    return true;
                }
            }
        }

        // Sales year can spill into next calendar year
        $next = $ignite->salesCalendar($year + 1);
        if (($next['code'] ?? null) === ResultCode::OK->value) {
            foreach ($next['data']['months'] ?? [] as $month) {
                foreach ($month['weeks'] ?? [] as $week) {
                    if ($orderedAtStr >= $week['start_at'] && $orderedAtStr < $week['end_at']) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function fmt(float $v): string
    {
        return number_format($v, 4, '.', '');
    }

    protected function markOrderPosted(Order $order, array $eligibleLines, array $respData, float $fallbackCv): void
    {
        $igniteOrderNo = $respData['order_no'] ?? null;
        $totalCv       = round((float) ($respData['total_cv'] ?? $fallbackCv), 4);

        foreach ($eligibleLines as $entry) {
            $entry['line']->update([
                'ignite_order_no'             => $igniteOrderNo,
                'ignite_sync_status'          => 'posted',
                'ignite_sync_error'           => null,
                'ignite_cv_posted'            => round((float) $entry['cv'], 4),
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