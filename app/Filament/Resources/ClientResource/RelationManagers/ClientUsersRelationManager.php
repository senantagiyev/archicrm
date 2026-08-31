<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Services\Portal\InvitationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ClientUsersRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'clientUsers';

    protected static ?string $title = 'Portal girişləri';

    protected static ?string $modelLabel = 'Portal istifadəçisi';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad')
                    ->description(fn ($record) => $record->email),
                Tables\Columns\TextColumn::make('invited_at')
                    ->label('Dəvət tarixi')
                    ->dateTime('d.m.Y H:i'),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Son giriş')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Hələ giriş etməyib'),
            ])
            ->headerActions([
                Actions\Action::make('invite')
                    ->label('Dəvət göndər')
                    ->icon('heroicon-o-envelope')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Ad, soyad')
                            ->default(fn () => $this->getOwnerRecord()->name)
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('E-poçt')
                            ->email()
                            ->default(fn () => $this->getOwnerRecord()->email)
                            ->required()
                            ->helperText('Giriş yalnız göstərdiyiniz poçt üçün açılacaq. Link 7 gün etibarlıdır.'),
                    ])
                    ->action(function (array $data, InvitationService $service) {
                        $service->invite($this->getOwnerRecord(), $data['name'], $data['email']);
                    })
                    ->successNotificationTitle('Dəvət göndərildi'),
            ])
            ->actions([
                Actions\Action::make('resend')
                    ->label('Linki yenidən göndər')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn ($record, InvitationService $service) => $service->invite(
                        $this->getOwnerRecord(),
                        $record->name,
                        $record->email,
                    ))
                    ->successNotificationTitle('Link göndərildi'),
                Actions\DeleteAction::make()
                    ->label('Girişi ləğv et')
                    ->requiresConfirmation()
                    ->modalDescription('Bu şəxsin portala girişi bağlanacaq.'),
            ]);
    }
}
