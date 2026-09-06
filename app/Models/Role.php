<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use Illuminate\Database\Eloquent\Model;

/**
 * A DB-backed access role (TZ V1.2). System roles mirror the StaffRole enum and
 * are seeded from AccessMatrix; custom roles are created by owners. The `levels`
 * map is {domain value => AccessLevel int}.
 */
class Role extends Model
{
    protected $fillable = ['key', 'name', 'levels', 'own_projects_only', 'is_system', 'active'];

    protected function casts(): array
    {
        return [
            'levels' => 'array',
            'own_projects_only' => 'boolean',
            'is_system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function level(Domain $domain): AccessLevel
    {
        $value = $this->levels[$domain->value] ?? 0;

        return AccessLevel::from((int) $value);
    }
}
