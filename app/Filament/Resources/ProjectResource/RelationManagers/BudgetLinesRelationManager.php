<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ApprovalStatus;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BudgetLinesRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'budgetLines';

    protected static ?string $title = 'Smeta';

    protected static ?string $modelLabel = 'Smeta sətri';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('work_type')
                ->label('İş növü')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
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
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('work_type')
                    ->label('İş növü')
                    ->searchable()
                    ->wrap(),
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
                Tables\Columns\TextColumn::make('approval_status')
                    ->label('Razılaşma')
                    ->badge()
                    ->formatStateUsing(fn (ApprovalStatus $state) => $state->label())
                    ->color(fn (ApprovalStatus $state) => $state->color()),
            ])
            ->defaultSort('position')
            ->filters([
                Tables\Filters\SelectFilter::make('approval_status')
                    ->label('Razılaşma statusu')
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->headerActions([
                Actions\CreateAction::make()->label('Sətir əlavə et'),
            ])
            ->actions([
                Actions\Action::make('requestApproval')
                    ->label('Razılaşdırmaya göndər')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn ($record) => in_array($record->approval_status, [ApprovalStatus::Draft, ApprovalStatus::Rejected], true))
                    ->requiresConfirmation()
                    ->modalDescription('Sətir sifarişçiyə razılaşdırma üçün göndəriləcək və ona bildiriş gedəcək.')
                    ->action(fn ($record, \App\Services\Approvals\ApprovalService $service) => $service->request($record, auth()->user())),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->approval_status === ApprovalStatus::Approved),
            ])
            ->bulkActions([
                Actions\BulkAction::make('requestApprovalBulk')
                    ->label('Razılaşdırmaya göndər')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records, \App\Services\Approvals\ApprovalService $service) {
                        foreach ($records as $record) {
                            if (in_array($record->approval_status, [ApprovalStatus::Draft, ApprovalStatus::Rejected], true)) {
                                $service->request($record, auth()->user());
                            }
                        }
                    }),
            ]);
    }
}
