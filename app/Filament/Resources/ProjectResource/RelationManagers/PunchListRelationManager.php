<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\PunchIssuePriority;
use App\Enums\PunchIssueStatus;
use App\Models\User;
use App\Rules\SafeUpload;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PunchListRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'punchListIssues';

    protected static ?string $title = 'Punch List (qüsurlar)';

    protected static ?string $modelLabel = 'Qüsur';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Başlıq')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('room')
                ->label('Otaq / zona')
                ->maxLength(191),
            Forms\Components\Select::make('priority')
                ->label('Prioritet')
                ->options(collect(PunchIssuePriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()]))
                ->default(PunchIssuePriority::Normal->value)
                ->required()
                ->native(false),
            Forms\Components\Textarea::make('description')
                ->label('Təsvir')
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul şəxs')
                ->options(fn () => User::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->native(false),
            Forms\Components\DatePicker::make('due_date')
                ->label('Son tarix')
                ->native(false),
            Forms\Components\Select::make('status')
                ->label('Status')
                ->options(collect(PunchIssueStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(PunchIssueStatus::Open->value)
                ->required()
                ->native(false),
            Forms\Components\FileUpload::make('photo_url')
                ->label('Foto')
                ->image()
                ->rules([SafeUpload::image()])
                ->directory('punchlist')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Başlıq')
                    ->searchable(),
                Tables\Columns\TextColumn::make('room')
                    ->label('Otaq')
                    ->searchable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->badge()
                    ->formatStateUsing(fn (PunchIssuePriority $state) => $state->label())
                    ->color(fn (PunchIssuePriority $state) => $state->color()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PunchIssueStatus $state) => $state->label())
                    ->color(fn (PunchIssueStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Məsul')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Son tarix')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn ($record) => $record->due_date
                        && $record->due_date->isPast()
                        && ! in_array($record->status, [PunchIssueStatus::Resolved, PunchIssueStatus::Closed], true)
                            ? 'danger' : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PunchIssueStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('priority')
                    ->label('Prioritet')
                    ->options(collect(PunchIssuePriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])),
            ])
            ->headerActions([
                Actions\CreateAction::make()->label('Qüsur əlavə et'),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
