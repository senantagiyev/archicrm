<?php

namespace App\Filament\Resources;

use App\Enums\ApprovalStatus;
use App\Filament\Concerns\OptimisticLock;
use App\Filament\Concerns\ScopesToOwnProjects;
use App\Filament\Resources\BudgetLineResource\Pages;
use App\Models\BudgetLine;
use App\Models\Stage;
use App\Services\Approvals\ApprovalService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mühasibatlıq → Smeta (TZ «Layihə naviqasiyası» §9: maliyyə smetası
 * mühasibatlıqda yerləşir; komplektasiya onun sətirlərinə `budget_line_id`
 * ilə bağlanır, dəyərlər kopyalanmır).
 *
 * Forma, cədvəl və razılaşdırma axını (`ApprovalService`) köhnə
 * `BudgetLinesRelationManager`-dən gəlir — o da bu tərifi işlədir.
 * Hüquq: `BudgetLinePolicy` (Domain::Budget + öz layihəsi).
 */
class BudgetLineResource extends Resource
{
    use ScopesToOwnProjects;

    protected static ?string $model = BudgetLine::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static string|\UnitEnum|null $navigationGroup = 'Maliyyə';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Smeta';

    protected static ?string $modelLabel = 'Smeta sətri';

    protected static ?string $pluralModelLabel = 'Smeta';

    protected static ?string $recordTitleAttribute = 'work_type';

    public static function form(Schema $form): Schema
    {
        return $form->schema(static::formFields(withProject: true))->columns(2);
    }

