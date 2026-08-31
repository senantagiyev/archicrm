<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\DocumentType;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'documents';

    protected static ?string $title = 'Sənədlər';

    protected static ?string $modelLabel = 'Sənəd';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Ad')
                ->required()
                ->maxLength(191),
            Forms\Components\Select::make('type')
                ->label('Tip')
                ->options(collect(DocumentType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->default(DocumentType::Other->value)
                ->required()
                ->native(false),
            Forms\Components\FileUpload::make('file_path')
                ->label('Fayl')
                ->directory('documents')
                ->required()
                ->columnSpanFull(),
            Forms\Components\Toggle::make('visible_to_client')
                ->label('Sifarişçiyə görünür')
                ->inline(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Sənəd')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (DocumentType $state) => $state->label()),
                Tables\Columns\IconColumn::make('visible_to_client')
                    ->label('Sifarişçiyə görünür')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yüklənmə tarixi')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Sənəd yüklə')
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
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Sənəd silinəcək. Bu əməliyyat əməliyyat jurnalında qeyd olunur.'),
            ]);
    }
}
