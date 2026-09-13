<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A DB-backed access role (TZ V1.2). System roles mirror the StaffRole enum and
 * are seeded from AccessMatrix; custom roles are created by owners. The `levels`
 * map is {domain value => AccessLevel int}.
 */
class Role extends Model
{
    protected $fillable = ['tenant_id', 'key', 'name', 'levels', 'own_projects_only', 'is_system', 'active'];

    protected function casts(): array
    {
        return [
            'levels' => 'array',
            'own_projects_only' => 'boolean',
            'is_system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * A studio's own row shadows the platform-wide one (tenant_id = null). The
     * ordering is what makes copy-on-write work: edit a built-in role inside a
     * studio and that studio gets its own copy, without touching anyone else's.
     */
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query
            ->where(fn (Builder $inner) => $inner->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is null');
    }

    public function level(Domain $domain): AccessLevel
    {
        $value = $this->levels[$domain->value] ?? 0;

        return AccessLevel::from((int) $value);
    }
}
