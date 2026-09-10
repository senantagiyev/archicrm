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
                        Forms\Components\TextInput::make('qty')
                            ->label('Say')
                            ->numeric()
                            ->default(1),
                        Forms\Components\TextInput::make('price')
                            ->label('Qiymət')
                            ->numeric()
                            ->prefix('AZN'),
                    ])
                    ->columns(['default' => 1, 'sm' => 3])
                    ->addActionLabel('Pozisiya əlavə et')
                    ->default([]),
            ])->collapsible(),

            Section::make('Məbləğlər')->columns(3)->schema([
                Forms\Components\TextInput::make('subtotal')
                    ->label('Ara cəm')
                    ->numeric()
                    ->prefix('AZN')
                    ->default(0),
                Forms\Components\TextInput::make('tax')
                    ->label('Vergi')
                    ->numeric()
                    ->prefix('AZN')
                    ->default(0),
                Forms\Components\TextInput::make('total')
                    ->label('Yekun')
                    ->numeric()
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
            $query->whereHas('project', fn (Builder $project) => $project
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $member) => $member->whereKey($user->id)));
        }

        return $query;
    }
}
