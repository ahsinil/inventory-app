<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    /**
     * Boot the trait.
     */
    protected static function bootAuditable()
    {
        static::created(function (Model $model) {
            static::logAudit($model, 'CREATED');
        });

        static::updated(function (Model $model) {
            static::logAudit($model, 'UPDATED');
        });

        static::deleted(function (Model $model) {
            static::logAudit($model, 'DELETED');
        });
    }

    /**
     * Log the audit trail.
     */
    protected static function logAudit(Model $model, string $action)
    {
        // Don't log if running in console without authentication
        if (app()->runningInConsole() && ! auth()->check()) {
            return;
        }

        $oldValues = $action === 'UPDATED' ? $model->getOriginal() : null;
        $newValues = $action !== 'DELETED' ? $model->getAttributes() : null;

        AuditLog::create([
            'user_id' => auth()->id(),
            'service_credential_id' => request()->attributes->get('service_credential_id'),
            'action' => $action,
            'resource_type' => get_class($model),
            'resource_id' => $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
