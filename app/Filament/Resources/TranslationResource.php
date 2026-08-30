<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TranslationResource\Pages;
use App\Models\Translation;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TranslationResource extends Resource
{
    protected static ?string $model = Translation::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-language';
    protected static string | \UnitEnum | null $navigationGroup = 'Sistem';
    protected static ?int $navigationSort = 90;
    protected static ?string $navigationLabel = 'Tərcümələr';
    protected static ?string $modelLabel = 'Tərcümə';
    protected static ?string $pluralModelLabel = 'Tərcümələr';
    protected static ?string $recordTitleAttribute = 'key';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('group')
                    ->label('Qrup')
                    ->required()
                    ->maxLength(120)
                    ->datalist(fn () => Translation::query()->distinct()->orderBy('group')->pluck('group')->all())
                    ->helperText('Açarın birinci hissəsi, məs. "common", "home", "cart".'),

                Forms\Components\TextInput::make('key')
                    ->label('Açar (key)')
                    ->required()
                    ->maxLength(191)
                    ->helperText('Qrupdan sonrakı hissə, məs. "hero.title". Kodda: t(\'qrup.açar\').'),
            ]),

            Section::make('Dəyərlər')->columns(1)->schema([
                Forms\Components\Textarea::make('az')
                    ->label('🇦🇿 Azərbaycan')
                    ->required()
                    ->rows(2)
                    ->autosize(),

                Forms\Components\Textarea::make('ru')
                    ->label('🇷🇺 Русский')
                    ->rows(2)
                    ->autosize(),

                Forms\Components\Textarea::make('en')
                    ->label('🇬🇧 English')
                    ->rows(2)
                    ->autosize(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('group')
                    ->label('Qrup')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('key')
                    ->label('Açar')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('az')
                    ->label('🇦🇿 AZ')
                    ->state(fn (Translation $r) => $r->getTranslation('value', 'az', false))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('value->az', 'like', "%{$search}%"))
                    ->limit(50)
                    ->wrap(),
                Tables\Columns\TextColumn::make('ru')
                    ->label('🇷🇺 RU')
                    ->state(fn (Translation $r) => $r->getTranslation('value', 'ru', false))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('value->ru', 'like', "%{$search}%"))
                    ->limit(50)
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('en')
                    ->label('🇬🇧 EN')
                    ->state(fn (Translation $r) => $r->getTranslation('value', 'en', false))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('value->en', 'like', "%{$search}%"))
                    ->limit(50)
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('group')
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label('Qrup')
                    ->options(fn () => Translation::query()->distinct()->orderBy('group')->pluck('group', 'group')->all()),
                Tables\Filters\Filter::make('missing_ru')
                    ->label('RU boşdur')
                    ->query(fn ($query) => $query->where(fn ($q) => $q->whereNull('value->ru')->orWhere('value->ru', ''))),
                Tables\Filters\Filter::make('missing_en')
                    ->label('EN boşdur')
                    ->query(fn ($query) => $query->where(fn ($q) => $q->whereNull('value->en')->orWhere('value->en', ''))),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTranslations::route('/'),
            'create' => Pages\CreateTranslation::route('/create'),
            'edit' => Pages\EditTranslation::route('/{record}/edit'),
        ];
    }
}
