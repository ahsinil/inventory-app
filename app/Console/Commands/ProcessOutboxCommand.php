<?php

namespace App\Console\Commands;

use App\Contracts\EventPublisher;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Console\Command;
use Throwable;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process {--once : Run a single batch and exit}';

    protected $description = 'Publish pending outbox events to the broker and fan out to webhook subscriptions';

    public function handle(EventPublisher $publisher): int
    {
        do {
            $processed = $this->processBatch($publisher);
            if ($this->option('once')) {
                break;
            }
            if ($processed === 0) {
                sleep(2);
            }
        } while (true);

        return self::SUCCESS;
    }

    public function processBatch(EventPublisher $publisher): int
    {
        $events = OutboxEvent::where('status', 'PENDING')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(config('integration.outbox.batch_size'))
            ->get();

        foreach ($events as $event) {
            try {
                $this->fanOutWebhooks($event);
                $publisher->publish($event->event_type, $event->payload, $event->event_id);
                $event->update(['status' => 'PUBLISHED', 'published_at' => now(), 'last_error' => null]);
            } catch (Throwable $e) {
                $attempts = $event->attempts + 1;
                $failed = $attempts >= config('integration.outbox.max_attempts');
                $event->update([
                    'attempts' => $attempts,
                    'status' => $failed ? 'FAILED' : 'PENDING',
                    'available_at' => now()->addSeconds(min(300, 2 ** $attempts)),
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                ]);
            }
        }

        return $events->count();
    }

    protected function fanOutWebhooks(OutboxEvent $event): void
    {
        if ($event->fanned_out_at) {
            return;
        }

        WebhookSubscription::where('is_active', true)
            ->when($event->company_id, fn ($q) => $q->where('company_id', $event->company_id))
            ->get()
            ->filter(fn ($s) => $s->matches($event->event_type))
            ->each(fn ($s) => WebhookDelivery::create([
                'webhook_subscription_id' => $s->id,
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'payload' => $event->payload,
                'status' => 'PENDING',
                'next_attempt_at' => now(),
            ]));

        $event->update(['fanned_out_at' => now()]);
    }
}
