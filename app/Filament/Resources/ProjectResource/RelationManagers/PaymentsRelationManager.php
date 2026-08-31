<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\PaymentStatus;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'payments';

    protected static ?string $title = 'Ödənişlər';

    protected static ?string $modelLabel = 'Ödəniş';

    public function form(Schema $form): Schema
    {
        return $form->schema([
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
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
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
            ])
            ->defaultSort('due_date')
            ->headerActions([
                Actions\CreateAction::make()->label('Ödəniş əlavə et'),
            ])
            ->actions([
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
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
