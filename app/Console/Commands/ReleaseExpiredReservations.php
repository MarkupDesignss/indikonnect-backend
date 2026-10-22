<?php

namespace App\Console\Commands;

use App\Models\InventoryReservation;
use App\Services\InventoryReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredReservations extends Command
{
    protected $signature   = 'reservations:release-expired {--batch=200}';
    protected $description = 'Release held inventory reservations past their expiry.';

    public function handle(InventoryReservationService $service): int
    {
        $batch = (int) $this->option('batch');
        $total = 0;

        do {
            $expired = InventoryReservation::where('status', 'held')
                ->where('expires_at', '<', now())
                ->orderBy('id')
                ->limit($batch)
                ->lockForUpdate()
                ->get();

            if ($expired->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($expired, $service) {
                foreach ($expired as $res) {
                    $fresh = InventoryReservation::where('id', $res->id)
                        ->where('status', 'held')
                        ->lockForUpdate()
                        ->first();

                    if (!$fresh) {
                        continue;
                    }

                    $service->release($fresh);

                    if ($fresh->order_id) {
                        $remainingHeld = InventoryReservation::where('order_id', $fresh->order_id)
                            ->where('status', 'held')
                            ->exists();

                        if (!$remainingHeld) {
                            DB::table('orders')
                                ->where('id', $fresh->order_id)
                                ->whereIn('status', ['pending', 'initiated', 'payment_pending'])
                                ->update(['status' => 'expired', 'updated_at' => now()]);
                        }
                    }
                }
            });

            $total += $expired->count();
        } while ($expired->count() === $batch);

        if ($total > 0) {
            Log::info("Released {$total} expired inventory reservations");
            $this->info("Released {$total} expired reservations.");
        }

        return self::SUCCESS;
    }
}
