<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A studio (tenant) in the SaaS. Operational data (clients, projects, finance,
 * users, portal accounts) is isolated per tenant via the BelongsToTenant trait;
 * shared catalogs (roles, automations, brief bank, translations) stay global.
 */
class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'active', 'settings'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'settings' => 'array'];
    }
}
