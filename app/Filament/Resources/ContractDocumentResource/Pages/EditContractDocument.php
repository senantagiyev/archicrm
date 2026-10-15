<?php

namespace App\Filament\Resources\ContractDocumentResource\Pages;

use App\Filament\Resources\ContractDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditContractDocument extends EditRecord
{
    protected static string $resource = ContractDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription('Sənəd silinəcək. Bu əməliyyat əməliyyat jurnalında qeyd olunur.'),
        ];
    }
}
