<?php

namespace App\Actions\Inventory;

use App\Models\InventoryAsset;
use App\Models\StockTransfer;
use App\Models\WarehouseLocation;
use App\Services\AssetService;
use App\Services\StockService;
use Exception;
use Illuminate\Support\Facades\DB;

class ReceiveTransferAction
{
    protected StockService $stockService;

    protected AssetService $assetService;

    public function __construct(StockService $stockService, AssetService $assetService)
    {
        $this->stockService = $stockService;
        $this->assetService = $assetService;
    }

    public function execute(StockTransfer $transfer, string $userId, array $receivedItems): StockTransfer
    {
        if ($transfer->status !== 'IN_TRANSIT') {
            throw new Exception('Transfer must be IN_TRANSIT to be received.');
        }

        return DB::transaction(function () use ($transfer, $userId, $receivedItems) {
            foreach ($transfer->items as $item) {
                $input = collect($receivedItems)->firstWhere('product_id', $item->product_id);
                $qty = $input ? ($input['received_quantity'] ?? 0) : 0;
                $serialNumbers = $input ? ($input['serial_numbers'] ?? []) : [];
                $destLocationId = $input['warehouse_location_id'] ?? null;

                if (! $destLocationId) {
                    $defaultLoc = WarehouseLocation::where('warehouse_id', $transfer->destination_warehouse_id)
                        ->where('is_default', true)
                        ->first();
                    if (! $defaultLoc) {
                        throw new Exception('No default location found for destination warehouse.');
                    }
                    $destLocationId = $defaultLoc->id;
                }

                if ($qty > 0) {
                    $item->update([
                        'received_quantity' => $qty,
                        'received_serial_numbers' => $serialNumbers,
                    ]);

                    // Receive at destination warehouse
                    $this->stockService->receive(
                        companyId: $transfer->company_id,
                        productId: $item->product_id,
                        warehouseId: $transfer->destination_warehouse_id,
                        warehouseLocationId: $destLocationId,
                        quantity: $qty,
                        reference: $transfer,
                        userId: $userId,
                        remarks: "Transfer In {$transfer->transfer_number}"
                    );

                    // Update asset status
                    if (! empty($serialNumbers)) {
                        $assets = InventoryAsset::where('company_id', $transfer->company_id)
                            ->whereIn('serial_number', $serialNumbers)
                            ->get();

                        foreach ($assets as $asset) {
                            if ($asset->status !== 'IN_TRANSIT') {
                                throw new Exception("Asset {$asset->serial_number} is not in transit.");
                            }
                            $oldStatus = $asset->status;
                            $asset->update([
                                'status' => 'AVAILABLE',
                                'warehouse_location_id' => $destLocationId,
                            ]);
                            $this->assetService->recordHistory($asset, 'TRANSFERRED_IN', $oldStatus, 'AVAILABLE', $transfer, $userId);
                        }
                    }
                }
            }

            $transfer->update(['status' => 'COMPLETED']);

            return $transfer;
        });
    }
}
