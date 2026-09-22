<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Pages\ChatCenter;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('chat')
                ->label('Çat')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->url(fn () => ChatCenter::getUrl(['project' => $this->record->id])),

            // Roomix «Subprojects»: təmir mərhələsi üçün ayrıca məkan — öz çatı,
            // mərhələləri və faylları olur, amma eyni müştəriyə bağlı qalır.
            // Alt-layihənin özündən yenisini yaratmaq olmur: iki səviyyə kifayətdir,
            // dərin ağac siyahıları oxunmaz edir.
            Actions\Action::make('createSubproject')
                ->label('Alt-layihə yarat')
                ->icon('heroicon-o-squares-plus')
                ->color('gray')
                ->visible(fn () => ! $this->record->isSubproject())
                ->schema([
                    TextInput::make('name')
                        ->label('Alt-layihənin adı')
                        ->default('Təmir')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data) {
                    /** @var Project $parent */
                    $parent = $this->record;

                    $child = Project::create([
                        'client_id' => $parent->client_id,
                        'parent_project_id' => $parent->id,
                        'name' => $data['name'],
                        // Tip, ünvan və menecer valideyndən götürülür: alt-layihə
                        // eyni obyektin eyni komanda ilə davamıdır.
                        'type' => $parent->type,
                        'address' => $parent->address,
                        'manager_user_id' => $parent->manager_user_id,
                        'client_response_days' => $parent->client_response_days,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Alt-layihə yaradıldı')
                        ->body($child->name)
                        ->send();

                    $this->redirect(ProjectResource::getUrl('edit', ['record' => $child]));
                }),

            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Layihəni sil')
                ->modalDescription('Layihə bütün mərhələləri və tapşırıqları ilə birlikdə silinəcək. Bu kritik əməliyyatdır və əməliyyat jurnalında qeyd olunur.'),
        ];
    }
}
