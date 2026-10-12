<?php

namespace App\Actions\Inventory;

use App\Models\StockAdjustment;
use App\Services\StockService;
use Exception;
use Illuminate\Support\Facades\DB;

class AdjustStockAction
{
    protected StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Posts a stock adjustment, updating balances.
     */
    public function execute(StockAdjustment $adjustment, string $userId): StockAdjustment
    {
        if ($adjustment->status === 'POSTED') {
            throw new Exception('Adjustment is already posted.');
        }

        return DB::transaction(function () use ($adjustment, $userId) {
            foreach ($adjustment->items as $item) {
                $difference = $item->difference; // actual_quantity - system_quantity

                if ($difference > 0) {
                    $this->stockService->receive(
                        companyId: $adjustment->company_id,
                        productId: $item->product_id,
                        warehouseId: $adjustment->warehouse_id,
                        warehouseLocationId: $item->warehouse_location_id,
                        quantity: $difference,
                        reference: $adjustment,
                        userId: $userId,
                        remarks: "Adjustment {$adjustment->adjustment_number} (Surplus)"
                    );
                } elseif ($difference < 0) {
                    $this->stockService->issue(
                        companyId: $adjustment->company_id,
                        productId: $item->product_id,
                        warehouseId: $adjustment->warehouse_id,
                        warehouseLocationId: $item->warehouse_location_id,
                        quantity: abs($difference),
                        reference: $adjustment,
                        userId: $userId,
                        remarks: "Adjustment {$adjustment->adjustment_number} (Deficit)"
                    );
                }
            }

            $adjustment->update([
                'status' => 'POSTED',
                'approved_by' => $userId,
            ]);

            return $adjustment;
        });
    }
}
