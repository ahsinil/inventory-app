<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'receipt_number', 'supplier_id', 'warehouse_id', 'status', 'external_reference', 'expected_date', 'received_date', 'remarks', 'created_by', 'posted_by'])]
class Receipt extends Model
{
    use Auditable, BelongsToCompany, HasUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
            'received_date' => 'date',
        ];
    }

    public function items()
    {
        return $this->hasMany(ReceiptItem::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
