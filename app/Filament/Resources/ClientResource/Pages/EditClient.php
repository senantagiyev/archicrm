<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createProject')
                ->label('Layihə yarat')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->url(fn () => ProjectResource::getUrl('create', ['client' => $this->record->id])),
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription('Müştəri silinəcək. Bu əməliyyat əməliyyat jurnalında qeyd olunur.'),
        ];
    }
}
