<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\ContactLogType;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ContactLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'contactLogs';

    protected static ?string $title = 'Ünsiyyət tarixçəsi';

    protected static ?string $modelLabel = 'Qeyd';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label('Tip')
                ->options(collect(ContactLogType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->default(ContactLogType::Note->value)
                ->required()
                ->native(false),
            Forms\Components\DateTimePicker::make('contacted_at')
                ->label('Tarix')
                ->default(now())
                ->required()
                ->native(false),
            Forms\Components\Textarea::make('note')
                ->label('Qeyd')
                ->required()
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->formatStateUsing(fn (ContactLogType $state) => $state->label()),
                Tables\Columns\TextColumn::make('note')
                    ->label('Qeyd')
                    ->wrap()
                    ->limit(120),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Əməkdaş'),
                Tables\Columns\TextColumn::make('contacted_at')
                    ->label('Tarix')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('contacted_at', 'desc')
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Qeyd əlavə et')
                    ->mutateDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ]);
    }
}
