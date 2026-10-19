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
        $order = Order::with(['lines.product', 'user'])->find($this->orderId);

        if (!$order || $order->ignite_sync_status === 'posted') return;

        $user = $order->user;
        if (!$user || empty($user->distributor_id) || $order->order_type !== 'distributor') {
            $order->update(['ignite_sync_status' => 'skipped']);
            return;
        }

        try {
            // 1. Fetch Catalog & Build Lines (Using Ignite CV)
            $catalog = Cache::remember('ignite_catalog', 1800, fn() => $ignite->catalog()['data']['items'] ?? []);
            $catalogMap = collect($catalog)->keyBy('product_id');
            
            $lines = [];
            $totalCv = 0;

            foreach ($order->lines as $line) {
                $product = $line->product;
                if (!$product || !$product->ignite_product_id) continue;
                
                $igniteItem = $catalogMap->get($product->ignite_product_id);
                if (!$igniteItem) continue;
                
                $lines[] = ['product_id' => (int) $product->ignite_product_id, 'qty' => (int) $line->quantity];
                $totalCv += (float) $igniteItem['cv'] * $line->quantity;
            }

            if (empty($lines)) {
                $order->update(['ignite_sync_status' => 'skipped', 'ignite_sync_error' => 'No valid lines']);
                return;
            }

            // 2. Fetch Member & Plan Ranks
            $member = Cache::remember("ignite_member_{$user->distributor_id}", 300, fn() => $ignite->memberDetails($user->distributor_id)['data'] ?? []);
            $qualifyCv = (float) Cache::remember('ignite_qualify_cv', 3600, fn() => $ignite->planRanks()['data']['plan']['qualify_cv'] ?? 0);

            // 3. Fetch Binary Tree to get -002 and -003 (NO HARDCODE)
            $tree = Cache::remember("ignite_binary_tree_{$user->distributor_id}", 300, fn() => $ignite->binaryTree($user->distributor_id, 1)['data']['rows'] ?? []);
            $level1Nodes = collect($tree)->where('level', 1);

            $ba002 = $level1Nodes->firstWhere('binary_side', 1)['ba_id'] ?? null; // Left sub-account
            $ba003 = $level1Nodes->firstWhere('binary_side', 2)['ba_id'] ?? null; // Right sub-account

            if (!$ba002 || !$ba003) {
                throw new \Exception("Could not find -002 or -003 nodes from Ignite binary tree.");
            }

            // 4. Allocations (Ignite Guide)
            $allocations = [];
            $cv001 = 0;

            if (empty($member['qualified_at'])) {
                $cv001 = min(max(0, $qualifyCv - (float) ($member['cv_allocated'] ?? 0)), $totalCv);
            }

            $remaining = $totalCv - $cv001;

            // ✅ FIX 1: Main account ka BA ID nikaalo
            $mainBaId = $user->distributor_id;
            if (str_ends_with($mainBaId, '-002') || str_ends_with($mainBaId, '-003')) {
                $mainBaId = preg_replace('/-(002|003)$/', '-001', $mainBaId);
            }

            if ($cv001 > 0) {
                $allocations[] = ['ba_id' => $mainBaId, 'cv' => (string) $cv001];
            }
            
            if ($remaining > 0) {
                // Guide: "-002 and -003: the rest, split freely"
                $allocations[] = ['ba_id' => $ba002, 'cv' => (string) ($remaining / 2)];
                $allocations[] = ['ba_id' => $ba003, 'cv' => (string) ($remaining / 2)];
            }

            // 5. Config Validation
            $localCurrency = config('services.ignite.local_currency');
            $timezone = config('services.ignite.timezone');
            if (!$localCurrency || !$timezone) {
                throw new \Exception("Ignite local currency or timezone is not configured.");
            }

            // 6. Post to Ignite
            $response = $ignite->createOrder(
                baId: $user->distributor_id,
                partnerOrderRef: $order->order_reference,
                orderedAt: $order->confirmed_at 
                    ? $order->confirmed_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s')
                    : now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                localAmount: (string) $order->total_payable,
                localCurrency: $localCurrency,
                lines: $lines,
                allocations: $allocations,
                extraTcs: [], // ✅ FIX 2: Explicitly bhejo
            );

            // 7. Handle Response
            if ($response['code'] === ResultCode::OK->value) {
                $order->update(['ignite_order_no' => $response['data']['order_no'], 'ignite_sync_status' => 'posted']);
            } elseif ($response['code'] === ResultCode::ORDER_DUPLICATE->value) {
                $existing = $ignite->getOrder($order->order_reference);
                $order->update(['ignite_order_no' => $existing['data']['order_no'] ?? null, 'ignite_sync_status' => 'posted']);
            } else {
                throw new \Exception("Ignite Error [{$response['code']}]: {$response['msg']}");
            }

        } catch (\Exception $e) {
            Log::error("Ignite Job Failed: Order {$order->order_reference}", ['error' => $e->getMessage()]);
            
            if ($this->attempts() >= $this->tries) {
                $order->update(['ignite_sync_status' => 'failed', 'ignite_sync_error' => $e->getMessage()]);
            } else {
                $this->release(60);
            }
        }
    }
}