<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\BelongsToCompany;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'name', 'code', 'description'])]
class Unit extends Model
{
    use Auditable, BelongsToCompany, HasUlid, SoftDeletes;

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
