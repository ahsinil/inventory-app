<?php

namespace App\Http\Controllers;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends Controller
{
    public function live()
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready()
    {
        try {
            DB::connection()->getPdo();

            return response()->json(['status' => 'ready']);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Database not ready'], 503);
        }
    }

    public function health()
    {
        $checks = [
            'database' => 'ok',
            'redis' => 'ok',
            'queue_size' => 0,
            'outbox_backlog' => 0,
        ];
        $status = 200;

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $checks['database'] = 'error';
            $status = 503;
        }

        try {
            Cache::store('redis')->get('health_check');
        } catch (Throwable $e) {
            // Might not use Redis, so we don't fail health check but mark it
            $checks['redis'] = 'unreachable';
        }

        try {
            $checks['queue_size'] = Queue::size();
        } catch (Throwable $e) {
            $checks['queue_size'] = -1;
        }

        try {
            $checks['outbox_backlog'] = OutboxEvent::where('status', 'PENDING')->count();
        } catch (Throwable $e) {
            $checks['outbox_backlog'] = -1;
        }

        return response()->json([
            'status' => $status === 200 ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $status);
    }
}
