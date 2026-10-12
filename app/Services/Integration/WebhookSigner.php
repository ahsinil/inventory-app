<?php

namespace App\Services\Integration;

class WebhookSigner
{
    /** Signature header format: t=<unix>,v1=<hmac_sha256("t.body")> */
    public static function sign(string $secret, string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
