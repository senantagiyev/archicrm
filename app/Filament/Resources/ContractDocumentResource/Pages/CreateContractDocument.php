<?php

namespace App\Filament\Resources\ContractDocumentResource\Pages;

use App\Filament\Resources\ContractDocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContractDocument extends CreateRecord
{
    protected static string $resource = ContractDocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by_type'] = 'user';
        $data['uploaded_by_id'] = auth()->id();

        return $data;
    }
}
