<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'sku', 'name', 'description', 'category_id', 'brand_id', 'unit_id', 'has_serial_number', 'minimum_stock', 'cost_price', 'selling_price', 'currency'])]
class Product extends Model
{
    use Auditable, BelongsToCompany, HasUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'has_serial_number' => 'boolean',
            'minimum_stock' => 'decimal:2',
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function stockBalances()
    {
        return $this->hasMany(StockBalance::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
