<?php

namespace App\Contracts;

interface EventPublisher
{
    /**
     * Publish an event payload. Must throw on failure.
     */
    public function publish(string $routingKey, array $payload, string $eventId): void;
}
