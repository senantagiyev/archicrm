<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\AccessMatrix;
use Illuminate\Database\Seeder;

/**
 * Seeds the 6 built-in roles from the canonical AccessMatrix. firstOrCreate keeps
 * an owner's later edits to a system role intact across re-seeds; custom roles are
 * created via the Filament constructor and never touched here.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AccessMatrix::systemRoles() as $key => $data) {
            Role::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $data['name'],
                    'levels' => $data['levels'],
                    'own_projects_only' => $data['own'],
                    'is_system' => true,
                    'active' => true,
                ],
            );
        }
    }
}
