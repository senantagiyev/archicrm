<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $owner = Arr::only($data, ['owner_name', 'owner_email', 'owner_password']);
        $tenantData = Arr::except($data, array_keys($owner));

        return DB::transaction(function () use ($tenantData, $owner): Tenant {
            $tenant = Tenant::create($tenantData);

            app(TenantContext::class)->actingAs($tenant->id, fn (): User => User::create([
                'name' => $owner['owner_name'],
                'email' => $owner['owner_email'],
                'password' => $owner['owner_password'],
                'role' => 'owner',
                'is_active' => true,
            ]));

            return $tenant;
        });
    }
}
