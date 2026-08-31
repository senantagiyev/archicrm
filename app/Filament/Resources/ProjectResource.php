<?php

namespace App\Filament\Resources;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\StaffRole;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers;
use App\Filament\Resources\ProjectResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\StagesRelationManager;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Layihələr';

    protected static ?string $modelLabel = 'Layihə';

    protected static ?string $pluralModelLabel = 'Layihələr';

    protected static ?string $recordTitleAttribute = 'name';

    /** TZ §5.4 "Öz layihələri": non-owner roles see only their projects. */
    public static function getEloquentQuery(): Builder
    {
        // Table closures read client/manager — eager load against the N+1 guard.
        $query = parent::getEloquentQuery()->with(['client', 'manager']);
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user->role)) {
            $query->where(fn (Builder $q) => $q
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id)));
        }

        return $query;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Layihə')->columns(2)->schema([
                Forms\Components\Select::make('client_id')
                    ->label('Müştəri')
                    ->options(fn () => Client::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Müştəri göstərilmədən layihə yaradıla bilməz.'),
                Forms\Components\TextInput::make('name')
                    ->label('Layihənin adı')
                    ->required()
                    ->maxLength(191),
                Forms\Components\Select::make('type')
                    ->label('Obyekt tipi')
                    ->options(collect(ProjectType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('address')
                    ->label('Ünvan / lokasiya')
                    ->maxLength(191),
                Forms\Components\TextInput::make('area')
                    ->label('Sahə (m²)')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('m²'),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(ProjectStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(ProjectStatus::Active->value)
                    ->required()
                    ->native(false),
            ]),

            Section::make('İdarəetmə və büdcə')->columns(2)->schema([
                Forms\Components\Select::make('manager_user_id')
                    ->label('Məsul menecer')
                    ->options(fn () => User::query()
                        ->where('is_active', true)
                        ->whereIn('role', [StaffRole::Owner->value, StaffRole::ProjectManager->value, StaffRole::Designer->value])
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false),
                Forms\Components\DatePicker::make('deadline')
                    ->label('Təhvil müddəti')
                    ->native(false),
                Forms\Components\TextInput::make('budget_plan')
                    ->label('Büdcə (plan)')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('₼'),
                Forms\Components\TextInput::make('budget_fact')
                    ->label('Büdcə (fakt)')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('₼'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Layihə')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Project $r) => $r->client?->name),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (ProjectType $state) => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ProjectStatus $state) => $state->label())
                    ->color(fn (ProjectStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('readiness')
                    ->label('Hazırlıq')
                    ->formatStateUsing(fn ($state) => $state.'%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('deadline')
                    ->label('Təhvil')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Project $r) => $r->deadline?->isPast() && $r->status !== ProjectStatus::Done ? 'danger' : null),
                Tables\Columns\TextColumn::make('manager.name')
                    ->label('Menecer')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ProjectStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip')
                    ->options(collect(ProjectType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
                Tables\Filters\SelectFilter::make('manager_user_id')
                    ->label('Menecer')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            StagesRelationManager::class,
            RelationManagers\BriefAnswersRelationManager::class,
            RelationManagers\BudgetLinesRelationManager::class,
            RelationManagers\ProcurementItemsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\FilesRelationManager::class,
            MembersRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'address', 'client.name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
