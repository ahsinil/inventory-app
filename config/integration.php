<?php

return [
    // log (default, no broker) | rabbitmq
    'publisher' => env('EVENT_PUBLISHER', 'log'),

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => env('RABBITMQ_EXCHANGE', 'inventory.events'),
    ],

    'outbox' => [
        'batch_size' => 100,
        'max_attempts' => 10,
    ],

    'webhooks' => [
        'timeout' => 10,
        'max_attempts' => 6,
        // seconds before attempt N+1
        'backoff' => [30, 120, 600, 3600, 21600],
    ],

    'rate_limits' => [
        'internal' => env('RATE_LIMIT_INTERNAL', 120),
        'external' => env('RATE_LIMIT_EXTERNAL', 60),
    ],

    'idempotency_ttl_hours' => 24,
];
