<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'return_number', 'warehouse_id', 'status', 'reference_type', 'reference_id', 'returned_by', 'reason', 'created_by'])]
class StockReturn extends Model
{
    use Auditable, BelongsToCompany, HasUlid, SoftDeletes;

    public function items()
    {
        return $this->hasMany(StockReturnItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
