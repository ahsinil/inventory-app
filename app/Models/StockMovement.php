<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'product_id', 'warehouse_id', 'warehouse_location_id', 'type', 'quantity', 'balance_after', 'reference_type', 'reference_id', 'user_id', 'remarks'])]
class StockMovement extends Model
{
    use BelongsToCompany, HasUlid;

    public const UPDATED_AT = null; // append-only

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseLocation()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
