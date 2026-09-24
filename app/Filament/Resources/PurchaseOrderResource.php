<?php

namespace App\Filament\Resources;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Satınalma';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Satınalma sifarişləri';

    protected static ?string $modelLabel = 'Satınalma sifarişi';

    protected static ?string $pluralModelLabel = 'Satınalma sifarişləri';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Əsas məlumatlar')->columns(2)->schema([
                Forms\Components\Select::make('supplier_id')
                    ->label('Təchizatçı')
                    ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->native(false),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(PurchaseOrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(PurchaseOrderStatus::Draft->value)
                    ->required()
                    ->native(false),
                Forms\Components\DatePicker::make('order_date')
                    ->label('Sifariş tarixi')
                    ->default(now())
                    ->native(false),
                Forms\Components\DatePicker::make('expected_delivery')
                    ->label('Gözlənilən çatdırılma')
                    ->native(false),
                Forms\Components\TextInput::make('payment_terms')
                    ->label('Ödəniş şərtləri')
                    ->maxLength(191),
            ]),

            Section::make('Pozisiyalar')->schema([
                Repeater::make('items')
                    ->label('')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Ad')
                            ->required(),
                        // Pozisiya məbləğləri başlıq cəmini YENİDƏN QURUR
                        // (`PurchaseOrder::booted()`), ona görə mənfi say və ya
                        // qiymət ara cəmi aşağı çəkir və maya dəyərini azaldır.
                        Forms\Components\TextInput::make('qty')
                            ->label('Say')
                            ->numeric()
                            ->minValue(0)
                            ->default(1),
                        Forms\Components\TextInput::make('price')
                            ->label('Qiymət')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('AZN'),
                    ])
                    ->columns(['default' => 1, 'sm' => 3])
                    ->addActionLabel('Pozisiya əlavə et')
                    ->default([]),
            ])->collapsible(),

            // MƏNFİ MƏBLƏĞ QADAĞASI: satınalma sifarişi rentabellik hesabatında
            // BİRBAŞA maya dəyəridir (`ProfitabilityService::forProject()` →
            // `purchases`). Mənfi ara cəm və ya vergi maya dəyərini azaldır və
            // marjanı süni şişirdir — 5 000-lik sifarişin yanına −4 000 yazılsa
            // xərc 1 000 görünür. `InvoiceResource` (`minValue(0)`) və
            // `ExpenseResource` (`minValue(0.01)`) ilə eyni üslub.
            Section::make('Məbləğlər')->columns(3)->schema([
                Forms\Components\TextInput::make('subtotal')
                    ->label('Ara cəm')
                    ->helperText('Pozisiya əlavə edilibsə, ara cəm onlardan hesablanır.')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('AZN')
                    ->default(0),
                Forms\Components\TextInput::make('tax')
                    ->label('Vergi')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('AZN')
                    ->default(0),
                // `total` OXUNAQLI: modeldəki `saving()` hook-u onu hər halda
                // `subtotal + tax` kimi yenidən yazır, ona görə sərbəst input
                // yalnız operatoru aldadırdı — yazdığı rəqəm səssizcə itirdi.
                // `InvoiceResource`-dakı `total` ilə eyni həll.
                Forms\Components\TextInput::make('total')
                    ->label('Yekun')
                    ->helperText('Ara cəm + vergi — avtomatik hesablanır.')
                    ->numeric()
                    ->minValue(0)
                    ->disabled()
                    ->dehydrated(false)
                    ->prefix('AZN')
                    ->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('№')
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Təchizatçı')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Layihə')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderStatus $state) => $state->label())
                    ->color(fn (PurchaseOrderStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('total')
                    ->label('Yekun')
                    ->money('AZN')
                    ->sortable(),
                Tables\Columns\TextColumn::make('order_date')
                    ->label('Sifariş tarixi')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('expected_delivery')
                    ->label('Gözlənilən çatdırılma')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PurchaseOrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Təchizatçı')
                    ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['supplier.name', 'project.name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user)) {
            // Layihəsiz (lump-sum) sifariş studiya səviyyəli qeyddir —
            // yoxlanılacaq üzvlük yoxdur, ona görə `ScopesProjectDomain` onu
            // qəsdən buraxır (`$projectId === null` → icazə). `whereHas` isə
            // NULL layihəni kənarlaşdırdığı üçün siyahı policy ilə ziddiyyətə
            // düşürdü: Komplektləşdirici üçün `can('view')`/`can('update')`
            // true, amma qeyd nə siyahıda görünürdü, nə birbaşa URL-dən açılırdı.
            // Nested where şərtdir — `orWhereNull` başqa filtrlərlə yan-yana
            // qalsa scope-u tamamilə açardı.
            $query->where(fn (Builder $scoped) => $scoped
                ->whereNull('project_id')
                ->orWhereHas('project', fn (Builder $project) => $project
                    ->where('manager_user_id', $user->id)
                    ->orWhereHas('members', fn (Builder $member) => $member->whereKey($user->id))));
        }

        return $query;
    }
}
