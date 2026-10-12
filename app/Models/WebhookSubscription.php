<?php

namespace App\Models;

use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Model;

class WebhookSubscription extends Model
{
    use HasUlid;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'is_active' => 'boolean'];
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function matches(string $eventType): bool
    {
        foreach ($this->events ?? [] as $pattern) {
            if ($pattern === '*' || $pattern === $eventType || fnmatch($pattern, $eventType)) {
                return true;
            }
        }

        return false;
    }
}
