<?php

namespace App\Services\Integration;

use App\Contracts\EventPublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

class RabbitMqEventPublisher implements EventPublisher
{
    public function publish(string $routingKey, array $payload, string $eventId): void
    {
        if (! class_exists(AMQPStreamConnection::class)) {
            throw new RuntimeException('php-amqplib is not installed. Run: composer require php-amqplib/php-amqplib');
        }

        $cfg = config('integration.rabbitmq');
        $connection = new AMQPStreamConnection(
            $cfg['host'], $cfg['port'], $cfg['user'], $cfg['password'], $cfg['vhost']
        );

        try {
            $channel = $connection->channel();
            $channel->exchange_declare($cfg['exchange'], 'topic', false, true, false);
            $channel->confirm_select();

            $message = new AMQPMessage(json_encode($payload), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
            ]);
            $channel->basic_publish($message, $cfg['exchange'], $routingKey);
            $channel->wait_for_pending_acks(5);
            $channel->close();
        } finally {
            $connection->close();
        }
    }
}
