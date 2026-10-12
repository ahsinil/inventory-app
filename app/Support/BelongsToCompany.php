<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    /**
     * Boot the trait.
     */
    protected static function bootBelongsToCompany()
    {
        // By default, only show records for the current active company.
        // In a real app, you would determine the active company from the session or request.
        // For MVP-A, we assume the first active company if not set.

        static::addGlobalScope('company', function (Builder $builder) {
            if (config('app.multi_company_enabled', false)) {
                // Example: get company_id from a bound instance or session
                // $companyId = app('current_company_id');
                // if ($companyId) {
                //     $builder->where('company_id', $companyId);
                // }
            }
        });

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                // Assign a default company if not provided
                $defaultCompany = Company::first();
                if ($defaultCompany) {
                    $model->company_id = $defaultCompany->id;
                }
            }
        });
    }

    /**
     * Get the company that owns the model.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
