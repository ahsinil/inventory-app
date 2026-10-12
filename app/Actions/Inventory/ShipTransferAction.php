<?php

namespace App\Actions\Inventory;

use App\Models\InventoryAsset;
use App\Models\StockTransfer;
use App\Services\AssetService;
use App\Services\StockService;
use Exception;
use Illuminate\Support\Facades\DB;

class ShipTransferAction
{
    protected StockService $stockService;

    protected AssetService $assetService;

    public function __construct(StockService $stockService, AssetService $assetService)
    {
        $this->stockService = $stockService;
        $this->assetService = $assetService;
    }

    public function execute(StockTransfer $transfer, string $userId, array $shippedItems): StockTransfer
    {
        if ($transfer->status !== 'APPROVED') {
            throw new Exception('Transfer must be APPROVED to be shipped.');
        }

        return DB::transaction(function () use ($transfer, $userId, $shippedItems) {
            foreach ($transfer->items as $item) {
                $input = collect($shippedItems)->firstWhere('product_id', $item->product_id);
                $qty = $input ? ($input['sent_quantity'] ?? 0) : 0;
                $serialNumbers = $input ? ($input['serial_numbers'] ?? []) : [];
                $sourceLocationId = $input['warehouse_location_id'] ?? null;

                if (! $sourceLocationId) {
                    throw new Exception('Source warehouse location is required to ship product.');
                }

                if ($qty > 0) {
                    // Update item
                    $item->update([
                        'sent_quantity' => $qty,
                        'sent_serial_numbers' => $serialNumbers,
                    ]);

                    // Issue from source warehouse
                    $this->stockService->issue(
                        companyId: $transfer->company_id,
                        productId: $item->product_id,
                        warehouseId: $transfer->source_warehouse_id,
                        warehouseLocationId: $sourceLocationId,
                        quantity: $qty,
                        reference: $transfer,
                        userId: $userId,
                        remarks: "Transfer Out {$transfer->transfer_number}"
                    );

                    // Update asset status
                    if (! empty($serialNumbers)) {
                        $assets = InventoryAsset::where('company_id', $transfer->company_id)
                            ->whereIn('serial_number', $serialNumbers)
                            ->get();

                        if ($assets->count() !== count($serialNumbers)) {
                            throw new Exception('Some serial numbers were not found.');
                        }

                        foreach ($assets as $asset) {
                            if ($asset->status !== 'AVAILABLE') {
                                throw new Exception("Asset {$asset->serial_number} is not available.");
                            }
                            $oldStatus = $asset->status;
                            $asset->update(['status' => 'IN_TRANSIT']);
                            $this->assetService->recordHistory($asset, 'TRANSFERRED_OUT', $oldStatus, 'IN_TRANSIT', $transfer, $userId);
                        }
                    }
                }
            }

            $transfer->update(['status' => 'IN_TRANSIT']);

            return $transfer;
        });
    }
}
