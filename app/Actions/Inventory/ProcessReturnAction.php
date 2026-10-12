<?php

namespace App\Actions\Inventory;

use App\Models\InventoryAsset;
use App\Models\StockReturn;
use App\Models\WarehouseLocation;
use App\Services\AssetService;
use App\Services\StockService;
use Exception;
use Illuminate\Support\Facades\DB;

class ProcessReturnAction
{
    protected StockService $stockService;

    protected AssetService $assetService;

    public function __construct(StockService $stockService, AssetService $assetService)
    {
        $this->stockService = $stockService;
        $this->assetService = $assetService;
    }

    public function execute(StockReturn $returnDoc, string $userId, array $inspectedItems): StockReturn
    {
        if ($returnDoc->status === 'POSTED') {
            throw new Exception('Return is already posted.');
        }

        return DB::transaction(function () use ($returnDoc, $userId, $inspectedItems) {
            foreach ($returnDoc->items as $item) {
                $input = collect($inspectedItems)->firstWhere('product_id', $item->product_id);
                $condition = $input ? ($input['condition'] ?? 'GOOD') : 'GOOD';
                $serialNumbers = $input ? ($input['serial_numbers'] ?? []) : [];

                $defaultLoc = WarehouseLocation::where('warehouse_id', $returnDoc->warehouse_id)
                    ->where('is_default', true)
                    ->first();

                $locationId = $defaultLoc ? $defaultLoc->id : null;
                if (! $locationId) {
                    throw new Exception('No default location found for warehouse.');
                }

                $item->update([
                    'condition' => $condition,
                    'returned_serial_numbers' => $serialNumbers,
                ]);

                // Receive back into stock
                // Optionally: If condition is DAMAGED or DISPOSED, it might be moved to a specific location or written off immediately.
                // For simplicity, we just receive it to the default location.
                $this->stockService->receive(
                    companyId: $returnDoc->company_id,
                    productId: $item->product_id,
                    warehouseId: $returnDoc->warehouse_id,
                    warehouseLocationId: $locationId,
                    quantity: $item->quantity,
                    reference: $returnDoc,
                    userId: $userId,
                    remarks: "Return {$returnDoc->return_number} - Condition: {$condition}"
                );

                // Update asset status
                if (! empty($serialNumbers)) {
                    $assets = InventoryAsset::where('company_id', $returnDoc->company_id)
                        ->whereIn('serial_number', $serialNumbers)
                        ->get();

                    foreach ($assets as $asset) {
                        $oldStatus = $asset->status;
                        $newAssetStatus = $condition === 'GOOD' ? 'AVAILABLE' : $condition; // e.g., DAMAGED, DISPOSED
                        $asset->update([
                            'status' => $newAssetStatus,
                            'warehouse_location_id' => $locationId,
                        ]);
                        $this->assetService->recordHistory($asset, 'RETURNED', $oldStatus, $newAssetStatus, $returnDoc, $userId);
                    }
                }
            }

            $returnDoc->update(['status' => 'POSTED']);

            return $returnDoc;
        });
    }
}
