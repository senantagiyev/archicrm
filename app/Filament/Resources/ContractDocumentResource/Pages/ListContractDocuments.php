<?php

namespace App\Filament\Resources\ContractDocumentResource\Pages;

use App\Filament\Resources\ContractDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContractDocuments extends ListRecords
{
    protected static string $resource = ContractDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Sənəd yüklə'),
        ];
    }
}
