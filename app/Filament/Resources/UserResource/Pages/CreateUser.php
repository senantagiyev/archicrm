<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * A platform admin has no tenant of their own, so creating staff from their
     * session left `tenant_id = NULL` — an account SetTenant then 403s on every
     * request. Staff belong to a studio; the platform admin adds them from inside
     * that studio's own Komanda, not from the registry.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenantId = auth()->user()?->tenant_id;

        abort_if(
            $tenantId === null,
            403,
            'İşçi yaratmaq üçün studiya konteksti lazımdır — platforma admini hesabı heç bir studiyaya bağlı deyil.',
        );

        $data['tenant_id'] = $tenantId;

        return $data;
    }
}
