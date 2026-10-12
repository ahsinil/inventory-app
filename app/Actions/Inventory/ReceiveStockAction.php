<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\Receipt;
use App\Models\WarehouseLocation;
use App\Services\AssetService;
use App\Services\StockService;
use Exception;
use Illuminate\Support\Facades\DB;

class ReceiveStockAction
{
    protected StockService $stockService;

    protected AssetService $assetService;

    public function __construct(StockService $stockService, AssetService $assetService)
    {
        $this->stockService = $stockService;
        $this->assetService = $assetService;
    }

    /**
     * Posts a receipt, updating stock balances and moving state to POSTED.
     */
    public function execute(Receipt $receipt, string $userId, array $receivedItems): Receipt
    {
        if ($receipt->status === 'POSTED') {
            throw new Exception('Receipt is already posted.');
        }

        // Must run in transaction
        return DB::transaction(function () use ($receipt, $userId, $receivedItems) {
            $defaultLocation = WarehouseLocation::where('warehouse_id', $receipt->warehouse_id)
                ->where('is_default', true)
                ->first();

            if (! $defaultLocation) {
                throw new Exception("No default location found for warehouse {$receipt->warehouse_id}");
            }

            foreach ($receipt->items as $item) {
                // Find received quantity from input
                $input = collect($receivedItems)->firstWhere('product_id', $item->product_id);
                $qty = $input ? ($input['received_quantity'] ?? 0) : 0;
                $serialNumbers = $input ? ($input['serial_numbers'] ?? []) : [];

                if ($qty > 0) {
                    $product = Product::find($item->product_id);
                    if ($product->has_serial_number && count($serialNumbers) !== $qty) {
                        throw new Exception("Product {$product->sku} requires exactly {$qty} serial numbers.");
                    }

                    // Update item
                    $item->update(['received_quantity' => $qty]);

                    // Add stock
                    $this->stockService->receive(
                        companyId: $receipt->company_id,
                        productId: $item->product_id,
                        warehouseId: $receipt->warehouse_id,
                        warehouseLocationId: $defaultLocation->id, // put into staging area first
                        quantity: $qty,
                        reference: $receipt,
                        userId: $userId,
                        remarks: "Receipt {$receipt->receipt_number}"
                    );

                    // Add assets
                    if ($product->has_serial_number) {
                        $this->assetService->receiveAssets(
                            $receipt->company_id,
                            $product,
                            $serialNumbers,
                            $defaultLocation->id,
                            $receipt,
                            $userId
                        );
                    }
                }
            }

            $receipt->update([
                'status' => 'POSTED',
                'received_date' => now(),
                'posted_by' => $userId,
            ]);

            return $receipt;
        });
    }
}
