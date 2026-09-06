<?php

namespace App\Filament\Resources;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Maliyyə';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Xərclər';

    protected static ?string $modelLabel = 'Xərc';

    protected static ?string $pluralModelLabel = 'Xərclər';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Xərc məlumatları')->columns(2)->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Layihəyə bağlı deyil')
                    ->native(false),
                Forms\Components\Select::make('category')
                    ->label('Kateqoriya')
                    ->options(collect(ExpenseCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                    ->default(ExpenseCategory::Other->value)
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('vendor')
                    ->label('Təchizatçı / qarşı tərəf')
                    ->maxLength(191),
                Forms\Components\DatePicker::make('date')
                    ->label('Tarix')
                    ->default(now())
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('amount')
                    ->label('Məbləğ')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->suffix('₼'),
                Forms\Components\Select::make('currency')
                    ->label('Valyuta')
                    ->options([
                        'AZN' => 'AZN',
                        'USD' => 'USD',
                        'EUR' => 'EUR',
                    ])
                    ->default('AZN')
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(ExpenseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(ExpenseStatus::Pending->value)
                    ->required()
                    ->live()
                    ->native(false),
                Forms\Components\Select::make('approved_by_user_id')
                    ->label('Təsdiqləyən')
                    ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get) => in_array($get('status'), [
                        ExpenseStatus::Approved->value,
                        ExpenseStatus::Paid->value,
                    ], true)),
            ]),

            Section::make()->schema([
                Forms\Components\Textarea::make('description')
                    ->label('Təsvir')
                    ->rows(3)
                    ->autosize()
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Tarix')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kateqoriya')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseCategory $state) => $state->label()),
                Tables\Columns\TextColumn::make('vendor')
                    ->label('Təchizatçı')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Layihə')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Məbləğ')
                    ->money('AZN')
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('AZN')->label('Cəm')),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseStatus $state) => $state->label())
                    ->color(fn (ExpenseStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Yaradan')
                    ->toggleable()
                    ->toggledHiddenByDefault(true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kateqoriya')
                    ->options(collect(ExpenseCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ExpenseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
