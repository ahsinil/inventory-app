<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUlid;

    public const UPDATED_AT = null; // No updated_at for audit log

    protected $fillable = [
        'user_id',
        'service_credential_id',
        'action',
        'resource_type',
        'resource_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'json',
        'new_values' => 'json',
    ];
}
