<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['stock_adjustment_id', 'product_id', 'warehouse_location_id', 'system_quantity', 'actual_quantity', 'difference'])]
class StockAdjustmentItem extends Model
{
    use Auditable, HasUlid;

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouseLocation()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:2',
            'actual_quantity' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }
}
