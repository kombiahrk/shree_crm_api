<?php

namespace App\Models\Traits;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToOrganization
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    protected static function bootBelongsToOrganization()
    {
        static::addGlobalScope(new OrganizationScope);

        // Automatically set organization_id on model creation if authenticated
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->organization_id = Auth::user()->organization_id;
            }
        });
    }

    /**
     * Get a new query builder that includes the global scope.
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public static function withOrganization(): Builder
    {
        return (new static)->newQueryWithoutScope(new OrganizationScope);
    }
}
