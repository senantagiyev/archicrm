<?php

namespace App\Filament\Resources;

use App\Enums\ClientSource;
use App\Enums\ClientStatus;
use App\Enums\StaffRole;
use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers\ClientUsersRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ContactLogsRelationManager;
use App\Models\Client;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Müştərilər';

    protected static ?string $modelLabel = 'Müştəri';

    protected static ?string $pluralModelLabel = 'Müştərilər';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Əsas məlumatlar')->columns(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Ad, soyad')
                    ->required()
                    ->maxLength(191),
                Forms\Components\TextInput::make('company')
                    ->label('Şirkət')
                    ->maxLength(191),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(ClientStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(ClientStatus::Lead->value)
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('source')
                    ->label('Müraciət mənbəyi')
                    ->options(collect(ClientSource::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->native(false),
                Forms\Components\Select::make('responsible_user_id')
                    ->label('Məsul menecer')
                    ->options(fn () => User::query()
                        ->where('is_active', true)
                        ->whereIn('role', [StaffRole::Owner->value, StaffRole::ProjectManager->value])
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->native(false),
                Forms\Components\DatePicker::make('first_contact_at')
                    ->label('İlk müraciət tarixi')
                    ->default(now())
                    ->native(false),
            ]),

            Section::make('Əlaqə')->columns(2)->schema([
                Forms\Components\TextInput::make('phone')
                    ->label('Telefon')
                    ->tel()
                    ->maxLength(32),
                Forms\Components\TextInput::make('email')
                    ->label('E-poçt')
                    ->email()
                    ->maxLength(191),
                Forms\Components\TextInput::make('whatsapp')
                    ->label('WhatsApp')
                    ->maxLength(32),
                Forms\Components\TextInput::make('telegram')
                    ->label('Telegram')
                    ->maxLength(64),
            ]),

            Section::make()->schema([
                Forms\Components\Textarea::make('notes')
                    ->label('Qeydlər')
                    ->rows(3)
                    ->autosize(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad, soyad')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Client $r) => $r->company),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ClientStatus $state) => $state->label())
                    ->color(fn (ClientStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Mənbə')
                    ->formatStateUsing(fn (?ClientSource $state) => $state?->label())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Məsul')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('projects_count')
                    ->label('Layihələr')
                    ->counts('projects')
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_contact_at')
                    ->label('İlk müraciət')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('responsible_user_id')
                    ->label('Məsul menecer')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Mənbə')
                    ->options(collect(ClientSource::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ContactLogsRelationManager::class,
            ClientUsersRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'company', 'phone', 'email'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
