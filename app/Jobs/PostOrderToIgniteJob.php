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
    public $timeout = 60;

    public function __construct(public int $orderId) {}

    public function handle(IgniteClient $ignite): void
    {
        Log::info('Ignite job started', ['order_id' => $this->orderId, 'attempt' => $this->attempts()]);

        $order = Order::with(['lines.product', 'user'])->find($this->orderId);

        if (!$order) {
            Log::warning('Ignite job: order not found', ['order_id' => $this->orderId]);
            return;
        }

        if ($order->ignite_sync_status === 'posted') {
            Log::info('Ignite job: already posted, skipping', ['order_id' => $order->id]);
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

        // Config + timezone guards
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

        // Normalize -001
        $mainBaId = $user->distributor_id;
        if (preg_match('/-(002|003)$/', $mainBaId)) {
            $mainBaId = preg_replace('/-(002|003)$/', '-001', $mainBaId);
        }

        try {
            // Catalog
            $catalog    = Cache::remember('ignite_catalog', 1800, fn() => $ignite->catalog()['data']['items'] ?? []);
            $catalogMap = collect($catalog)->keyBy('product_id');

            $lines   = [];
            $totalCv = 0.0;

            foreach ($order->lines as $line) {
                $product = $line->product;
                if (!$product || !$product->ignite_product_id) continue;

                $igniteItem = $catalogMap->get($product->ignite_product_id);
                if (!$igniteItem) continue;

                $lines[]  = ['product_id' => (int) $product->ignite_product_id, 'qty' => (int) $line->quantity];
                $totalCv += (float) $igniteItem['cv'] * $line->quantity;
            }

            if (empty($lines)) {
                $order->update(['ignite_sync_status' => 'skipped', 'ignite_sync_error' => 'No valid lines']);
                Log::info('Ignite job: no valid lines', ['order_id' => $order->id]);
                return;
            }

            // Member (use mainBaId, not distributor_id)
            $member    = Cache::remember("ignite_member_{$mainBaId}", 300, fn() => $ignite->memberDetails($mainBaId)['data'] ?? []);
            $qualifyCv = (float) Cache::remember('ignite_qualify_cv', 3600, fn() => $ignite->planRanks()['data']['plan']['qualify_cv'] ?? 0);

            // Binary tree
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

            // Allocations with rounding
            $allocations = [];
            $cv001       = 0.0;

            if (empty($member['qualified_at'])) {
                $cv001 = min(
                    max(0, round($qualifyCv - (float) ($member['cv_allocated'] ?? 0), 2)),
                    round($totalCv, 2)
                );
            }

            $remaining = round($totalCv - $cv001, 2);

            if ($cv001 > 0) {
                $allocations[] = ['ba_id' => $mainBaId, 'cv' => number_format($cv001, 2, '.', '')];
            }

            if ($remaining > 0) {
                $half  = round($remaining / 2, 2);
                $other = round($remaining - $half, 2);

                if ($half > 0)  $allocations[] = ['ba_id' => $ba002, 'cv' => number_format($half, 2, '.', '')];
                if ($other > 0) $allocations[] = ['ba_id' => $ba003, 'cv' => number_format($other, 2, '.', '')];
            }

            // Create order
            $response = $ignite->createOrder(
                baId:            $mainBaId,
                partnerOrderRef: $order->order_reference,
                orderedAt:       $order->confirmed_at
                                    ? $order->confirmed_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s')
                                    : now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                localAmount:     (string) $order->total_payable,
                localCurrency:   $localCurrency,
                lines:           $lines,
                allocations:     $allocations,
                extraTcs:        [], // TODO: source from order data when TCs are earned
            );

            // Handle response
            if ($response['code'] === ResultCode::OK->value) {
                $order->update([
                    'ignite_order_no'       => $response['data']['order_no'] ?? null,
                    'ignite_sync_status'    => 'posted',
                    'ignite_sync_error'     => null,
                    'commissionable_volume' => $response['data']['total_cv'] ?? 0,
                    'sales_week_year'       => $response['data']['sales_week']['year']       ?? null,
                    'sales_week_no'         => $response['data']['sales_week']['week_no']    ?? null,
                    'settlement_week_year'  => $response['data']['settlement_week']['year']  ?? null,
                    'settlement_week_no'    => $response['data']['settlement_week']['week_no'] ?? null,
                ]);

                // Invalidate caches so dashboard/tree reflect new CV immediately
                Cache::forget("ignite_member_{$mainBaId}");
                Cache::forget("ignite_binary_tree_{$mainBaId}");

                Log::info('Ignite order posted', [
                    'order_id'        => $order->id,
                    'ref'             => $order->order_reference,
                    'ignite_order_no' => $response['data']['order_no'] ?? null,
                    'total_cv'        => $response['data']['total_cv'] ?? null,
                    'sales_week'      => $response['data']['sales_week'] ?? null,
                    'settlement_week' => $response['data']['settlement_week'] ?? null,
                    'allocations'     => $allocations,
                ]);
                return;
            }

            if ($response['code'] === ResultCode::ORDER_DUPLICATE->value) {
                $existing = $ignite->getOrder($order->order_reference);
                $existingOrderNo = $existing['data']['order_no'] ?? null;

                if (empty($existingOrderNo)) {
                    throw new \Exception("Ignite 5001 but getOrder returned no order_no: " . json_encode($existing));
                }

                // Prefer getOrder's total_cv; fall back to existing value; default 0 (never null)
                $cvFromExisting = $existing['data']['total_cv']
                    ?? $order->commissionable_volume
                    ?? 0;

                $order->update([
                    'ignite_order_no'       => $existingOrderNo,
                    'ignite_sync_status'    => 'posted',
                    'ignite_sync_error'     => null,
                    'commissionable_volume' => $cvFromExisting,
                    'sales_week_year'       => $existing['data']['sales_week']['year']       ?? $order->sales_week_year,
                    'sales_week_no'         => $existing['data']['sales_week']['week_no']    ?? $order->sales_week_no,
                    'settlement_week_year'  => $existing['data']['settlement_week']['year']  ?? $order->settlement_week_year,
                    'settlement_week_no'    => $existing['data']['settlement_week']['week_no'] ?? $order->settlement_week_no,
                ]);

                Cache::forget("ignite_member_{$mainBaId}");
                Cache::forget("ignite_binary_tree_{$mainBaId}");

                Log::info('Ignite order duplicate resolved', [
                    'order_id'        => $order->id,
                    'ref'             => $order->order_reference,
                    'ignite_order_no' => $existingOrderNo,
                    'total_cv'        => $cvFromExisting,
                ]);
                return;
            }

            throw new \Exception("Ignite Error [{$response['code']}]: {$response['msg']}");

        } catch (\Exception $e) {
            Log::error("Ignite Job Failed: Order {$order->order_reference}", [
                'error'   => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Wrap the failure-state write so a DB error here doesn't kill the job
            try {
                if ($this->attempts() >= $this->tries) {
                    $order->update([
                        'ignite_sync_status' => 'failed',
                        'ignite_sync_error'  => $e->getMessage(),
                    ]);
                } else {
                    $this->release(60);
                }
            } catch (\Throwable $inner) {
                Log::error("Ignite job: failed to persist failure state", [
                    'order_id' => $order->id,
                    'error'    => $inner->getMessage(),
                ]);
                throw $e;
            }
        }
    }
}