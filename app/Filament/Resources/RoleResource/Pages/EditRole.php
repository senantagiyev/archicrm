<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Models\Role;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** Set when this save created the studio's copy, so the URL moves to it. */
    private bool $forked = false;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label(fn (Role $record) => $record->isStudioCopy() ? 'Standarta qaytar' : 'Sil')
                // Platform-wide rows belong to every studio; only a studio's own
                // custom role can be removed from here.
                ->visible(fn (Role $record) => ! $record->is_system && $record->tenant_id !== null),
        ];
    }

    /**
     * A platform row the studio has already copied opens as that copy. The list
     * hides such rows, but an old link or a second tab still points at the
     * platform id — saving it again used to try a second copy and fail with
     * «açar artıq istifadə olunub».
     */
    protected function resolveRecord(int|string $key): Model
    {
        $tenantId = auth()->user()?->tenant_id;
        $record = Role::query()->forTenant($tenantId)->whereKey($key)->first();

        if ($record === null) {
            throw (new ModelNotFoundException)->setModel(Role::class, [$key]);
        }

        return $record->editableFor($tenantId);
    }

    /**
     * Copy-on-write. Saving a platform-wide role from inside a studio creates
     * that studio's own copy instead of editing the shared row — which used to
     * rewrite the access matrix for every studio on the installation. The copy
     * then replaces the platform row in the list; later saves update it.
     */
    protected function handleRecordUpdate($record, array $data): Role
    {
        $tenantId = auth()->user()?->tenant_id;
        $record = $record->editableFor($tenantId);

        if ($record->tenant_id === null && $tenantId !== null) {
            $copy = Role::create(array_merge(
                $record->only(['key', 'name', 'levels', 'own_projects_only', 'active']),
                $data,
                // The fork is the studio's own row, not a built-in one. Copying
                // `is_system` made it permanently undeletable, so a studio could
                // never revert to the platform default once it edited a role.
                // The key is the platform one — it is what makes the copy
                // replace the role for every holder of the staff enum.
                ['tenant_id' => $tenantId, 'is_system' => false, 'key' => $record->key],
            ));

            AccessMatrix::flushCache();

            $this->record = $copy;
            $this->forked = true;

            return $copy;
        }

        $record->update($data);
        AccessMatrix::flushCache();

        $this->record = $record;

        return $record;
    }

    protected function getRedirectUrl(): ?string
    {
        // Stay on the form, but on the copy's own URL — a reload then opens the
        // row that was just saved, not the platform original.
        return $this->forked
            ? static::getResource()::getUrl('edit', ['record' => $this->record])
            : parent::getRedirectUrl();
    }
}
