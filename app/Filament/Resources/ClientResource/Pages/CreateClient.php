<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Concerns\SyncsPortalPassword;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    use SyncsPortalPassword;

    protected static string $resource = ClientResource::class;

    protected function afterCreate(): void
    {
        $this->syncPortalPassword();
    }
}
