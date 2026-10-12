<?php

namespace App\Services\Integration;

use App\Models\OutboxEvent;
use Illuminate\Support\Str;

class OutboxService
{
    /**
     * Record a domain event. Call inside the same DB transaction as the state change
     * so the event is committed atomically (Transactional Outbox).
     */
    public function record(string $eventType, ?string $companyId, ?string $aggregateType, ?string $aggregateId, array $data): OutboxEvent
    {
        $eventId = (string) Str::ulid();

        return OutboxEvent::create([
            'company_id' => $companyId,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => now()->toIso8601String(),
                'correlation_id' => app()->bound('requestId') ? app('requestId') : null,
                'data' => $data,
            ],
            'status' => 'PENDING',
            'available_at' => now(),
        ]);
    }
}
