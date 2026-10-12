<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['inventory_asset_id', 'event', 'status_from', 'status_to', 'reference_type', 'reference_id', 'user_id', 'remarks'])]
class AssetHistory extends Model
{
    use HasUlid;

    public function asset()
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id');
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