    /**
     * @return array<int, Forms\Components\Field>
     */
    public static function formFields(bool $withProject): array
    {
        $fields = [];

        if ($withProject) {
            $fields[] = Forms\Components\Select::make('project_id')
                ->label('Layihə')
                ->options(fn () => static::projectOptions())
                ->in(fn () => array_keys(static::projectOptions()))
                ->searchable()
                ->required()
                ->live()
                ->native(false)
                ->columnSpanFull();
        }

        return [
            ...$fields,
            Forms\Components\TextInput::make('work_type')
                ->label('İş növü')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\Select::make('stage_id')
                ->label('Mərhələ')
                ->options(fn (Get $get, $livewire) => static::stageOptions(static::contextProjectId($get, $livewire)))
                ->in(fn (Get $get, $livewire) => array_keys(static::stageOptions(static::contextProjectId($get, $livewire))))
                ->native(false),
            Forms\Components\TextInput::make('room')
                ->label('Otaq')
                ->maxLength(191),
            Forms\Components\TextInput::make('unit')
                ->label('Vahid')
                ->placeholder('m² / ədəd / m')
                ->maxLength(16),
            Forms\Components\TextInput::make('qty')
                ->label('Miqdar / həcm')
                ->numeric()
                ->default(1)
                ->minValue(0)
                ->required(),
            Forms\Components\TextInput::make('work_price')
                ->label('İşin qiyməti')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->suffix('₼'),
            Forms\Components\TextInput::make('material_price')
                ->label('Materialın qiyməti')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->suffix('₼'),
            Forms\Components\Toggle::make('visible_to_client')
                ->label('Sifarişçiyə görünür')
                ->helperText('Yalnız yekun məbləğ göstərilir; iş/material qiymətləri müştəriyə heç vaxt ötürülmür.')
                ->inline(false),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project' => fn ($q) => $q->withTrashed(), 'stage']))
            ->columns(static::tableColumns(withProject: true))
            ->defaultSort('position')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => static::projectOptions())
                    ->searchable(),
                ...static::tableFilters(),
            ])
            ->actions(static::tableActions(inRelationManager: false))
            ->bulkActions(static::tableBulkActions());
    }

    /** @return array<int, Tables\Columns\Column> */
    public static function tableColumns(bool $withProject): array
    {
        return array_values(array_filter([
            $withProject
                ? Tables\Columns\TextColumn::make('project.name')
                    ->label('Layihə')
                    ->description(fn ($record) => $record->project?->trashed() ? 'Layihə silinib' : null)
                    ->searchable()
                    ->sortable()
                : null,
            Tables\Columns\TextColumn::make('work_type')
                ->label('İş növü')
                ->searchable()
                ->wrap(),
            Tables\Columns\TextColumn::make('stage.name')
                ->label('Mərhələ')
                ->placeholder('—')
                ->toggleable(),
            Tables\Columns\TextColumn::make('room')
                ->label('Otaq'),
            Tables\Columns\TextColumn::make('qty')
                ->label('Miqdar')
                ->numeric(2),
            Tables\Columns\TextColumn::make('work_price')
                ->label('İş')
                ->money('AZN'),
            Tables\Columns\TextColumn::make('material_price')
                ->label('Material')
                ->money('AZN'),
            Tables\Columns\TextColumn::make('total')
                ->label('Cəmi')
                ->money('AZN')
                ->weight('bold')
                ->summarize(Tables\Columns\Summarizers\Sum::make()->money('AZN')->label('Cəm')),
            // Komplektasiyada bu sətrə bağlanan pozisiyaların sayı — dəyər
            // smetada bir dəfə yazılır, komplektasiya əlaqə ilə istifadə edir.
            Tables\Columns\TextColumn::make('procurement_items_count')
                ->label('Komplektasiya')
                ->counts('procurementItems')
                ->badge()
                ->color('gray')
                ->toggleable(),
            Tables\Columns\IconColumn::make('visible_to_client')
                ->label('Müştəriyə')
                ->boolean()
                ->trueIcon('heroicon-o-eye')
                ->falseIcon('heroicon-o-eye-slash')
                ->tooltip(fn ($record) => $record->visible_to_client ? 'Müştəri yekun məbləği görür' : 'Müştəriyə görünmür'),
            Tables\Columns\TextColumn::make('approval_status')
                ->label('Razılaşma')
                ->badge()
                ->formatStateUsing(fn (ApprovalStatus $state) => $state->label())
                ->color(fn (ApprovalStatus $state) => $state->color()),
        ]));
    }

    /** @return array<int, Tables\Filters\BaseFilter> */
    public static function tableFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('approval_status')
                ->label('Razılaşma statusu')
                ->options(collect(ApprovalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
        ];
    }

    /** @return array<int, Actions\Action> */
    public static function tableActions(bool $inRelationManager): array
    {
        $edit = Actions\EditAction::make();

        if ($inRelationManager) {
            $edit->using(OptimisticLock::updateUsing());
        }

        return [
            Actions\Action::make('requestApproval')
                ->label('Razılaşdırmaya göndər')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn ($record) => in_array($record->approval_status, [ApprovalStatus::Draft, ApprovalStatus::Rejected], true)
                    && auth()->user()->can('update', $record))
                ->requiresConfirmation()
                ->modalDescription('Sətir sifarişçiyə razılaşdırma üçün göndəriləcək və ona bildiriş gedəcək.')
                ->action(fn ($record, ApprovalService $service) => $service->request($record, auth()->user())),
            $edit,
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->hidden(fn ($record) => $record->approval_status === ApprovalStatus::Approved),
        ];
    }

    /** @return array<int, Actions\BulkAction> */
    public static function tableBulkActions(): array
    {
        return [
            Actions\BulkAction::make('requestApprovalBulk')
                ->label('Razılaşdırmaya göndər')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->deselectRecordsAfterCompletion()
                ->action(function ($records, ApprovalService $service) {
                    foreach ($records as $record) {
                        if (in_array($record->approval_status, [ApprovalStatus::Draft, ApprovalStatus::Rejected], true)
                            && auth()->user()->can('update', $record)) {
                            $service->request($record, auth()->user());
                        }
                    }
                }),
        ];
    }

    /** @return array<int, string> */
    public static function stageOptions(?int $projectId): array
    {
        if ($projectId === null) {
            return [];
        }

        return Stage::query()
            ->where('project_id', $projectId)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['work_type', 'project.name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBudgetLines::route('/'),
            'create' => Pages\CreateBudgetLine::route('/create'),
            'edit' => Pages\EditBudgetLine::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToOwnProjects(parent::getEloquentQuery());
    }
}
