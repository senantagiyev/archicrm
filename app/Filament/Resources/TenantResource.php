<?php

namespace App\Filament\Resources;

use App\Enums\StaffRole;
use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Studiyalar';

    protected static ?string $modelLabel = 'Studiya';

    protected static ?string $pluralModelLabel = 'Studiyalar';

    protected static ?string $recordTitleAttribute = 'name';

    /** Platform-level surface — Owner only. */
    public static function canAccess(): bool
    {
        return auth()->user()?->role === StaffRole::Owner;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Studiya')->columns(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Ad')
                    ->required()
                    ->maxLength(191)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug((string) $state))),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true)
                    ->helperText('Unikal identifikator (URL üçün).'),
                Forms\Components\Toggle::make('active')
                    ->label('Aktiv')
                    ->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad')
                    ->description(fn (Tenant $r) => $r->slug)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Aktiv')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaradılıb')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->defaultSort('id')
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
