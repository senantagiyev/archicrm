<?php

namespace App\Filament\Resources;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource\Pages;
use App\Models\Project;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Tapşırıqlar';

    protected static ?string $modelLabel = 'Tapşırıq';

    protected static ?string $pluralModelLabel = 'Tapşırıqlar';

    protected static ?string $recordTitleAttribute = 'title';

    /** Own-project scoping for non-manager roles. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user->role)) {
            $query->where(fn (Builder $q) => $q
                ->where('assignee_user_id', $user->id)
                ->orWhereHas('project', fn (Builder $p) => $p
                    ->where('manager_user_id', $user->id)
                    ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id))));
        }

        return $query;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make()->columns(2)->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(fn (Set $set) => $set('stage_id', null)),
                Forms\Components\Select::make('stage_id')
                    ->label('Mərhələ')
                    ->options(fn (Get $get) => $get('project_id')
                        ? Stage::where('project_id', $get('project_id'))->orderBy('position')->pluck('name', 'id')
                        : [])
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('title')
                    ->label('Tapşırıq')
                    ->required()
                    ->maxLength(191)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label('Təsvir')
                    ->rows(3)
                    ->autosize()
                    ->columnSpanFull(),
                Forms\Components\Select::make('assignee_user_id')
                    ->label('İcraçı')
                    ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Tapşırıqda icraçı və müddət məcburidir.'),
                Forms\Components\DatePicker::make('deadline')
                    ->label('Son tarix')
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('priority')
                    ->label('Prioritet')
                    ->options(collect(TaskPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()]))
                    ->default(TaskPriority::Normal->value)
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(TaskStatus::Todo->value)
                    ->required()
                    ->native(false),
            ]),

            Section::make('Çek-list')->schema([
                Repeater::make('checklist')
                    ->label('')
                    ->schema([
                        Forms\Components\TextInput::make('text')
                            ->label('Bənd')
                            ->required(),
                        Forms\Components\Checkbox::make('done')
                            ->label('Hazır')
                            ->default(false),
                    ])
                    ->columns(['default' => 1, 'sm' => 4])
                    ->addActionLabel('Bənd əlavə et')
                    ->default([]),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Tapşırıq')
                    ->searchable()
                    ->description(fn (Task $r) => $r->project->name.' — '.$r->stage->name)
                    ->wrap(),
                Tables\Columns\TextColumn::make('assignee.name')
                    ->label('İcraçı')
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->badge()
                    ->formatStateUsing(fn (TaskPriority $state) => $state->label())
                    ->color(fn (TaskPriority $state) => $state->color()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('deadline')
                    ->label('Son tarix')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Task $r) => $r->isOverdue() ? 'danger' : null)
                    ->weight(fn (Task $r) => $r->isOverdue() ? 'bold' : null),
            ])
            ->defaultSort('deadline')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('assignee_user_id')
                    ->label('İcraçı')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Actions\Action::make('markDone')
                    ->label('Hazırdır')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Task $r) => ! $r->status->isFinal())
                    ->action(fn (Task $r) => $r->update(['status' => TaskStatus::Done])),
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'project.name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTasks::route('/'),
            'create' => Pages\CreateTask::route('/create'),
            'edit' => Pages\EditTask::route('/{record}/edit'),
        ];
    }
}
