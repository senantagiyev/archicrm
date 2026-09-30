<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Models\ClientUser;
use App\Services\Portal\InvitationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Müştərinin portal hesabları. Hər hesab iki yolla girə bilər: e-poçta gələn
 * birdəfəlik linklə (həmişə) və şifrə ilə (təyin edilibsə).
 */
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
                Tables\Columns\TextColumn::make('login_method')
                    ->label('Giriş üsulu')
                    ->state(fn (ClientUser $record) => filled($record->password) ? 'Şifrə + link' : 'Yalnız link')
                    ->badge()
                    ->color(fn (ClientUser $record) => filled($record->password) ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('invited_at')
                    ->label('Dəvət tarixi')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
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

                Actions\Action::make('createWithPassword')
                    ->label('Şifrə ilə giriş yarat')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->modalDescription('Müştəri bu e-poçt və şifrə ilə portala daxil olacaq. Link ilə giriş də açıq qalır.')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Ad, soyad')
                            ->default(fn () => $this->getOwnerRecord()->name)
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('E-poçt')
                            ->email()
                            ->default(fn () => $this->getOwnerRecord()->email)
                            ->required(),
                        Forms\Components\TextInput::make('password')
                            ->label('Şifrə')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required()
                            ->minLength(8)
                            ->maxLength(72),
                    ])
                    ->action(function (array $data, InvitationService $service) {
                        $service->setPassword($this->getOwnerRecord(), $data['email'], $data['name'], $data['password']);
                    })
                    ->successNotificationTitle('Portal girişi yaradıldı'),
            ])
            ->actions([
                Actions\Action::make('setPassword')
                    ->label(fn (ClientUser $record) => filled($record->password) ? 'Şifrəni dəyiş' : 'Şifrə təyin et')
                    ->icon('heroicon-o-key')
                    ->form([
                        Forms\Components\TextInput::make('password')
                            ->label('Yeni şifrə')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required()
                            ->minLength(8)
                            ->maxLength(72),
                    ])
                    // `password` `hashed` cast-dadır — düz mətn burada heşlənir.
                    ->action(fn (ClientUser $record, array $data) => $record->forceFill(['password' => $data['password']])->save())
                    ->successNotificationTitle('Şifrə yeniləndi'),
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
