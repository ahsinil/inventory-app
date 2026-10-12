<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }
}
