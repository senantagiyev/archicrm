<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('convertToClient')
                ->label('Müştəriyə çevir')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Lidi müştəriyə çevir')
                ->modalDescription('Bu liddən yeni müştəri yaradılacaq və lidin statusu "Qazanılıb" olaraq işarələnəcək.')
                ->visible(fn (Lead $record) => $record->status !== LeadStatus::Won)
                ->action(function (Lead $record) {
                    $client = LeadResource::convertToClient($record);

                    Notification::make()
                        ->title('Müştəri yaradıldı')
                        ->body("\"{$client->name}\" müştəri kimi əlavə olundu.")
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalDescription('Lid silinəcək. Bu əməliyyat əməliyyat jurnalında qeyd olunur.'),
        ];
    }
}
