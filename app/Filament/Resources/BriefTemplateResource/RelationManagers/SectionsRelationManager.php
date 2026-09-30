<?php

namespace App\Filament\Resources\BriefTemplateResource\RelationManagers;

use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Services\Brief\BriefBuilderService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Brifin bölmələri — müştəri portalda hər bölməni ayrıca kart kimi görür.
 *
 * Sistem şablonunun nüsxəsində bank bölmələri (o cümlədən otaq bölmələri)
 * gəlir; fərdi brif bir bölmə ilə başlayır, istəsəniz yenisini əlavə edirsiniz.
 * Yazma `BriefBuilderService`-dədir: açar unikallığı, «ən azı bir aktiv
 * bölmə» və «sualı olan bölmə silinmir» qaydaları orada yoxlanılır.
 */
class SectionsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'sections';

    protected static ?string $title = 'Bölmələr';

    protected static ?string $modelLabel = 'Bölmə';

    protected static ?string $pluralModelLabel = 'Bölmələr';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof BriefTemplate
            && $ownerRecord->isCustom()
            && (auth()->user()?->can('update', $ownerRecord) ?? false);
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Bölmənin adı')
                ->required()
                ->maxLength(191)
                ->columnSpanFull(),
            Forms\Components\Textarea::make('intro')
                ->label('Giriş mətni (istəyə görə)')
                ->rows(3)
                ->maxLength(2000)
                ->helperText('Müştəri bölməni açanda başlığın altında görür.')
                ->columnSpanFull(),
            Forms\Components\TextInput::make('position')
                ->label('Sıra')
                ->numeric()
                ->minValue(0)
                ->default(fn () => (int) BriefSection::where('brief_template_id', $this->getOwnerRecord()->id)->max('position') + 1),
            Forms\Components\Toggle::make('active')
                ->label('Aktiv')
                ->helperText('Deaktiv bölmə müştəriyə görünmür; cavablar saxlanılır.')
                ->default(true)
                ->visibleOn('edit'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->defaultSort('position')
            ->columns([
                Tables\Columns\TextColumn::make('position')
                    ->label('#')
                    ->width('3rem')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Bölmə')
                    ->formatStateUsing(fn ($state) => is_array($state) ? ($state['az'] ?? reset($state)) : $state)
                    ->description(fn (BriefSection $record) => $record->isRoomSection() ? 'Otaq bölməsi — hər əlavə olunan otaq üçün ayrıca doldurulur' : null)
                    ->weight('semibold')
                    ->wrap(),
                Tables\Columns\TextColumn::make('questions_count')
                    ->label('Sual')
                    ->counts('questions')
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Aktiv')
                    ->boolean(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Bölmə əlavə et')
                    ->icon('heroicon-o-plus')
                    ->modalWidth(Width::Full)
                    ->using(fn (array $data) => $this->guard(fn () => app(BriefBuilderService::class)->addSection($this->getOwnerRecord(), $data)))
                    ->successNotificationTitle('Bölmə əlavə edildi'),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->modalWidth(Width::Full)
                    ->mutateRecordDataUsing(function (array $data, BriefSection $record): array {
                        $data['name'] = $record->getTranslation('name', 'az');
                        $data['intro'] = $record->getTranslation('intro', 'az') ?: null;

                        return $data;
                    })
                    ->using(fn (BriefSection $record, array $data) => $this->guard(fn () => app(BriefBuilderService::class)->updateSection($record, $data))),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Yalnız boş bölmə silinir. Sualları olan bölməni deaktiv edin — cavablar itməsin.')
                    ->visible(fn (BriefSection $record) => ! $record->isRoomSection())
                    ->using(fn (BriefSection $record) => $this->guard(fn () => app(BriefBuilderService::class)->deleteSection($record)) ?? true),
            ]);
    }

    /**
     * Servis qayda pozuntusunu `InvalidArgumentException` ilə bildirir —
     * admin ağ xəta səhifəsi yox, izahlı bildiriş görsün və modal dayansın.
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new \Filament\Support\Exceptions\Halt;
        }
    }
}
