<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'adjustment_number', 'warehouse_id', 'status', 'reason', 'remarks', 'created_by', 'approved_by'])]
class StockAdjustment extends Model
{
    use Auditable, BelongsToCompany, HasUlid, SoftDeletes;

    public function items()
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
