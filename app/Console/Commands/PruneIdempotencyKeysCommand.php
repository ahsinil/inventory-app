<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete expired idempotency keys';

    public function handle(): int
    {
        $n = IdempotencyKey::where('created_at', '<', now()->subHours(config('integration.idempotency_ttl_hours')))->delete();
        $this->info("Pruned {$n} keys.");

        return self::SUCCESS;
    }
}
