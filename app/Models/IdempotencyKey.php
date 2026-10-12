<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }
}
