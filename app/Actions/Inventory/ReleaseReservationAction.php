<?php

namespace App\Actions\Inventory;

use App\Models\InventoryAsset;
use App\Models\StockReservation;
use App\Models\WarehouseLocation;
use App\Services\AssetService;
use App\Services\Integration\OutboxService;
use App\Services\StockService;
use Exception;
use Illuminate\Support\Facades\DB;

class ReleaseReservationAction
{
    protected StockService $stockService;

    protected AssetService $assetService;

    public function __construct(StockService $stockService, AssetService $assetService)
    {
        $this->stockService = $stockService;
        $this->assetService = $assetService;
    }

    public function execute(StockReservation $reservation, string $userId, string $newStatus = 'RELEASED'): StockReservation
    {
        if ($reservation->status !== 'RESERVED') {
            throw new Exception('Only RESERVED reservations can be released.');
        }

        return DB::transaction(function () use ($reservation, $userId, $newStatus) {
            foreach ($reservation->items as $item) {
                if ($item->quantity > 0) {
                    $defaultLoc = WarehouseLocation::where('warehouse_id', $reservation->warehouse_id)
                        ->where('is_default', true)
                        ->first();

                    $locationId = $defaultLoc ? $defaultLoc->id : null;
                    if (! $locationId) {
                        throw new Exception('No default location found for warehouse.');
                    }

                    // Receive back into stock
                    $this->stockService->receive(
                        companyId: $reservation->company_id,
                        productId: $item->product_id,
                        warehouseId: $reservation->warehouse_id,
                        warehouseLocationId: $locationId,
                        quantity: $item->quantity,
                        reference: $reservation,
                        userId: $userId,
                        remarks: "Released Reservation {$reservation->reservation_number}"
                    );

                    // Update asset status
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
                            $asset->update(['status' => 'AVAILABLE']);
                            $this->assetService->recordHistory($asset, 'RELEASED', $oldStatus, 'AVAILABLE', $reservation, $userId);
                        }
                    }
                }
            }

            $reservation->update(['status' => $newStatus]);

            app(OutboxService::class)->record(
                $newStatus === 'EXPIRED' ? 'reservation.expired' : 'reservation.released',
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
