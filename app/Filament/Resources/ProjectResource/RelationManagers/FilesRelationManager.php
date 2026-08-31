<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\FileCategory;
use App\Rules\SafeUpload;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class FilesRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'files';

    protected static ?string $title = 'Fayllar';

    protected static ?string $modelLabel = 'Fayl';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Ad')
                ->maxLength(191),
            Forms\Components\Select::make('category')
                ->label('Kateqoriya')
                ->options(collect(FileCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]))
                ->default(FileCategory::Other->value)
                ->required()
                ->native(false),
            Forms\Components\FileUpload::make('file_path')
                ->label('Fayl')
                ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'text/csv'])
                ->maxSize(20480)
                ->rules([SafeUpload::document()])
                ->helperText('SVG və icra olunan fayllar qəbul edilmir.')
                ->directory('project-files')
                ->required()
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Fayl')
                    ->state(fn ($record) => $record->title ?: basename($record->file_path))
                    ->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kateqoriya')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (FileCategory $state) => $state->label()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tarix')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kateqoriya')
                    ->options(collect(FileCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Fayl yüklə')
                    ->mutateDataUsing(function (array $data): array {
                        $data['uploaded_by_type'] = 'user';
                        $data['uploaded_by_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Actions\Action::make('download')
                    ->label('Yüklə')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => Storage::disk('public')->url($record->file_path))
                    ->openUrlInNewTab(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
