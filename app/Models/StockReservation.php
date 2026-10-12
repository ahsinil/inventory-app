<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'reservation_number', 'warehouse_id', 'status', 'reference_type', 'reference_id', 'customer_id', 'expires_at', 'remarks', 'created_by'])]
class StockReservation extends Model
{
    use Auditable, BelongsToCompany, HasUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(StockReservationItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
