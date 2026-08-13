<?php

namespace App\Console\Commands;

use App\Models\CommissionApiEvent;
use App\Services\Commission\CommissionServiceInterface;
use App\Services\Commission\OrderPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessCommissionQueue extends Command
{
    protected $signature = 'commission:process {--limit=50 : Number of events to process} {--dry-run : Simulate without calling API}';
    protected $description = 'Process pending commission events from the queue';

    protected CommissionServiceInterface $commissionService;

    public function __construct(CommissionServiceInterface $commissionService)
    {
        parent::__construct();
        $this->commissionService = $commissionService;
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        // Fetch pending events that are ready for retry
        $events = CommissionApiEvent::where('status', 'pending')
            ->where('event_type', 'order_post')
            ->get()
            ->filter(fn($e) => $e->shouldRetry())
            ->take($limit);

        if ($events->isEmpty()) {
            $this->info('No pending events to process.');
            return 0;
        }

        $this->info("Processing {$events->count()} events...");

        foreach ($events as $event) {
            $this->processEvent($event, $dryRun);
        }

        return 0;
    }

    protected function processEvent(CommissionApiEvent $event, bool $dryRun): void
    {
        try {
            if ($dryRun) {
                $this->line("[DRY RUN] Event #{$event->id} (Attempt {$event->retry_count})");
                return;
            }

            $this->info("Processing event #{$event->id} (Attempt {$event->retry_count})");

            // Fix: Ensure $payload is an array
            $payload = $event->payload;
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
                if (!is_array($payload)) {
                    throw new \Exception('Invalid payload format: cannot decode JSON.');
                }
            }

            $event->markProcessing();

            $orderPayload = new OrderPayload(
                eventId: $payload['eventId'] ?? 'evt_' . uniqid(),
                action: $payload['action'] ?? 'ORDER_PLACED',
                orderReference: $payload['orderReference'],
                purchaserIdentifier: $payload['purchaserIdentifier'],
                accountType: $payload['accountType'],
                eventTimestamp: $payload['eventTimestamp'],
                lines: $payload['lines'],
                totalOrderValue: $payload['totalOrderValue'],
            );

            $response = $this->commissionService->postOrderEvent($orderPayload);

            if ($response->success) {
                $event->markSent($response->data);
                if ($event->order_id && isset($response->data['cv'])) {
                    $event->order()->update(['commissionable_volume' => $response->data['cv']]);
                }
                $this->info("✓ Event #{$event->id} succeeded. CV: " . ($response->data['cv'] ?? 'N/A'));
            } else {
                $event->markFailed($response->message);
                $this->warn("✗ Event #{$event->id} failed: {$response->message}");
                if ($event->status === 'failed') {
                    $this->error("Event #{$event->id} permanently failed after {$event->max_retries} attempts.");
                    // Optionally send admin alert
                }
            }
        } catch (\Throwable $e) {
            $event->markFailed($e->getMessage());
            $this->error("Error processing event #{$event->id}: " . $e->getMessage());
        }
    }
}