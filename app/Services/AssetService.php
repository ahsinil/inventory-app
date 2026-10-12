<?php

namespace App\Services;

use App\Models\AssetHistory;
use App\Models\InventoryAsset;
use App\Models\Product;
use Exception;
use Illuminate\Database\Eloquent\Model;

class AssetService
{
    /**
     * Create new assets from receiving.
     */
    public function receiveAssets(
        string $companyId,
        Product $product,
        array $serialNumbers,
        string $warehouseLocationId,
        Model $reference,
        ?string $userId = null
    ): void {
        if (! $product->has_serial_number) {
            return;
        }

        foreach ($serialNumbers as $serial) {
            // Validate unique serial per company
            $exists = InventoryAsset::where('company_id', $companyId)
                ->where('serial_number', $serial)
                ->exists();

            if ($exists) {
                throw new Exception("Serial number {$serial} already exists in this company.");
            }

            $asset = InventoryAsset::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'serial_number' => $serial,
                'status' => 'AVAILABLE',
                'warehouse_location_id' => $warehouseLocationId,
            ]);

            $this->recordHistory($asset, 'RECEIVED', null, 'AVAILABLE', $reference, $userId);
        }
    }

    /**
     * Record asset state history
     */
    public function recordHistory(
        InventoryAsset $asset,
        string $event,
        ?string $statusFrom,
        string $statusTo,
        Model $reference,
        ?string $userId = null,
        ?string $remarks = null
    ): void {
        AssetHistory::create([
            'inventory_asset_id' => $asset->id,
            'event' => $event,
            'status_from' => $statusFrom,
            'status_to' => $statusTo,
            'reference_type' => get_class($reference),
            'reference_id' => $reference->id,
            'user_id' => $userId,
            'remarks' => $remarks,
        ]);
    }
}
