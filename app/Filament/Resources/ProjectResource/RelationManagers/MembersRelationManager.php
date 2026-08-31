<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ProjectRole;
use App\Enums\StaffRole;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'members';

    protected static ?string $title = 'Komanda';

    protected static ?string $modelLabel = 'Üzv';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad, soyad')
                    ->description(fn ($record) => $record->email),
                Tables\Columns\TextColumn::make('role')
                    ->label('Sistem rolu')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (StaffRole $state) => $state->label()),
                Tables\Columns\TextColumn::make('project_role')
                    ->label('Layihədəki rol')
                    ->badge()
                    ->state(fn ($record) => ProjectRole::tryFrom($record->pivot->project_role)?->label() ?? $record->pivot->project_role),
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->label('Üzv əlavə et')
                    ->preloadRecordSelect()
                    ->form(fn (Actions\AttachAction $action) => [
                        $action->getRecordSelect()->label('Əməkdaş'),
                        Forms\Components\Select::make('project_role')
                            ->label('Layihədəki rol')
                            ->options(collect(ProjectRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()]))
                            ->required()
                            ->native(false),
                    ]),
            ])
            ->actions([
                Actions\DetachAction::make()->label('Çıxar'),
            ]);
    }
}
