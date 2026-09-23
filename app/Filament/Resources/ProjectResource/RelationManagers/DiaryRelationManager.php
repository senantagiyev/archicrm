<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Rules\SafeUpload;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class DiaryRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'diaryEntries';

    protected static ?string $title = 'Müəllif nəzarəti';

    protected static ?string $modelLabel = 'Qeyd';

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\Textarea::make('body')
                ->label('Qeyd')
                ->required()
                ->rows(4)
                ->maxLength(2000)
                ->helperText('Obyektdən qısa qeyd — müştəri portalda bunu görəcək.')
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('photos')
                ->label('Fotolar')
                ->image()
                ->multiple()
                ->reorderable()
                ->maxFiles(10)
                ->maxSize(10240)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->rules([SafeUpload::image()])
                ->helperText('SVG və icra olunan fayllar qəbul edilmir.')
                // Disk AÇIQ yazılmalıdır. Təyin edilməsə Filament `FILESYSTEM_DISK`
                // defoltunu götürür — Laravel 12-də bu `local`-dır, yəni fayl
                // `storage/app/private`-a düşür. Portal və heyət tərəfi isə hər
                // yerdə `public` diskindən oxuyur: nəticədə paneldən yüklənən
                // hər fayl müştəridə 404/500 verirdi. Yükləyən və oxuyan eyni
                // diski göstərməlidir.
                ->disk('public')
                ->directory('diary-photos')
                ->columnSpanFull(),
            Forms\Components\DateTimePicker::make('published_at')
                ->label('Dərc tarixi')
                ->native(false)
                ->seconds(false)
                ->helperText('Boş qalsa qeyd qaralamadır və müştəriyə görünmür.')
                ->columnSpanFull(),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('body')
                    ->label('Qeyd')
                    ->limit(80)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('photos')
                    ->label('Foto')
                    ->state(fn ($record) => count($record->photos ?? [])),
                Tables\Columns\TextColumn::make('author.name')
                    ->label('Müəllif'),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Dərc')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Qaralama')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaradılıb')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('published_at')
                    ->label('Dərc olunub')
                    ->nullable()
                    ->placeholder('Hamısı')
                    ->trueLabel('Dərc olunub')
                    ->falseLabel('Qaralama'),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Qeyd əlavə et')
                    ->mutateDataUsing(function (array $data): array {
                        // Müəllif həmişə serverdən — formada redaktə olunan sahə deyil.
                        $data['author_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->actions([
                Actions\Action::make('publish')
                    ->label('Dərc et')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    // Dərc müştəriyə yazmaqdır: yalnız Baxış hüququ olan rol
                    // qaralamanı portala çıxara bilməməlidir.
                    ->visible(fn ($record) => $record->published_at === null
                        && auth()->user()?->can('update', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Qeydi müştəriyə dərc et')
                    ->modalDescription('Qeyd və fotolar müştəri portalında görünəcək.')
                    ->action(fn ($record) => $record->update(['published_at' => now()])),
                Actions\Action::make('unpublish')
                    ->label('Dərcdən çıxar')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn ($record) => $record->published_at !== null
                        && auth()->user()?->can('update', $record))
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['published_at' => null])),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->requiresConfirmation(),
            ]);
    }
}
