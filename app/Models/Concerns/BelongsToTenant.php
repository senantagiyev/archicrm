<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant isolation (TZ multi-tenancy). While a tenant is in context the model
 * is (a) filtered to that tenant by a global scope and (b) stamped with it on
 * create. With no tenant in context (CLI, queue, tests, pre-auth) the scope is
 * inert, so those paths see and write everything unchanged.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function ($model): void {
            $ctx = app(TenantContext::class);
            if ($ctx->has() && $model->tenant_id === null) {
                $model->tenant_id = $ctx->id();
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder): void {
            $ctx = app(TenantContext::class);
            if ($ctx->has()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $ctx->id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
