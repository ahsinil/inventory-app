<?php

namespace App\Http\Middleware;

use App\Models\ServiceCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates external systems via "Authorization: Bearer <key_id>.<secret>"
 * and enforces scopes: ->middleware('service.auth:stock:read').
 */
class ServiceAuth
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = $request->bearerToken();
        if (! $token || ! str_contains($token, '.')) {
            return $this->fail('Missing or malformed service credential.', 'UNAUTHENTICATED', 401);
        }

        [$keyId, $secret] = explode('.', $token, 2);
        $credential = ServiceCredential::where('key_id', $keyId)->first();

        if (! $credential
            || ! hash_equals($credential->secret_hash, hash('sha256', $secret))
            || ! $credential->is_active
            || ($credential->expires_at && $credential->expires_at->isPast())) {
            return $this->fail('Invalid service credential.', 'UNAUTHENTICATED', 401);
        }

        foreach ($scopes as $scope) {
            if (! $credential->hasScope($scope)) {
                return $this->fail("Missing required scope: {$scope}", 'FORBIDDEN_SCOPE', 403);
            }
        }

        if (! $credential->last_used_at || $credential->last_used_at->lt(now()->subMinute())) {
            $credential->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('service_credential', $credential);

        return $next($request);
    }

    protected function fail(string $message, string $code, int $status): Response
    {
        return response()->json(['message' => $message, 'code' => $code, 'errors' => (object) []], $status);
    }
}
