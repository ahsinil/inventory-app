<?php

namespace App\Services\Integration;

use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebhookDeliveryService
{
    public function attempt(WebhookDelivery $delivery): WebhookDelivery
    {
        $subscription = $delivery->subscription;
        $body = json_encode($delivery->payload);
        $delivery->attempts++;

        try {
            $response = Http::timeout(config('integration.webhooks.timeout'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Inventory-Event' => $delivery->event_type,
                    'X-Inventory-Delivery' => $delivery->id,
                    'X-Inventory-Signature' => WebhookSigner::sign($subscription->secret, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->url);

            $delivery->response_status = $response->status();
            $delivery->response_body = mb_substr($response->body(), 0, 2000);

            if ($response->successful()) {
                $delivery->status = 'SUCCESS';
                $delivery->delivered_at = now();
                $delivery->next_attempt_at = null;
                $delivery->last_error = null;
                $delivery->save();

                return $delivery;
            }
            $delivery->last_error = "HTTP {$response->status()}";
        } catch (Throwable $e) {
            $delivery->last_error = mb_substr($e->getMessage(), 0, 1000);
        }

        $max = config('integration.webhooks.max_attempts');
        if ($delivery->attempts >= $max) {
            $delivery->status = 'FAILED';
            $delivery->next_attempt_at = null;
        } else {
            $backoff = config('integration.webhooks.backoff');
            $delay = $backoff[min($delivery->attempts - 1, count($backoff) - 1)];
            $delivery->status = 'PENDING';
            $delivery->next_attempt_at = now()->addSeconds($delay);
        }
        $delivery->save();

        return $delivery;
    }
}
