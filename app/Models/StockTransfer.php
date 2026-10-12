<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'transfer_number', 'source_warehouse_id', 'destination_warehouse_id', 'status', 'expected_date', 'remarks', 'created_by', 'approved_by'])]
class StockTransfer extends Model
{
    use Auditable, BelongsToCompany, HasUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
        ];
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function sourceWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }
}
