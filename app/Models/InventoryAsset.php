<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'product_id', 'serial_number', 'status', 'warehouse_location_id', 'remarks'])]
class InventoryAsset extends Model
{
    use BelongsToCompany, HasUlid;

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouseLocation()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
}
