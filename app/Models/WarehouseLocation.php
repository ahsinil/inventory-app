<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['warehouse_id', 'code', 'name', 'is_default'])]
class WarehouseLocation extends Model
{
    use Auditable, HasUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
