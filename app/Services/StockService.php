<?php

namespace App\Services;

use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Integration\OutboxService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockService
{
    /**
     * Increment stock.
     */
    public function receive(
        string $companyId,
        string $productId,
        string $warehouseId,
        string $warehouseLocationId,
        float $quantity,
        Model $reference, // Receipt, Adjustment, etc.
        ?string $userId = null,
        ?string $remarks = null
    ): void {
        $this->recordMovement('IN', $companyId, $productId, $warehouseId, $warehouseLocationId, $quantity, $reference, $userId, $remarks);
    }

    /**
     * Decrement stock.
     */
    public function issue(
        string $companyId,
        string $productId,
        string $warehouseId,
        string $warehouseLocationId,
        float $quantity,
        Model $reference,
        ?string $userId = null,
        ?string $remarks = null
    ): void {
        $this->recordMovement('OUT', $companyId, $productId, $warehouseId, $warehouseLocationId, $quantity, $reference, $userId, $remarks);
    }

    /**
     * Record a stock movement safely inside a transaction using row locking.
     */
    protected function recordMovement(
        string $type,
        string $companyId,
        string $productId,
        string $warehouseId,
        string $warehouseLocationId,
        float $quantity,
        Model $reference,
        ?string $userId,
        ?string $remarks
    ): void {
        if ($quantity <= 0) {
            throw new Exception('Quantity must be greater than zero.');
        }

        DB::transaction(function () use (
            $type, $companyId, $productId, $warehouseId, $warehouseLocationId, $quantity, $reference, $userId, $remarks
        ) {
            // Get or create balance record with write lock
            $balance = StockBalance::where([
                'company_id' => $companyId,
                'product_id' => $productId,
                'warehouse_location_id' => $warehouseLocationId,
            ])->lockForUpdate()->first();

            if (! $balance) {
                $balance = StockBalance::create([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'warehouse_location_id' => $warehouseLocationId,
                    'quantity' => 0,
                ]);
                // Lock the newly created row immediately to prevent race conditions just in case
                $balance = StockBalance::where('id', $balance->id)->lockForUpdate()->first();
            }

            // Calculate new balance
            $oldQuantity = $balance->quantity;
            $newQuantity = $type === 'IN'
                ? $oldQuantity + $quantity
                : $oldQuantity - $quantity;

            // TODO: check negative stock allowed flag from settings
            if ($newQuantity < 0) {
                // Check if negative stock is allowed (placeholder - assume not allowed for now)
                $allowNegative = false; // \App\Models\Setting::get($companyId, 'allow_negative_stock', false);
                if (! $allowNegative) {
                    throw new Exception("Insufficient stock for product ID: {$productId}. Required: {$quantity}, Available: {$oldQuantity}");
                }
            }

            // Update balance
            $balance->quantity = $newQuantity;
            $balance->save();

            // Insert append-only movement record
            StockMovement::insert([
                'id' => Str::ulid(),
                'company_id' => $companyId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $warehouseLocationId,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $newQuantity,
                'reference_type' => get_class($reference),
                'reference_id' => $reference->id,
                'user_id' => $userId,
                'remarks' => $remarks,
                'created_at' => now(),
            ]);

            app(OutboxService::class)->record(
                'stock.moved', $companyId, 'product', $productId, [
                    'type' => $type,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'warehouse_location_id' => $warehouseLocationId,
                    'quantity' => $quantity,
                    'balance_after' => $newQuantity,
                    'reference_type' => get_class($reference),
                    'reference_id' => $reference->id,
                ]
            );
        });
    }
}
