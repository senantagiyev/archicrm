<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DeliverableStatus;
use App\Enums\DeliverableType;
use App\Enums\DeliverableVersionStatus;
use App\Models\User;
use App\Rules\SafeUpload;
use App\Services\Approvals\ApprovalService;
use App\Services\Design\DeliverableService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class DeliverablesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliverables';

    protected static ?string $title = 'Dizayn (Deliverables)';

    protected static ?string $modelLabel = 'Deliverable';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Ad')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\Select::make('type')
                ->label('Tip')
                ->options(collect(DeliverableType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->default(DeliverableType::Concept->value)
                ->required()
                ->native(false),
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul')
                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->native(false),
            Forms\Components\Textarea::make('description')
                ->label('Təsvir')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Deliverable')
                    ->searchable()
                    ->description(fn ($record) => $record->type?->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (DeliverableStatus $s) => $s->label())
                    ->color(fn (DeliverableStatus $s) => $s->color()),
                Tables\Columns\TextColumn::make('currentVersion.version_number')
                    ->label('Cari versiya')
                    ->formatStateUsing(fn ($state) => $state ? 'v'.$state : '—'),
                Tables\Columns\TextColumn::make('currentVersion.status')
                    ->label('Versiya statusu')
                    ->badge()
                    ->formatStateUsing(fn (?DeliverableVersionStatus $s) => $s?->label() ?? '—')
                    ->color(fn (?DeliverableVersionStatus $s) => $s?->color() ?? 'gray'),
                Tables\Columns\TextColumn::make('versions_count')
                    ->label('Versiyalar')
                    ->counts('versions'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Actions\CreateAction::make()->label('Deliverable yarat'),
            ])
            ->actions([
                Actions\Action::make('newVersion')
                    ->label('Yeni versiya')
                    ->icon('heroicon-o-document-plus')
                    ->form([
                        Forms\Components\FileUpload::make('file_path')
                            ->label('Fayl')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(20480)
                            ->rules([SafeUpload::document()])
                            ->directory('deliverables'),
                        Forms\Components\Textarea::make('change_summary')
                            ->label('Dəyişiklik qeydi')
                            ->rows(2),
                    ])
                    ->action(fn ($record, array $data, DeliverableService $service) => $service->createVersion(
                        $record,
                        $data['file_path'] ?? null,
                        auth()->user(),
                        $data['change_summary'] ?? null,
                    ))
                    ->successNotificationTitle('Yeni versiya yaradıldı'),
                Actions\Action::make('sendForApproval')
                    ->label('Razılaşdırmaya göndər')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->visible(fn ($record) => $record->current_version_id
                        && $record->currentVersion?->status !== DeliverableVersionStatus::Approved
                        && $record->currentVersion?->status !== DeliverableVersionStatus::Locked)
                    ->requiresConfirmation()
                    ->modalDescription('Cari versiya müştəriyə razılaşdırma üçün göndəriləcək.')
                    ->action(function ($record, ApprovalService $service) {
                        $record->update(['client_visible' => true]);
                        $service->request($record, auth()->user());
                    })
                    ->successNotificationTitle('Razılaşdırmaya göndərildi'),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status === DeliverableStatus::Approved),
            ]);
    }
}
