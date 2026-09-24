<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * `tenant_id` `User::$fillable`-də YOXDUR (və olmamalıdır — forma gövdəsindən
     * studiya dəyişdirilə bilməsin). Nəticədə yuxarıdaki `$data['tenant_id']`
     * `User::create()` tərəfindən SƏSSİZCƏ atılırdı: işçi yalnız `BelongsToTenant`
     * trait-inin `TenantContext`-dən möhürləməsi sayəsində düzgün studiyaya
     * düşürdü. Yəni resursun öz müdafiə xətti işləmirdi və kontekst hər hansı
     * səbəbdən boş olsaydı (konsol, növbə, yeni bir marşrut) işçi studiyasız
     * yaranardı — SetTenant onu hər sorğuda 403 edən ölü hesab.
     *
     * Ona görə studiya BİRBAŞA təyinatla yazılır: bu, mass-assignment qorumasını
     * yan keçmir, sadəcə ondan istifadə etmir.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenantId = $data['tenant_id'] ?? null;
        unset($data['tenant_id']);

        $user = new User($data);
        $user->tenant_id = $tenantId;
        $user->save();

        return $user;
    }
}
