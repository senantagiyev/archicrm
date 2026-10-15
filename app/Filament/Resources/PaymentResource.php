<?php

namespace App\Filament\Resources;

use App\Enums\PaymentStatus;
use App\Filament\Concerns\OptimisticLock;
use App\Filament\Concerns\ScopesToOwnProjects;
use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Models\ProcurementItem;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mühasibatlıq → Ödənişlər (TZ «Layihə naviqasiyası» §9: ödənişlər layihə
 * bölmələrindən çıxarılır, layihə ilə əlaqəsi saxlanılaraq mühasibatlıqda qalır).
 *
 * Forma və cədvəl köhnə `PaymentsRelationManager`-dən gəlir — o da indi bu
 * sinfin tərifini işlədir, iki yerdə ayrı-ayrı saxlanmır. Hüquq: `PaymentPolicy`
 * (Domain::Payments + öz layihəsi).
 */
class PaymentResource extends Resource
{
    use ScopesToOwnProjects;

    protected static ?string $model = Payment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Maliyyə';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Ödənişlər';

    protected static ?string $modelLabel = 'Ödəniş';

    protected static ?string $pluralModelLabel = 'Ödənişlər';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $form): Schema
    {
        return $form->schema(static::formFields(withProject: true))->columns(2);
    }

    /**
     * @return array<int, Component|Forms\Components\Field>
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
            Forms\Components\TextInput::make('title')
                ->label('Təyinat')
                ->placeholder('Avans / 2-ci mərhələ ödənişi ...')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('amount')
                ->label('Məbləğ')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->suffix('₼'),
            Forms\Components\DatePicker::make('due_date')
                ->label('Plan tarixi')
                ->native(false),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(collect(PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(PaymentStatus::Pending->value)
                ->required()
                ->live()
                ->native(false),
            Forms\Components\DateTimePicker::make('paid_at')
                ->label('Ödənilmə tarixi (fakt)')
                ->native(false)
                ->visible(fn (Get $get) => $get('status') === PaymentStatus::Paid->value)
                ->default(now()),
            // Komplektasiya ilə AÇIQ əlaqə: ödəniş burada bir dəfə yazılır və
            // pozisiyanın «Faktiki ödəniş» göstəricisi məhz bu qeyddən oxunur.
            Forms\Components\Select::make('procurement_item_id')
                ->label('Komplektasiya pozisiyası')
                ->helperText('Könüllü. Bağlanan «Ödənilib» qeydi pozisiyanın «Faktiki ödəniş» göstəricisinə düşür; plan qiymətini və sifariş dəyərini dəyişmir.')
                ->options(fn (Get $get, $livewire) => static::procurementOptions(static::contextProjectId($get, $livewire)))
                ->in(fn (Get $get, $livewire) => array_keys(static::procurementOptions(static::contextProjectId($get, $livewire))))
                ->searchable()
                ->native(false)
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project' => fn ($q) => $q->withTrashed(), 'procurementItem']))
            ->columns(static::tableColumns(withProject: true))
            ->defaultSort('due_date')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => static::projectOptions())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions(static::tableActions(inRelationManager: false))
            ->bulkActions([]);
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
            Tables\Columns\TextColumn::make('title')
                ->label('Təyinat')
                ->searchable(),
            Tables\Columns\TextColumn::make('amount')
                ->label('Məbləğ')
                ->money('AZN')
                ->summarize(Tables\Columns\Summarizers\Sum::make()->money('AZN')->label('Cəm')),
            Tables\Columns\TextColumn::make('due_date')
                ->label('Plan tarixi')
                ->date('d.m.Y')
                ->sortable(),
            Tables\Columns\TextColumn::make('paid_at')
                ->label('Fakt')
                ->dateTime('d.m.Y H:i'),
            Tables\Columns\TextColumn::make('status')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                ->color(fn (PaymentStatus $state) => $state->color()),
            Tables\Columns\TextColumn::make('procurementItem.name')
                ->label('Komplektasiya pozisiyası')
                ->placeholder('—')
                ->toggleable(),
        ]));
    }

    /** @return array<int, Actions\Action> */
    public static function tableActions(bool $inRelationManager): array
    {
        $edit = Actions\EditAction::make();

        if ($inRelationManager) {
            $edit->using(OptimisticLock::updateUsing());
        }

        return [
            Actions\Action::make('markPaid')
                ->label('Ödənildi')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn ($record) => $record->status !== PaymentStatus::Paid
                    && auth()->user()->can('update', $record))
                ->requiresConfirmation()
                ->action(fn ($record) => $record->update([
                    'status' => PaymentStatus::Paid,
                    'paid_at' => now(),
                ])),
            $edit,
            Actions\DeleteAction::make()->requiresConfirmation(),
        ];
    }

    /** @return array<int, string> */
    public static function procurementOptions(?int $projectId): array
    {
        if ($projectId === null) {
            return [];
        }

        return ProcurementItem::query()
            ->where('project_id', $projectId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'project.name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'edit' => Pages\EditPayment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeToOwnProjects(parent::getEloquentQuery());
    }
}
