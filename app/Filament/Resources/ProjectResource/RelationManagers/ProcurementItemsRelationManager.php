<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ApprovalStatus;
use App\Enums\PurchaseStatus;
use App\Exports\ProcurementExport;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;

class ProcurementItemsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'procurementItems';

    protected static ?string $title = 'Komplektasiya';

    protected static ?string $modelLabel = 'Pozisiya';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\FileUpload::make('photo_path')
                ->label('Foto')
                ->image()
                ->imageEditor()
                ->directory('procurement')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('name')
                ->label('Ad')
                ->required()
                ->maxLength(191),
            Forms\Components\TextInput::make('sku')
                ->label('Artikul')
                ->maxLength(64),
            Forms\Components\TextInput::make('category')
                ->label('Kateqoriya')
                ->maxLength(191),
            Forms\Components\TextInput::make('room')
                ->label('Otaq')
                ->maxLength(191),
            Forms\Components\TextInput::make('price')
                ->label('Vahid qiyməti')
                ->numeric()
                ->required()
                ->minValue(0)
                ->suffix('₼'),
            Forms\Components\TextInput::make('qty')
                ->label('Miqdar')
                ->numeric()
                ->default(1)
                ->minValue(0)
                ->required(),
            Forms\Components\TextInput::make('store')
                ->label('Mağaza / təchizatçı')
                ->maxLength(191),
            Forms\Components\TextInput::make('url')
                ->label('Link')
                ->url()
                ->maxLength(191),
            Forms\Components\Select::make('purchase_status')
                ->label('Satınalma statusu')
                ->options(collect(PurchaseStatus::cases())
                    ->reject(fn ($s) => $s === PurchaseStatus::Cancelled)
                    ->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(PurchaseStatus::Planned->value)
                ->native(false),
            Forms\Components\Toggle::make('paid')
                ->label('Ödənilib')
                ->inline(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Foto')
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad')
                    ->searchable()
                    ->description(fn ($record) => $record->sku)
                    ->wrap(),
                Tables\Columns\TextColumn::make('room')
                    ->label('Otaq')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Qiymət')
                    ->money('AZN'),
                Tables\Columns\TextColumn::make('qty')
                    ->label('Miqdar')
                    ->numeric(2),
                Tables\Columns\TextColumn::make('total')
                    ->label('Cəmi')
                    ->money('AZN')
                    ->weight('bold')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('AZN')->label('Cəm')),
                Tables\Columns\TextColumn::make('approval_status')
                    ->label('Razılaşma')
                    ->badge()
                    ->formatStateUsing(fn (ApprovalStatus $state) => $state->label())
                    ->color(fn (ApprovalStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('purchase_status')
                    ->label('Satınalma')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseStatus $state) => $state->label())
                    ->color(fn (PurchaseStatus $state) => $state->color()),
                Tables\Columns\IconColumn::make('paid')
                    ->label('Ödənilib')
                    ->boolean(),
                Tables\Columns\TextColumn::make('store')
                    ->label('Mağaza')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('approval_status')
                    ->label('Razılaşma')
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('room')
                    ->label('Otaq')
                    ->options(fn () => $this->getOwnerRecord()->procurementItems()
                        ->whereNotNull('room')->distinct()->pluck('room', 'room')->all()),
            ])
            ->headerActions([
                Actions\Action::make('exportExcel')
                    ->label('Excel-ə ixrac')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => Excel::download(
                        new ProcurementExport($this->getOwnerRecord()),
                        'komplektasiya-'.$this->getOwnerRecord()->id.'.xlsx',
                    )),
                Actions\CreateAction::make()->label('Pozisiya əlavə et'),
            ])
            ->actions([
                Actions\Action::make('requestApproval')
                    ->label('Razılaşdırmaya göndər')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn ($record) => in_array($record->approval_status, [ApprovalStatus::Draft, ApprovalStatus::Rejected], true))
                    ->requiresConfirmation()
                    ->modalDescription('Pozisiya sifarişçiyə razılaşdırma üçün göndəriləcək və ona bildiriş gedəcək.')
                    ->action(fn ($record, \App\Services\Approvals\ApprovalService $service) => $service->request($record, auth()->user())),
                Actions\Action::make('cancelItem')
                    ->label('Ləğv et')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->isDeletionLocked() && $record->purchase_status !== PurchaseStatus::Cancelled)
                    ->form([
                        Forms\Components\Textarea::make('cancel_comment')
                            ->label('Ləğv səbəbi (məcburi)')
                            ->required()
                            ->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Pozisiyanı ləğv et')
                    ->modalDescription('Razılaşdırılmış və ödənilmiş pozisiya silinə bilməz — yalnız şərhlə ləğv edilə bilər (TZ §5.10).')
                    ->action(fn ($record, array $data) => $record->update([
                        'purchase_status' => PurchaseStatus::Cancelled,
                        'cancel_comment' => $data['cancel_comment'],
                    ])),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->isDeletionLocked()),
            ]);
    }
}
