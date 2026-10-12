<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'available_at' => 'datetime',
            'fanned_out_at' => 'datetime',
        ];
    }
}
