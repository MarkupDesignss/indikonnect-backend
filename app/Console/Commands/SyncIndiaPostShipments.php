<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\IndiaPostShipmentSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** cPanel cron: artisan indiapost:sync --limit=100 */
final class SyncIndiaPostShipments extends Command
{
    protected $signature='indiapost:sync {--limit=100 : Maximum active shipments per invocation}';
    protected $description='Poll India Post via protected Node adapter and store verified AWB tracking statuses';

    public function handle(IndiaPostShipmentSyncService $service): int
    {
        $limit=max(1,min(500,(int)$this->option('limit')));
        $orders=DB::table('indiapost_order_shipments')
            ->whereIn('booking_status',['submitted','booked','picked_up','pickup_requested',
                'in_transit','out_for_delivery','on_hold','return_in_transit','event_received'])
            ->orderBy('updated_at')->limit($limit)->pluck('order_id');
        $success=0;$failed=0;
        foreach ($orders as $id) {
            try { $service->syncOne((int)$id);$success++; }
            catch (Throwable $e) { $failed++;$this->warn('Order '.$id.': '.get_class($e)); }
        }
        $this->info("Synchronized: $success; failures: $failed");
        return $failed>0 ? self::FAILURE : self::SUCCESS;
    }
}
