<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Services\Integration\WebhookDeliveryService;
use Illuminate\Console\Command;

class DeliverWebhooksCommand extends Command
{
    protected $signature = 'webhooks:deliver {--once : Run a single batch and exit}';

    protected $description = 'Deliver pending webhook deliveries (with retry/backoff)';

    public function handle(WebhookDeliveryService $service): int
    {
        do {
            $count = $this->deliverBatch($service);
            if ($this->option('once')) {
                break;
            }
            if ($count === 0) {
                sleep(2);
            }
        } while (true);

        return self::SUCCESS;
    }

    public function deliverBatch(WebhookDeliveryService $service): int
    {
        $deliveries = WebhookDelivery::with('subscription')
            ->where('status', 'PENDING')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(50)
            ->get();

        foreach ($deliveries as $delivery) {
            $service->attempt($delivery);
        }

        return $deliveries->count();
    }
}
