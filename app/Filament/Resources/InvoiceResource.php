<?php

namespace App\Filament\Resources;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'Maliyyə';

    protected static ?string $navigationLabel = 'Hesab-fakturalar';

    protected static ?string $modelLabel = 'Hesab-faktura';

    protected static ?string $pluralModelLabel = 'Hesab-fakturalar';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Əsas məlumatlar')->columns(2)->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        $clientId = Project::query()->whereKey($state)->value('client_id');
                        if ($clientId !== null) {
                            $set('client_id', $clientId);
                        }
                    }),
                Forms\Components\Select::make('client_id')
                    ->label('Müştəri')
                    ->options(fn () => Client::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('number')
                    ->label('Nömrə')
                    ->required()
                    ->maxLength(64),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(InvoiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(InvoiceStatus::Draft->value)
                    ->required()
                    ->native(false),
                Forms\Components\DatePicker::make('issue_date')
                    ->label('Kəsilmə tarixi')
                    ->default(now())
                    ->native(false),
                Forms\Components\DatePicker::make('due_date')
                    ->label('Ödəniş tarixi')
                    ->native(false),
            ]),

            Section::make('Məbləğlər')->columns(3)->schema([
                Forms\Components\TextInput::make('currency')
                    ->label('Valyuta')
                    ->default('AZN')
                    ->required()
                    ->maxLength(3),
                Forms\Components\TextInput::make('subtotal')
                    ->label('Ara cəm')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix(fn (Get $get) => $get('currency')),
                Forms\Components\TextInput::make('tax')
                    ->label('Vergi')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix(fn (Get $get) => $get('currency')),
                Forms\Components\TextInput::make('total')
                    ->label('Ümumi məbləğ')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix(fn (Get $get) => $get('currency')),
                Forms\Components\TextInput::make('paid_amount')
                    ->label('Ödənilmiş məbləğ')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->prefix(fn (Get $get) => $get('currency')),
            ]),

            Section::make()->schema([
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
                Tables\Columns\TextColumn::make('number')
                    ->label('Nömrə')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Müştəri')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Layihə')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label())
                    ->color(fn (InvoiceStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('total')
                    ->label('Ümumi')
                    ->money(fn (Invoice $r) => $r->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Ödənilib')
                    ->money(fn (Invoice $r) => $r->currency)
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Ödəniş tarixi')
                    ->date('d.m.Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Kəsilmə tarixi')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(InvoiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
            ])
            ->actions([
                Actions\EditAction::make(),
                // Issued invoices are never physically deleted — they are cancelled
                // (state machine, TZ §9.4). Only drafts may be hard-deleted.
                Actions\Action::make('cancel')
                    ->label('Ləğv et')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Hesab-faktura ləğv ediləcək. Bu əməliyyat jurnalda qeyd olunur.')
                    ->visible(fn (Invoice $r) => ! in_array($r->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true))
                    ->action(fn (Invoice $r) => $r->update(['status' => InvoiceStatus::Cancelled])),
                Actions\DeleteAction::make()
                    ->visible(fn (Invoice $r) => $r->status === InvoiceStatus::Draft),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
