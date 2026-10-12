<?php

namespace App\Services\Integration;

use App\Contracts\EventPublisher;
use Illuminate\Support\Facades\Log;

/** No-op publisher used when the broker is disabled (default). */
class LogEventPublisher implements EventPublisher
{
    public function publish(string $routingKey, array $payload, string $eventId): void
    {
        Log::info('outbox.published', ['routing_key' => $routingKey, 'event_id' => $eventId]);
    }
}
