<?php

namespace App\Filament\Resources;

use App\Enums\TimeEntrySource;
use App\Filament\Resources\TimeEntryResource\Pages;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class TimeEntryResource extends Resource
{
    protected static ?string $model = TimeEntry::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Komanda';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Vaxt uçotu';

    protected static ?string $modelLabel = 'Vaxt qeydi';

    protected static ?string $pluralModelLabel = 'Vaxt uçotu';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('project_id')
                ->label('Layihə')
                ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                ->searchable()->required()->live()->native(false)
                ->afterStateUpdated(fn (Set $set) => $set('stage_id', null)),
            Forms\Components\Select::make('user_id')
                ->label('İşçi')
                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => auth()->id())
                ->searchable()->required()->native(false),
            Forms\Components\Select::make('stage_id')
                ->label('Mərhələ')
                ->options(fn (Get $get) => $get('project_id')
                    ? Stage::where('project_id', $get('project_id'))->orderBy('position')->pluck('name', 'id')
                    : [])
                ->native(false),
            Forms\Components\Select::make('task_id')
                ->label('Tapşırıq')
                ->options(fn (Get $get) => $get('project_id')
                    ? Task::where('project_id', $get('project_id'))->orderBy('title')->pluck('title', 'id')
                    : [])
                ->searchable()->native(false),
            Forms\Components\DateTimePicker::make('started_at')->label('Başlanğıc')->native(false),
            Forms\Components\DateTimePicker::make('ended_at')->label('Bitmə')->native(false),
            Forms\Components\TextInput::make('duration_minutes')
                ->label('Müddət (dəqiqə)')->numeric()->default(0)->minValue(0)
                ->helperText('Başlanğıc/bitmə göstərilsə, avtomatik hesablanır.'),
            Forms\Components\Select::make('source')
                ->label('Mənbə')
                ->options(collect(TimeEntrySource::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(TimeEntrySource::Manual->value)->native(false),
            Forms\Components\TextInput::make('comment')->label('Şərh')->maxLength(191)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('İşçi')->sortable(),
                Tables\Columns\TextColumn::make('project.name')->label('Layihə')->sortable()->wrap(),
                Tables\Columns\TextColumn::make('task.title')->label('Tapşırıq')->toggleable()->wrap(),
                Tables\Columns\TextColumn::make('duration_minutes')
                    ->label('Müddət')
                    ->formatStateUsing(fn ($state) => round($state / 60, 1).' saat'),
                Tables\Columns\TextColumn::make('labour_cost')
                    ->label('Maya dəyəri')
                    ->state(fn (TimeEntry $r) => $r->labourCost())
                    ->money('AZN'),
                Tables\Columns\TextColumn::make('source')
                    ->label('Mənbə')->badge()
                    ->formatStateUsing(fn (TimeEntrySource $state) => $state->label()),
                Tables\Columns\TextColumn::make('created_at')->label('Tarix')->date('d.m.Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('user_id')->label('İşçi')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([Actions\EditAction::make(), Actions\DeleteAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTimeEntries::route('/'),
            'create' => Pages\CreateTimeEntry::route('/create'),
            'edit' => Pages\EditTimeEntry::route('/{record}/edit'),
        ];
    }
}
