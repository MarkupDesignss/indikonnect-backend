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

    public $tries = 5;
    public $timeout = 120;

    public function __construct(public int $orderId) {}

    public function handle(IgniteClient $ignite): void
    {
        Log::info('Ignite line-wise job started', [
            'order_id' => $this->orderId,
            'attempt'  => $this->attempts(),
        ]);

        $order = Order::with(['lines.product', 'user'])->find($this->orderId);

        if (!$order) {
            Log::warning('Ignite job: order not found', ['order_id' => $this->orderId]);
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

        // Config guards
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

        $tzObj  = new \DateTimeZone($timezone);
        $offset = $tzObj->getOffset(new \DateTime('now', $tzObj));
        if ($offset !== 28800) {
            $order->update([
                'ignite_sync_status' => 'failed',
                'ignite_sync_error'  => "Ignite timezone must be UTC+8, got {$timezone}.",
            ]);
            Log::error('Ignite job: timezone not UTC+8', ['tz' => $timezone]);
            return;
        }

        $mainBaId = $this->normalizeMainBaId($user->distributor_id);

        try {
            // Cache once per job
            $catalog    = Cache::remember('ignite_catalog', 1800, fn() => $ignite->catalog()['data']['items'] ?? []);
            $catalogMap = collect($catalog)->keyBy('product_id');

            $member    = Cache::remember("ignite_member_{$mainBaId}", 300, fn() => $ignite->memberDetails($mainBaId)['data'] ?? []);
            $qualifyCv = (float) Cache::remember('ignite_qualify_cv', 3600, fn() => $ignite->planRanks()['data']['plan']['qualify_cv'] ?? 0);

            $tree   = Cache::remember("ignite_binary_tree_{$mainBaId}", 300, fn() => $ignite->binaryTree($mainBaId, 1)['data']['rows'] ?? []);
            $level1 = collect($tree)->where('level', 1);

            $ba002 = $level1->firstWhere('binary_side', 1)['ba_id'] ?? null;
            $ba003 = $level1->firstWhere('binary_side', 2)['ba_id'] ?? null;

            if (!$ba002 || !$ba003) {
                $order->update([
                    'ignite_sync_status' => 'pending_placement',
                    'ignite_sync_error'  => 'Member has no -002/-003 (not yet placed).',
                ]);
                Log::warning('Ignite job: member not placed, parked', ['order_id' => $order->id]);
                return;
            }

            $anyPosted   = false;
            $anyFailed   = false;
            $anyPostedCv = 0.0;

            foreach ($order->lines as $line) {
                // Idempotency — already posted
                if ($line->ignite_sync_status === 'posted') {
                    $anyPosted   = true;
                    $anyPostedCv += (float) ($line->ignite_cv_posted ?? 0);
                    continue;
                }

                // Skip cancelled/returned lines
                if (in_array($line->delivery_status, ['cancelled', 'returned'], true)) {
                    $line->update([
                        'ignite_sync_status' => 'skipped',
                        'ignite_sync_error'  => 'Line cancelled/returned before Ignite post.',
                    ]);
                    continue;
                }

                $product = $line->product;
                if (!$product || !$product->ignite_product_id) {
                    $line->update([
                        'ignite_sync_status' => 'failed',
                        'ignite_sync_error'  => 'Missing ignite_product_id.',
                    ]);
                    $anyFailed = true;
                    Log::warning('Ignite line: missing ignite_product_id', [
                        'order_id' => $order->id, 'line_id' => $line->id,
                    ]);
                    continue;
                }

                $igniteItem = $catalogMap->get($product->ignite_product_id);
                if (!$igniteItem) {
                    $line->update([
                        'ignite_sync_status' => 'failed',
                        'ignite_sync_error'  => "Product {$product->ignite_product_id} not on Ignite catalog.",
                    ]);
                    $anyFailed = true;
                    Log::warning('Ignite line: product not on catalog', [
                        'order_id' => $order->id, 'line_id' => $line->id,
                        'ignite_product_id' => $product->ignite_product_id,
                    ]);
                    continue;
                }

                $lineCv = round((float) $igniteItem['cv'] * $line->quantity, 2);

                $partnerRef = $this->buildPartnerRef($order->order_reference, $line->id);

                $allocations = $this->buildLineAllocations(
                    $lineCv, $mainBaId, $ba002, $ba003, $member, $qualifyCv
                );

                $lineAmount = (string) round((float) $line->line_total, 2);

                $orderedAt = $order->confirmed_at
                    ? $order->confirmed_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s')
                    : now()->setTimezone($timezone)->format('Y-m-d H:i:s');

                try {
                    $response = $ignite->createOrder(
                        baId:            $mainBaId,
                        partnerOrderRef: $partnerRef,
                        orderedAt:       $orderedAt,
                        localAmount:     $lineAmount,
                        localCurrency:   $localCurrency,
                        lines:           [['product_id' => (int) $product->ignite_product_id, 'qty' => (int) $line->quantity]],
                        allocations:     $allocations,
                        extraTcs:        [],
                    );
                } catch (\Throwable $e) {
                    $line->update([
                        'ignite_sync_status' => 'failed',
                        'ignite_sync_error'  => 'Ignite threw: ' . $e->getMessage(),
                    ]);
                    $anyFailed = true;
                    Log::error('Ignite line: createOrder threw', [
                        'order_id' => $order->id, 'line_id' => $line->id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                // Success
                if (($response['code'] ?? null) === ResultCode::OK->value) {
                    $line->update([
                        'ignite_order_no'             => $response['data']['order_no'] ?? null,
                        'ignite_sync_status'          => 'posted',
                        'ignite_sync_error'           => null,
                        'ignite_cv_posted'            => $response['data']['total_cv'] ?? $lineCv,
                        'ignite_sales_week_year'      => $response['data']['sales_week']['year']       ?? null,
                        'ignite_sales_week_no'        => $response['data']['sales_week']['week_no']    ?? null,
                        'ignite_settlement_week_year' => $response['data']['settlement_week']['year']  ?? null,
                        'ignite_settlement_week_no'   => $response['data']['settlement_week']['week_no'] ?? null,
                    ]);
                    $anyPosted   = true;
                    $anyPostedCv += (float) ($response['data']['total_cv'] ?? $lineCv);

                    Log::info('Ignite line posted', [
                        'order_id'        => $order->id,
                        'line_id'         => $line->id,
                        'ref'             => $partnerRef,
                        'ignite_order_no' => $response['data']['order_no'] ?? null,
                        'cv'              => $response['data']['total_cv'] ?? $lineCv,
                    ]);
                    continue;
                }

                // 5001 duplicate
                if (($response['code'] ?? null) === ResultCode::ORDER_DUPLICATE->value) {
                    $existing        = $ignite->getOrder($partnerRef);
                    $existingOrderNo = $existing['data']['order_no'] ?? null;

                    if (empty($existingOrderNo)) {
                        $line->update([
                            'ignite_sync_status' => 'failed',
                            'ignite_sync_error'  => 'Ignite 5001 but getOrder returned no order_no.',
                        ]);
                        $anyFailed = true;
                        continue;
                    }

                    $cvFromExisting = $existing['data']['total_cv'] ?? $lineCv;

                    $line->update([
                        'ignite_order_no'             => $existingOrderNo,
                        'ignite_sync_status'          => 'posted',
                        'ignite_sync_error'           => null,
                        'ignite_cv_posted'            => $cvFromExisting,
                        'ignite_sales_week_year'      => $existing['data']['sales_week']['year']       ?? null,
                        'ignite_sales_week_no'        => $existing['data']['sales_week']['week_no']    ?? null,
                        'ignite_settlement_week_year' => $existing['data']['settlement_week']['year']  ?? null,
                        'ignite_settlement_week_no'   => $existing['data']['settlement_week']['week_no'] ?? null,
                    ]);
                    $anyPosted   = true;
                    $anyPostedCv += (float) $cvFromExisting;

                    Log::info('Ignite line duplicate resolved', [
                        'order_id' => $order->id, 'line_id' => $line->id,
                        'ignite_order_no' => $existingOrderNo,
                    ]);
                    continue;
                }

                // Other refusal
                $line->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => "Ignite [{$response['code']}]: " . ($response['msg'] ?? ''),
                ]);
                $anyFailed = true;

                Log::warning('Ignite line failed', [
                    'order_id' => $order->id, 'line_id' => $line->id,
                    'code' => $response['code'] ?? null, 'msg' => $response['msg'] ?? null,
                ]);
            }

            // Update parent order
            $pendingCount = $order->lines()
                ->whereNotIn('delivery_status', ['cancelled', 'returned'])
                ->where(function ($q) {
                    $q->whereNull('ignite_sync_status')
                      ->orWhereIn('ignite_sync_status', ['failed', 'pending_placement']);
                })
                ->count();

            if ($pendingCount === 0 && !$anyFailed) {
                $order->update([
                    'ignite_sync_status'    => 'posted',
                    'ignite_sync_error'     => null,
                    'commissionable_volume' => round($anyPostedCv, 2),
                ]);
                Cache::forget("ignite_member_{$mainBaId}");
                Cache::forget("ignite_binary_tree_{$mainBaId}");
            } elseif ($anyPosted) {
                $order->update([
                    'ignite_sync_status'    => 'partial',
                    'ignite_sync_error'     => null,
                    'commissionable_volume' => round($anyPostedCv, 2),
                ]);
            } else {
                throw new \Exception('All lines failed — will retry.');
            }
        } catch (\Throwable $e) {
            Log::error('Ignite line-wise job failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
                'attempt'  => $this->attempts(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $order->update([
                    'ignite_sync_status' => 'failed',
                    'ignite_sync_error'  => $e->getMessage(),
                ]);
            } else {
                $this->release(60);
            }
        }
    }

    /**
     * Unique partner_order_ref per line.
     * Format: ORD6AD621923C174-54
     */
    protected function buildPartnerRef(string $orderRef, int $lineId): string
    {
        return $orderRef . '-' . $lineId;
    }

    /**
     * Per-line allocations.
     */
    protected function buildLineAllocations(
        float $lineCv,
        string $mainBaId,
        ?string $ba002,
        ?string $ba003,
        array $member,
        float $qualifyCv
    ): array {
        $allocations = [];
        $cv001       = 0.0;

        if (empty($member['qualified_at'])) {
            $cv001 = min(
                max(0, round($qualifyCv - (float) ($member['cv_allocated'] ?? 0), 2)),
                round($lineCv, 2)
            );
        }

        $remaining = round($lineCv - $cv001, 2);

        if ($cv001 > 0) {
            $allocations[] = ['ba_id' => $mainBaId, 'cv' => number_format($cv001, 2, '.', '')];
        }

        if ($remaining > 0 && $ba002 && $ba003) {
            $half  = round($remaining / 2, 2);
            $other = round($remaining - $half, 2);

            if ($half > 0)  $allocations[] = ['ba_id' => $ba002, 'cv' => number_format($half, 2, '.', '')];
            if ($other > 0) $allocations[] = ['ba_id' => $ba003, 'cv' => number_format($other, 2, '.', '')];
        }

        return $allocations;
    }

    protected function normalizeMainBaId(string $baId): string
    {
        if (preg_match('/-(002|003)$/', $baId)) {
            return preg_replace('/-(002|003)$/', '-001', $baId);
        }
        return $baId;
    }
}