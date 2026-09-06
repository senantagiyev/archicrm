<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Satınalma';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Təchizatçılar';

    protected static ?string $modelLabel = 'Təchizatçı';

    protected static ?string $pluralModelLabel = 'Təchizatçılar';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Əsas məlumatlar')->columns(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Ad')
                    ->required()
                    ->maxLength(191),
                Forms\Components\TextInput::make('category')
                    ->label('Kateqoriya')
                    ->maxLength(191),
                Forms\Components\TextInput::make('contact')
                    ->label('Əlaqədar şəxs')
                    ->maxLength(191),
                Forms\Components\Select::make('rating')
                    ->label('Reytinq')
                    ->options([
                        1 => '1',
                        2 => '2',
                        3 => '3',
                        4 => '4',
                        5 => '5',
                    ])
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
                Forms\Components\TextInput::make('website')
                    ->label('Veb sayt')
                    ->url()
                    ->maxLength(191),
                Forms\Components\TextInput::make('address')
                    ->label('Ünvan')
                    ->maxLength(191),
            ]),

            Section::make('Şərtlər')->schema([
                Forms\Components\TextInput::make('payment_terms')
                    ->label('Ödəniş şərtləri')
                    ->maxLength(191),
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
                    ->label('Ad')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Supplier $r) => $r->category),
                Tables\Columns\TextColumn::make('contact')
                    ->label('Əlaqədar şəxs')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('E-poçt')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('rating')
                    ->label('Reytinq')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('purchase_orders_count')
                    ->label('Sifarişlər')
                    ->counts('purchaseOrders')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('rating')
                    ->label('Reytinq')
                    ->options([
                        1 => '1',
                        2 => '2',
                        3 => '3',
                        4 => '4',
                        5 => '5',
                    ]),
            ])
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'category', 'contact', 'phone', 'email'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
