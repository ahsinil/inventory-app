<?php

namespace App\Console\Commands;

use App\Actions\Inventory\ReleaseReservationAction;
use App\Models\StockReservation;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ExpireReservationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:expire-reservations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release expired stock reservations';

    /**
     * Execute the console command.
     */
    public function handle(ReleaseReservationAction $releaseAction)
    {
        $expiredReservations = StockReservation::where('status', 'RESERVED')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expiredReservations as $reservation) {
            try {
                // System user ID or null for automated process
                // Because ULID is expected, and 'system' is not valid ULID, we should pass null if the table allows,
                // or just skip logging the user.
                // Let's pass a dummy ULID or modify actions to accept null.
                // In our migration, `created_by`, `user_id` are nullable.
                $releaseAction->execute($reservation, Str::ulid()->toString(), 'EXPIRED');
                $count++;
            } catch (\Exception $e) {
                $this->error("Failed to release reservation {$reservation->reservation_number}: ".$e->getMessage());
            }
        }

        $this->info("Successfully expired {$count} reservations.");
    }
}
