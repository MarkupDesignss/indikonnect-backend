<?php

namespace App\Console\Commands;

use App\Models\InventoryReservation;
use App\Services\InventoryReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReleaseExpiredReservations extends Command
{
    protected $signature   = 'reservations:release-expired {--batch=200}';
    protected $description = 'Release held inventory reservations past their expiry and mark orders as payment_failed.';

    public function handle(InventoryReservationService $service): int
    {
        $batch = (int) $this->option('batch');
        $total = 0;

        // counters for final summary
        $releasedCount   = 0;
        $skippedCount    = 0;
        $failedCount     = 0;
        $ordersFailed    = 0;
        $linesFailed     = 0;

        Log::info('reservations:release-expired started', ['batch' => $batch]);

        do {
            try {
                $expired = InventoryReservation::where('status', 'held')
                    ->where('expires_at', '<', now())
                    ->orderBy('id')
                    ->limit($batch)
                    ->lockForUpdate()
                    ->get();
            } catch (Throwable $e) {
                Log::error('reservations:release-expired — failed to fetch expired batch', [
                    'batch' => $batch,
                    'error' => $e->getMessage(),
                ]);
                $this->error('Failed to fetch expired reservations: ' . $e->getMessage());
                return self::FAILURE;
            }

            if ($expired->isEmpty()) {
                break;
            }

            try {
                DB::transaction(function () use (
                    $expired,
                    $service,
                    &$releasedCount,
                    &$skippedCount,
                    &$failedCount,
                    &$ordersFailed,
                    &$linesFailed
                ) {
                    foreach ($expired as $res) {
                        try {
                            $fresh = InventoryReservation::where('id', $res->id)
                                ->where('status', 'held')
                                ->lockForUpdate()
                                ->first();

                            // Case: kisi aur process ne pehle hi release/consume kar diya
                            if (!$fresh) {
                                $skippedCount++;
                                Log::warning('reservations:release-expired — reservation already handled, skipped', [
                                    'reservation_id' => $res->id,
                                    'order_id'       => $res->order_id,
                                    'product_id'     => $res->product_id,
                                ]);
                                continue;
                            }

                            $service->release($fresh);
                            $releasedCount++;

                            Log::info('reservations:release-expired — reservation released', [
                                'reservation_id' => $fresh->id,
                                'order_id'       => $fresh->order_id,
                                'product_id'     => $fresh->product_id,
                                'quantity'       => $fresh->quantity,
                            ]);

                            // Order status ko payment_failed karo agar koi held reservation nahi bachi
                            if ($fresh->order_id) {
                                $remainingHeld = InventoryReservation::where('order_id', $fresh->order_id)
                                    ->where('status', 'held')
                                    ->exists();

                                if (!$remainingHeld) {
                                    $updated = DB::table('orders')
                                        ->where('id', $fresh->order_id)
                                        ->whereIn('status', ['pending', 'initiated', 'payment_pending'])
                                        ->update([
                                            'status'     => 'payment_failed',
                                            'updated_at' => now(),
                                        ]);

                                    if ($updated > 0) {
                                        $ordersFailed++;

                                        // Order lines ka delivery_status bhi payment_failed karo.
                                        // Sirf wahi lines jo is reservation se tied hain.
                                        $linesQuery = DB::table('order_lines')
                                            ->where('order_id', $fresh->order_id);

                                        if (!empty($fresh->item_reference_id)) {
                                            $linesQuery->where('id', $fresh->item_reference_id);
                                        } else {
                                            $linesQuery->where('product_id', $fresh->product_id);
                                        }

                                        $linesUpdated = $linesQuery->update([
                                            'delivery_status' => 'payment_failed',
                                            'updated_at'      => now(),
                                        ]);

                                        $linesFailed += $linesUpdated;

                                        Log::info('reservations:release-expired — order marked payment_failed', [
                                            'order_id'         => $fresh->order_id,
                                            'order_lines_hit'  => $linesUpdated,
                                        ]);
                                    } else {
                                        Log::warning('reservations:release-expired — order status not changed (already moved on)', [
                                            'order_id' => $fresh->order_id,
                                        ]);
                                    }
                                }
                            }
                        } catch (Throwable $e) {
                            // Ek reservation fail hui — poora batch rollback nahi karna
                            $failedCount++;
                            Log::error('reservations:release-expired — failed to release reservation', [
                                'reservation_id' => $res->id,
                                'order_id'       => $res->order_id,
                                'product_id'     => $res->product_id,
                                'quantity'       => $res->quantity,
                                'error'          => $e->getMessage(),
                                'trace'          => $e->getTraceAsString(),
                            ]);
                            // intentionally swallowed so other reservations still process
                        }
                    }
                });
            } catch (Throwable $e) {
                Log::error('reservations:release-expired — batch transaction failed, rolled back', [
                    'batch' => $batch,
                    'error' => $e->getMessage(),
                ]);
                $this->error('Batch transaction failed: ' . $e->getMessage());
                return self::FAILURE;
            }

            $total += $expired->count();
        } while ($expired->count() === $batch);

        // Final summary log
        Log::info('reservations:release-expired finished', [
            'total_processed'       => $total,
            'released'              => $releasedCount,
            'skipped'               => $skippedCount,
            'failed'                => $failedCount,
            'orders_marked_failed'  => $ordersFailed,
            'order_lines_marked_failed' => $linesFailed,
        ]);

        if ($total > 0) {
            $this->info("Processed {$total} expired reservations. Released: {$releasedCount}, Skipped: {$skippedCount}, Failed: {$failedCount}, Orders payment_failed: {$ordersFailed}, Order lines payment_failed: {$linesFailed}");
        } else {
            $this->info('No expired reservations found.');
        }

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
