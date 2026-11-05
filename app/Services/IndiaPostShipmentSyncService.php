<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Poll authoritative India Post scans via Node, then synchronize only order-linked AWBs. */
final class IndiaPostShipmentSyncService
{
    private function client()
    {
        $key=(string)config('services.indiapost_node.api_key','');
        $url=rtrim((string)config('services.indiapost_node.url',''),'/');
        if (!(bool)config('services.indiapost_node.enabled',false) || !str_starts_with($url,'https://') || $key==='') {
            throw new RuntimeException('India Post Node service is not configured.');
        }
        return [Http::acceptJson()->withHeaders(['x-internal-api-key'=>$key])->connectTimeout(4)->timeout(30),$url];
    }

    public function syncOne(int $orderId): array
    {
        $local=DB::table('indiapost_order_shipments')->where('order_id',$orderId)->first();
        if (!$local) throw new RuntimeException('No local India Post booking record for order.');
        if (!preg_match('/^[A-Z]{2}[0-9]{9}IN$/D',(string)$local->barcode_no)) {
            throw new RuntimeException('Invalid locally linked AWB.');
        }
        [$http,$base]=$this->client();
        $trackingWarning=null;
        $refresh=$http->post($base.'/api/indiapost/tracking/bulk',['bulk'=>[$local->barcode_no]]);
        if (!$refresh->successful() || ($refresh->json()['success'] ?? false)!==true) {
            $trackingWarning='Carrier tracking refresh failed; last saved status only.';
            Log::warning('India Post tracking refresh failed',['order_id'=>$orderId,'status'=>$refresh->status()]);
        }
        $resp=$http->get($base.'/api/indiapost/orders/'.$orderId.'/shipments');
        $json=$resp->json();
        if (!$resp->successful() || ($json['success'] ?? false)!==true) {
            throw new RuntimeException('Node shipment lookup failed; no status changed.');
        }
        $shipments=array_values(array_filter($json['shipments'] ?? [],static fn($s)=>
            is_array($s) && (int)($s['order_id'] ?? 0)===$orderId
            && strtoupper((string)($s['barcode_no'] ?? ''))===$local->barcode_no));
        if (count($shipments)!==1) throw new RuntimeException('Shipment/AWB ownership mismatch; manual review required.');
        $s=$shipments[0];
        $status=strtolower((string)($s['booking_status'] ?? ''));
        $allowed=['submitting','submitted','rejected','unknown','booked','picked_up',
            'pickup_requested','pickup_cancelled','in_transit','out_for_delivery','delivered',
            'returned_to_sender','return_in_transit','on_hold','event_received','needs_reconciliation'];
        if (!in_array($status,$allowed,true)) throw new RuntimeException('Unknown carrier shipment status.');
        // Trust only Node's DB-linked carrier status, not user-submitted text/events.
        DB::table('indiapost_order_shipments')->where('order_id',$orderId)->where('barcode_no',$local->barcode_no)->update([
            'booking_status'=>$status,'latest_event'=>mb_substr((string)($s['latest_event'] ?? ''),0,160) ?: null,
            'latest_event_at'=>mb_substr((string)($s['latest_event_at'] ?? ''),0,60) ?: null,
            'delivered_at'=>$status==='delivered' ? ($local->delivered_at ?: now()) : $local->delivered_at,
            'updated_at'=>now(),
        ]);
        $updatedMaster=false;
        if (config('indiapost_flow.auto_update_order_status',false) && $trackingWarning===null) {
            $updatedMaster=$this->updateLegacyOrderState($orderId,$status);
        }
        return [
            'order_id'=>$orderId,'awb'=>$local->barcode_no,'courier_status'=>$status,
            'latest_event'=>$s['latest_event'] ?? null,'legacy_order_updated'=>$updatedMaster,
            'auto_status_sync_enabled'=>(bool)config('indiapost_flow.auto_update_order_status',false),
            'warning'=>$trackingWarning,
        ];
    }

    private function updateLegacyOrderState(int $id,string $courierStatus): bool
    {
        // Only real provider movement scans can change master order status.
        // Booking 'submitted' / AWB assigned never means parcel has been handed over.
        $target=match($courierStatus) {
            'picked_up'=>'dispatched',
            'in_transit','out_for_delivery'=>'shipped',
            'delivered'=>'delivered',
            default=>null,
        };
        if ($target===null) return false;
        $rank=['confirmed'=>0,'dispatched'=>1,'shipped'=>2,'delivered'=>3];
        return DB::transaction(function() use ($id,$target,$rank): bool {
            $order=DB::table('orders')->where('id',$id)->lockForUpdate()->first();
            if (!$order) return false;
            $current=strtolower((string)$order->status);
            if (!isset($rank[$current]) || $rank[$current]>=$rank[$target]) return false;
            $set=['status'=>$target];
            if ($target==='dispatched' && Schema::hasColumn('orders','dispatched_at') && !$order->dispatched_at) $set['dispatched_at']=now();
            if ($target==='shipped' && Schema::hasColumn('orders','shipped_at') && !$order->shipped_at) $set['shipped_at']=now();
            if ($target==='delivered' && Schema::hasColumn('orders','delivered_at') && !$order->delivered_at) $set['delivered_at']=now();
            if (Schema::hasColumn('orders','updated_at')) $set['updated_at']=now();
            DB::table('orders')->where('id',$id)->update($set);
            $lineSet=['delivery_status'=>$target];
            if (Schema::hasColumn('order_lines','updated_at')) $lineSet['updated_at']=now();
            DB::table('order_lines')->where('order_id',$id)
                ->whereIn('delivery_status',['confirmed','dispatched','shipped'])->update($lineSet);
            // WARNING: existing OrderController may have wallet, inventory, notifications,
            // accounting or audit side effects. Enable only after adapting to those services.
            Log::info('India Post verified order transition',['order_id'=>$id,'from'=>$current,'to'=>$target]);
            return true;
        });
    }
}
