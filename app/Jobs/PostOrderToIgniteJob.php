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

    // SDK comment #3: all times are UTC+8 (Hong Kong). Never read from config.
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
        if (!$order) {
            return;
        }

        // Idempotency check
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

            $catalogItems = collect($catalogRes['data']['items'] ?? []);
            $catalogByRef = $catalogItems->whereNotNull('partner_product_ref')->keyBy('partner_product_ref');
            $catalogByProduct = $catalogItems->groupBy('product_id');

            // ── 2. PRE-FLIGHT: MAP ORDER LINES ──
            $lines         = [];
            $totalCv       = 0.0;
            $eligibleLines = [];
            $skippedLines  = [];

            foreach ($order->lines as $line) {
                if (in_array($line->delivery_status, ['cancelled', 'returned'], true)) {
                    continue; // Cancelled / returned lines ignored
                }

                $product    = $line->product;
                $igniteItem = null;

                // SDK comment #1: partner_product_ref is the primary mapping key.
                if ($product && !empty($product->partner_product_ref)) {
                    $igniteItem = $catalogByRef->get($product->partner_product_ref);
                }

                // Safe fallback via ignite_product_id — only when unambiguous.
                if (!$igniteItem && $product && !empty($product->ignite_product_id)) {
                    $candidates = $catalogByProduct->get((int) $product->ignite_product_id, collect());

                    if ($candidates->count() === 1) {
                        $igniteItem = $candidates->first();
                    } elseif ($candidates->count() > 1) {
                        // Ambiguous — refuse rather than guess (SDK comment #2).
                        $skippedLines[] = [
                            'line_id'          => $line->id,
                            'product_id'       => $product->id,
                            'reason'           => 'Multiple catalog lines; no partner_product_ref set.',
                            'catalog_item_ids' => $candidates->pluck('catalog_item_id')->all(),
                        ];
                        $line->update([
                            'ignite_sync_status' => 'skipped',
                            'ignite_sync_error'  => 'Multiple catalog lines & no partner_product_ref — set it on the product.',
                        ]);
                        continue;
                    }
                }

                // SDK comment #2: catalog_item_id MUST be sent so promos/past prices work.
                if (!$igniteItem || empty($igniteItem['catalog_item_id'])) {
                    $skippedLines[] = [
                        'line_id'      => $line->id,
                        'product_id'   => $product?->id,
                        'product_name' => $product?->name ?? 'Unknown Product',
                        'reason'       => 'No catalog_item_id resolvable via partner_product_ref.',
                    ];
                    $line->update([
                        'ignite_sync_status' => 'skipped',
                        'ignite_sync_error'  => 'No catalog_item_id resolvable via partner_product_ref.',
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

            if (empty($lines)) {
                $order->update([
                    'ignite_sync_status' => 'skipped',
                    'ignite_sync_error'  => 'No mapped products found for this order.',
                ]);
                Log::warning('Ignite job: Entire order skipped (no mapped products).', [
                    'order_id' => $order->id,
                ]);
                return;
            }

            if (!empty($skippedLines)) {
                Log::warning('Ignite job: Some lines skipped (unmapped).', [
                    'order_id' => $order->id,
                    'skipped'  => $skippedLines,
                ]);
            }

            // ── 3. Fetch Plan, Member & Tree ──
            $planRes = $ignite->planRanks();
            if (($planRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("planRanks() refused: " . ($planRes['msg'] ?? 'unknown'));
            }
            $qualifyCv = (float) ($planRes['data']['plan']['qualify_cv'] ?? 0);

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

            // ── 4. Allocations ──
            $cv001 = 0.0;
            if (empty($member['qualified_at'])) {
                $cv001 = min(
                    max(0.0, round($qualifyCv - (float) ($member['cv_allocated'] ?? 0), 4)),
                    round($totalCv, 4)
                );
            }
            $cv001     = round($cv001, 4);
            $remaining = round($totalCv - $cv001, 4);

            // -002 and -003 must each hold qualify_cv before anything else.
            $child002 = $level1->firstWhere('binary_side', 1);
            $child003 = $level1->firstWhere('binary_side', 2);

            $cv002Have = (float) ($child002['cv_left'] ?? 0) + (float) ($child002['cv_right'] ?? 0);
            $cv003Have = (float) ($child003['cv_left'] ?? 0) + (float) ($child003['cv_right'] ?? 0);

            $cv002Need = max(0.0, round($qualifyCv - $cv002Have, 4));
            $cv003Need = max(0.0, round($qualifyCv - $cv003Have, 4));

            $cv002 = min($remaining, $cv002Need);
            $remaining -= $cv002;

            $cv003 = min($remaining, $cv003Need);
            $remaining -= $cv003;

            if ($remaining > 0.0001) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => 'Partial order cannot fill -001/-002/-003 to qualify_cv; '
                                          . 'no extra TC logic implemented. Remaining CV: ' . $remaining,
                ]);
                Log::error('Ignite job: partial order cannot be allocated', [
                    'order_id'  => $order->id,
                    'remaining' => $remaining,
                ]);
                return;
            }

            $allocations = [];
            if ($cv001 > 0) $allocations[] = ['ba_id' => $mainBaId, 'cv' => number_format($cv001, 4, '.', '')];
            if ($cv002 > 0) $allocations[] = ['ba_id' => $ba002,   'cv' => number_format($cv002, 4, '.', '')];
            if ($cv003 > 0) $allocations[] = ['ba_id' => $ba003,   'cv' => number_format($cv003, 4, '.', '')];

            // ── 5. Create Order ──
            // SDK comment #3: ordered_at MUST be in Asia/Hong_Kong (+8)
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
                extraTcs:        [],
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

                Log::info('Ignite order posted (partial if lines skipped)', ['order_id' => $order->id]);
                return;
            }

            // ── 7. PRODUCT_NOT_ON_CATALOG (SDK comment #2) ──
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