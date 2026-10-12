<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replays the stored response for a repeated "Idempotency-Key" on mutating requests.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return $this->error('Idempotency-Key header is required.', 'IDEMPOTENCY_KEY_REQUIRED', 400);
        }
        if (strlen($key) > 128) {
            return $this->error('Idempotency-Key is too long (max 128).', 'IDEMPOTENCY_KEY_INVALID', 400);
        }

        $credential = $request->attributes->get('service_credential');
        $scope = $credential?->id ?? ($request->user()?->id ?? 'anonymous');
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = IdempotencyKey::where(['scope' => $scope, 'key' => $key])->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        try {
            $record = IdempotencyKey::create([
                'scope' => $scope, 'key' => $key, 'request_hash' => $hash, 'locked_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay(IdempotencyKey::where(['scope' => $scope, 'key' => $key])->firstOrFail(), $hash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();
            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $record->delete(); // allow retry after server errors
        } else {
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'locked_at' => null,
            ]);
        }

        return $response;
    }

    protected function replay(IdempotencyKey $existing, string $hash): Response
    {
        if ($existing->request_hash !== $hash) {
            return $this->error('Idempotency-Key was already used with a different request.', 'IDEMPOTENCY_KEY_REUSED', 422);
        }
        if ($existing->response_status === null) {
            return $this->error('A request with this Idempotency-Key is still in progress.', 'IDEMPOTENCY_IN_PROGRESS', 409);
        }

        return response($existing->response_body, $existing->response_status, [
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }

    protected function error(string $message, string $code, int $status): Response
    {
        return response()->json(['message' => $message, 'code' => $code, 'errors' => (object) []], $status);
    }
}
