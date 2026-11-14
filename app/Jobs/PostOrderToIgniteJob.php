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
        $timezone      = config('services.ignite.timezone');

        if (!$localCurrency || !$timezone) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => 'Ignite config missing.',
            ]);
            return;
        }

        $mainBaId = preg_replace('/-(002|003)$/', '-001', $user->distributor_id);

        try {
            // ── 1. Fetch Catalog ──
            $catalogRes = $ignite->catalog();
            if (($catalogRes['code'] ?? null) !== ResultCode::OK->value) {
                throw new \Exception("catalog() refused");
            }
            
            $catalogItems     = collect($catalogRes['data']['items'] ?? []);
            $catalogByProduct = $catalogItems->groupBy('product_id');
            $catalogByRef     = $catalogItems->whereNotNull('partner_product_ref')->keyBy('partner_product_ref');

            // ── 2. PRE-FLIGHT: STRICT ALL-OR-NOTHING ──
            $lines         = [];
            $totalCv       = 0.0;
            $eligibleLines = [];
            $unmappedLines = []; // Track missing products

            foreach ($order->lines as $line) {
                if (in_array($line->delivery_status, ['cancelled', 'returned'], true)) {
                    continue; // Cancelled lines are ignored
                }

                $product = $line->product;
                $igniteItem = null;

                // Priority 1: Match by partner_product_ref (if exists in DB)
                if ($product && !empty($product->partner_product_ref)) {
                    $igniteItem = $catalogByRef->get($product->partner_product_ref);
                }

                // Priority 2: Match by ignite_product_id (Main fallback)
                if (!$igniteItem && $product && !empty($product->ignite_product_id)) {
                    $candidates = $catalogByProduct->get((int) $product->ignite_product_id, collect());
                    
                    // If exactly 1 line found, use it. If 0 or >1, it's considered unmapped/ambiguous.
                    if ($candidates->count() === 1) {
                        $igniteItem = $candidates->first();
                    }
                }

                // ❌ If not mapped, add to missing array
                if (!$igniteItem || empty($igniteItem['catalog_item_id'])) {
                    $unmappedLines[] = [
                        'line_id'      => $line->id,
                        'product_id'   => $product ? $product->id : null,
                        'product_name' => $product ? $product->name : 'Unknown Product',
                    ];
                    continue;
                }

                // ✅ If mapped, add to valid lines
                $lineCv = round((float) $igniteItem['cv'] * (int) $line->quantity, 4);
                $lines[] = [
                    'product_id'      => (int) $igniteItem['product_id'],
                    'qty'             => (int) $line->quantity,
                    'catalog_item_id' => (int) $igniteItem['catalog_item_id'],
                ];
                $totalCv += $lineCv;
                $eligibleLines[] = ['line' => $line, 'cv' => $lineCv];
            }

            // 🚨 STRICT ACTION: If ANY product is missing, SKIP ENTIRE ORDER
            if (!empty($unmappedLines)) {
                $order->update([
                    'ignite_sync_status' => 'skipped',
                    'ignite_sync_error'  => 'Order skipped: Missing Ignite mapping.',
                ]);
                Log::error('Ignite job: Entire order skipped due to unmapped products.', [
                    'order_id' => $order->id,
                    'missing'  => $unmappedLines
                ]);
                return; // Exit: No API call to Ignite
            }

            if (empty($lines)) {
                $order->update([
                    'ignite_sync_status' => 'skipped',
                    'ignite_sync_error'  => 'No eligible lines to post.',
                ]);
                return;
            }

            // ── 3. Fetch Plan, Member & Tree ──
            $planRes   = $ignite->planRanks();
            $qualifyCv = (float) ($planRes['data']['plan']['qualify_cv'] ?? 0);

            $memberRes = $ignite->memberDetails($mainBaId);
            $member    = $memberRes['data'] ?? [];

            $treeRes = $ignite->binaryTree($mainBaId, 1);
            $level1  = collect($treeRes['data']['rows'] ?? [])->where('level', 1);
            $ba002   = $level1->firstWhere('binary_side', 1)['ba_id'] ?? null;
            $ba003   = $level1->firstWhere('binary_side', 2)['ba_id'] ?? null;

            if (!$ba002 || !$ba003) {
                $order->update([
                    'ignite_sync_status' => 'pending_placement',
                    'ignite_sync_error'  => 'Member has no -002/-003 (not placed).',
                ]);
                return;
            }

            // ── 4. Calculate Allocations (Order-wise) ──
            $cv001 = 0.0;
            if (empty($member['qualified_at'])) {
                $cv001 = min(max(0.0, round($qualifyCv - (float) ($member['cv_allocated'] ?? 0), 4)), round($totalCv, 4));
            }
            $cv001 = round($cv001, 4);
            $remaining = round($totalCv - $cv001, 4);
            $cv002     = round($remaining / 2, 4);
            $cv003     = round($remaining - $cv002, 4);

            $allocations = [];
            if ($cv001 > 0) $allocations[] = ['ba_id' => $mainBaId, 'cv' => number_format($cv001, 4, '.', '')];
            if ($cv002 > 0) $allocations[] = ['ba_id' => $ba002,   'cv' => number_format($cv002, 4, '.', '')];
            if ($cv003 > 0) $allocations[] = ['ba_id' => $ba003,   'cv' => number_format($cv003, 4, '.', '')];

            // ── 5. Create Order on Ignite (ONE API CALL) ──
            $orderedAt = $order->confirmed_at ? $order->confirmed_at->copy()->setTimezone($timezone) : now()->setTimezone($timezone);
            
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

            // ── 6. Success Handling ──
            if ($code === ResultCode::OK->value || $code === ResultCode::ORDER_DUPLICATE->value) {
                $data = $response['data'] ?? [];
                if ($code === ResultCode::ORDER_DUPLICATE->value) {
                    $existing = $ignite->getOrder($order->order_reference);
                    $data = $existing['data'] ?? [];
                }

                $this->markOrderPosted($order, $eligibleLines, $data, $totalCv);
                $this->forgetMemberCache($mainBaId);

                Log::info('Ignite order posted successfully', ['order_id' => $order->id]);
                return;
            }

            // ── 7. Error Handling ──
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