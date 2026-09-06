<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\SpecificationCategory;
use App\Enums\SpecificationStatus;
use App\Services\Design\SpecificationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SpecificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'specificationItems';

    protected static ?string $title = 'Spesifikasiyalar';

    protected static ?string $modelLabel = 'Spesifikasiya';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('product_name')
                ->label('Məhsul')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\Select::make('category')
                ->label('Kateqoriya')
                ->options(collect(SpecificationCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->default(SpecificationCategory::Furniture->value)
                ->required()
                ->native(false),
            Forms\Components\TextInput::make('room')->label('Otaq')->maxLength(191),
            Forms\Components\TextInput::make('brand')->label('Brend')->maxLength(191),
            Forms\Components\TextInput::make('model')->label('Model')->maxLength(191),
            Forms\Components\TextInput::make('supplier')->label('Təchizatçı')->maxLength(191),
            Forms\Components\TextInput::make('client_price')
                ->label('Müştəri qiyməti')->numeric()->default(0)->minValue(0)->suffix('₼'),
            Forms\Components\TextInput::make('supplier_cost')
                ->label('Təchizatçı maya (müştəriyə görünmür)')->numeric()->default(0)->minValue(0)->suffix('₼'),
            Forms\Components\TextInput::make('quantity')->label('Miqdar')->numeric()->default(1)->minValue(0),
            Forms\Components\TextInput::make('unit')->label('Vahid')->maxLength(16),
            Forms\Components\TextInput::make('link')->label('Link')->url()->maxLength(191),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Məhsul')->searchable()
                    ->description(fn ($record) => trim(($record->brand ?? '').' '.($record->model ?? ''))),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kateqoriya')->badge()->color('gray')
                    ->formatStateUsing(fn (SpecificationCategory $c) => $c->label()),
                Tables\Columns\TextColumn::make('room')->label('Otaq')->toggleable(),
                Tables\Columns\TextColumn::make('client_price')->label('Müştəri qiyməti')->money('AZN'),
                Tables\Columns\TextColumn::make('quantity')->label('Miqdar')->numeric(2),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')->badge()
                    ->formatStateUsing(fn (SpecificationStatus $state) => $state->label())
                    ->color(fn (SpecificationStatus $state) => $state->color()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(SpecificationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kateqoriya')
                    ->options(collect(SpecificationCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            ])
            ->headerActions([
                Actions\CreateAction::make()->label('Spesifikasiya əlavə et'),
            ])
            ->actions([
                Actions\Action::make('approve')
                    ->label('Təsdiqlə')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => in_array($record->status, [SpecificationStatus::Draft, SpecificationStatus::Proposed, SpecificationStatus::ClientReview], true))
                    ->action(fn ($record) => $record->forceFill(['status' => SpecificationStatus::Approved])->save()),
                Actions\Action::make('sendToProcurement')
                    ->label('Satınalmaya göndər')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('info')
                    ->visible(fn ($record) => $record->status === SpecificationStatus::Approved && ! $record->procurement_item_id)
                    ->requiresConfirmation()
                    ->modalDescription('Bu spesifikasiya avtomatik olaraq komplektasiya pozisiyası yaradacaq.')
                    ->action(fn ($record, SpecificationService $service) => $service->sendToProcurement($record))
                    ->successNotificationTitle('Komplektasiyaya göndərildi'),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
