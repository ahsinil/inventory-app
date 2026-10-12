<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'is_active'])]
class Company extends Model
{
    use HasUlid;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
