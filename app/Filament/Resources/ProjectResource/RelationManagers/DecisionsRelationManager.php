<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DecisionSource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class DecisionsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'decisions';

    protected static ?string $title = 'Qərarlar jurnalı';

    protected static ?string $modelLabel = 'Qərar';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Başlıq')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('category')
                ->label('Kateqoriya')
                ->maxLength(191),
            Forms\Components\Select::make('source')
                ->label('Mənbə')
                ->options(collect(DecisionSource::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(DecisionSource::Manual->value)
                ->required()
                ->native(false),
            Forms\Components\Textarea::make('decision')
                ->label('Qərar')
                ->required()
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\DateTimePicker::make('decided_at')
                ->label('Qərar tarixi')
                ->native(false),
            Forms\Components\Toggle::make('client_approved')
                ->label('Sifarişçi təsdiqi'),
            Forms\Components\Textarea::make('notes')
                ->label('Qeydlər')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Başlıq')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Mənbə')
                    ->badge()
                    ->formatStateUsing(fn (DecisionSource $state) => $state->label())
                    ->color(fn (DecisionSource $state) => $state->color()),
                Tables\Columns\TextColumn::make('decided_at')
                    ->label('Tarix')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\IconColumn::make('client_approved')
                    ->label('Sifarişçi təsdiqi')
                    ->boolean(),
            ])
            ->defaultSort('decided_at', 'desc')
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Qərar əlavə et')
                    ->mutateDataUsing(function (array $data): array {
                        $data['made_by_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
