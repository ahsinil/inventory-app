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

class ReserveStockAction
{
    protected StockService $stockService;

    protected AssetService $assetService;

    public function __construct(StockService $stockService, AssetService $assetService)
    {
        $this->stockService = $stockService;
        $this->assetService = $assetService;
    }

    public function execute(StockReservation $reservation, string $userId, array $reservedItems): StockReservation
    {
        if ($reservation->status !== 'DRAFT') {
            throw new Exception('Reservation must be DRAFT to be reserved.');
        }

        return DB::transaction(function () use ($reservation, $userId, $reservedItems) {
            foreach ($reservation->items as $item) {
                $input = collect($reservedItems)->firstWhere('product_id', $item->product_id);
                $qty = $input ? ($input['quantity'] ?? 0) : 0;
                $serialNumbers = $input ? ($input['serial_numbers'] ?? []) : [];
                $locationId = $input['warehouse_location_id'] ?? null;

                if (! $locationId) {
                    $defaultLoc = WarehouseLocation::where('warehouse_id', $reservation->warehouse_id)
                        ->where('is_default', true)
                        ->first();
                    if (! $defaultLoc) {
                        throw new Exception('No default location found for warehouse.');
                    }
                    $locationId = $defaultLoc->id;
                }

                if ($qty > 0) {
                    $item->update([
                        'quantity' => $qty,
                        'reserved_serial_numbers' => $serialNumbers,
                    ]);

                    // Issue from warehouse immediately (acting as reserved)
                    // Alternative: use a separate reserved_quantity column in StockBalance,
                    // but for this phase we can physically move it to a 'RESERVED' location or just issue it.
                    // For simplicity, we just issue it now, and if released, we receive it back.
                    $this->stockService->issue(
                        companyId: $reservation->company_id,
                        productId: $item->product_id,
                        warehouseId: $reservation->warehouse_id,
                        warehouseLocationId: $locationId,
                        quantity: $qty,
                        reference: $reservation,
                        userId: $userId,
                        remarks: "Reservation {$reservation->reservation_number}"
                    );

                    // Update asset status
                    if (! empty($serialNumbers)) {
                        $assets = InventoryAsset::where('company_id', $reservation->company_id)
                            ->whereIn('serial_number', $serialNumbers)
                            ->get();

                        foreach ($assets as $asset) {
                            if ($asset->status !== 'AVAILABLE') {
                                throw new Exception("Asset {$asset->serial_number} is not available.");
                            }
                            $oldStatus = $asset->status;
                            $asset->update(['status' => 'RESERVED']);
                            $this->assetService->recordHistory($asset, 'RESERVED', $oldStatus, 'RESERVED', $reservation, $userId);
                        }
                    }
                }
            }

            $reservation->update(['status' => 'RESERVED']);

            app(OutboxService::class)->record(
                'reservation.reserved', $reservation->company_id, 'stock_reservation', $reservation->id,
                $this->eventData($reservation)
            );

            return $reservation;
        });
    }

    protected function eventData(StockReservation $r): array
    {
        return [
            'reservation_id' => $r->id,
            'reservation_number' => $r->reservation_number,
            'external_system' => $r->external_system,
            'external_reference' => $r->external_reference,
            'warehouse_id' => $r->warehouse_id,
            'status' => $r->status,
        ];
    }
}
