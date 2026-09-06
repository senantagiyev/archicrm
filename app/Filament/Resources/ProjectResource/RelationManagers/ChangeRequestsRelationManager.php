<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ChangeRequestStatus;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class ChangeRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'changeRequests';

    protected static ?string $title = 'Dəyişiklik sorğuları';

    protected static ?string $modelLabel = 'Dəyişiklik sorğusu';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Başlıq')->required()->maxLength(191)->columnSpanFull(),
            Forms\Components\Textarea::make('description')->label('Təsvir')->rows(2)->columnSpanFull(),
            Forms\Components\Textarea::make('reason')->label('Səbəb')->rows(2)->columnSpanFull(),
            Forms\Components\Select::make('requested_by')
                ->label('Kim tələb edir')
                ->options(['team' => 'Komanda', 'client' => 'Müştəri'])
                ->default('team')->required()->native(false),
            Forms\Components\Select::make('responsible_user_id')
                ->label('Məsul')
                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()->native(false),
            Forms\Components\Section::make('Təsir qiymətləndirməsi (Impact Assessment)')->columns(3)->schema([
                Forms\Components\TextInput::make('schedule_impact_days')
                    ->label('Müddət təsiri (gün)')->numeric()->default(0),
                Forms\Components\TextInput::make('cost_impact')
                    ->label('Dəyər təsiri')->numeric()->default(0)->suffix('₼'),
                Forms\Components\TextInput::make('estimated_hours')
                    ->label('Əlavə saat')->numeric()->default(0),
            ]),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Nömrə')->searchable(),
                Tables\Columns\TextColumn::make('title')->label('Başlıq')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('requested_by')
                    ->label('Tələb edən')
                    ->formatStateUsing(fn ($state) => $state === 'client' ? 'Müştəri' : 'Komanda'),
                Tables\Columns\TextColumn::make('schedule_impact_days')->label('+Gün'),
                Tables\Columns\TextColumn::make('cost_impact')->label('+Dəyər')->money('AZN'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')->badge()
                    ->formatStateUsing(fn (ChangeRequestStatus $s) => $s->label())
                    ->color(fn (ChangeRequestStatus $s) => $s->color()),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ChangeRequestStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Dəyişiklik sorğusu yarat')
                    ->mutateDataUsing(function (array $data): array {
                        $data['requested_by_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Actions\Action::make('advance')
                    ->label('Növbəti mərhələ')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->visible(fn ($record) => ChangeRequestStatus::allowed()[$record->status->value] !== [])
                    ->form([
                        Forms\Components\Select::make('to')
                            ->label('Yeni status')
                            ->options(fn ($record) => collect(ChangeRequestStatus::allowed()[$record->status->value] ?? [])
                                ->mapWithKeys(fn ($v) => [$v => ChangeRequestStatus::from($v)->label()]))
                            ->required()->native(false),
                    ])
                    ->action(function ($record, array $data) {
                        $record->transitionTo(ChangeRequestStatus::from($data['to']));
                    })
                    ->successNotificationTitle('Status yeniləndi'),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
