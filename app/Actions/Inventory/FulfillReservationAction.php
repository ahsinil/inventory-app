<?php

namespace App\Actions\Inventory;

use App\Models\InventoryAsset;
use App\Models\StockReservation;
use App\Services\AssetService;
use App\Services\Integration\OutboxService;
use Exception;
use Illuminate\Support\Facades\DB;

class FulfillReservationAction
{
    protected AssetService $assetService;

    public function __construct(AssetService $assetService)
    {
        $this->assetService = $assetService;
    }

    public function execute(StockReservation $reservation, string $userId): StockReservation
    {
        if ($reservation->status === 'DRAFT') {
            $items = $reservation->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'serial_numbers' => $item->reserved_serial_numbers ?? [],
                ];
            })->toArray();
            app(ReserveStockAction::class)->execute($reservation, $userId, $items);
            $reservation->refresh();
        }

        if (! in_array($reservation->status, ['RESERVED', 'CONFIRMED'])) {
            throw new Exception('Only RESERVED or CONFIRMED reservations can be fulfilled.');
        }

        return DB::transaction(function () use ($reservation, $userId) {
            foreach ($reservation->items as $item) {
                if ($item->quantity > 0) {
                    // Update asset status if serialized
                    $serialNumbers = $item->reserved_serial_numbers ?? [];
                    if (! empty($serialNumbers)) {
                        $assets = InventoryAsset::where('company_id', $reservation->company_id)
                            ->whereIn('serial_number', $serialNumbers)
                            ->get();

                        foreach ($assets as $asset) {
                            if ($asset->status !== 'RESERVED') {
                                throw new Exception("Asset {$asset->serial_number} is not reserved.");
                            }
                            $oldStatus = $asset->status;
                            $asset->update([
                                'status' => 'INSTALLED',
                                'external_system' => $reservation->external_system,
                                'external_reference' => $reservation->external_reference,
                            ]);
                            $this->assetService->recordHistory($asset, 'FULFILLED', $oldStatus, 'INSTALLED', $reservation, $userId);
                        }
                    }
                }
            }

            $reservation->update(['status' => 'FULFILLED']);

            app(OutboxService::class)->record(
                'reservation.fulfilled',
                $reservation->company_id, 'stock_reservation', $reservation->id,
                [
                    'reservation_id' => $reservation->id,
                    'reservation_number' => $reservation->reservation_number,
                    'external_system' => $reservation->external_system,
                    'external_reference' => $reservation->external_reference,
                    'status' => $reservation->status,
                ]
            );

            return $reservation;
        });
    }
}
