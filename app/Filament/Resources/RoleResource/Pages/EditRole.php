<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Models\Role;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                // Platform-wide rows belong to every studio; only a studio's own
                // custom role can be removed from here.
                ->visible(fn (Role $record) => ! $record->is_system && $record->tenant_id !== null),
        ];
    }

    /**
     * Copy-on-write. Saving a platform-wide role from inside a studio creates
     * that studio's own copy instead of editing the shared row — which used to
     * rewrite the access matrix for every studio on the installation.
     */
    protected function handleRecordUpdate($record, array $data): Role
    {
        $tenantId = auth()->user()?->tenant_id;

        if ($record->tenant_id === null && $tenantId !== null) {
            $copy = Role::create(array_merge(
                $record->only(['key', 'name', 'levels', 'own_projects_only', 'active']),
                $data,
                // The fork is the studio's own row, not a built-in one. Copying
                // `is_system` made it permanently undeletable, so a studio could
                // never revert to the platform default once it edited a role.
                ['tenant_id' => $tenantId, 'is_system' => false],
            ));

            AccessMatrix::flushCache();

            $this->record = $copy;

            return $copy;
        }

        $record->update($data);
        AccessMatrix::flushCache();

        return $record;
    }
}
