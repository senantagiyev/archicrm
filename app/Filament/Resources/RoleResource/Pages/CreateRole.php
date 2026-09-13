<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Support\AccessMatrix;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * `Role` deliberately does not use BelongsToTenant (platform-wide rows have a
     * null tenant), so nothing stamps a new row. Without this every custom role a
     * studio created landed as tenant_id = NULL — the platform-wide row that
     * `Role::scopeForTenant` then hands to EVERY studio, assignable from their
     * Komanda dropdown. It also made the role undeletable, since the delete gate
     * requires a tenant-owned row.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()?->tenant_id;
        $data['is_system'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        AccessMatrix::flushCache();
    }
}
