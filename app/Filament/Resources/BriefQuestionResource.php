<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BriefQuestionResource\Pages;
use App\Models\BriefQuestion;
use App\Rules\SafeUpload;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brif variantlarının ŞƏKİLLƏRİ.
 *
 * Sualların özü git-dəki bankdan (`database/seeders/brief/bank.php`) gəlir və
 * burada redaktə olunmur — mətn, tip və şərti məntiq kod nəzarətindədir.
 * Bu ekranın yeganə işi variantlara şəkil bağlamaqdır, çünki şəkillər
 * kontentdir: onları dizayner/kontent menecer yükləyir, developer yox.
 *
 * İki rol (docs/roomix-brief-ux-analiz.md):
 *  - `image_select` / `image_multiselect` → variant başına BİR şəkil (kartın özü);
 *  - `supports_inspiration` → variant başına BİR NEÇƏ nümunə şəkli (modal qalereya).
 *
 * Seeder yenidən işlədiləndə bu şəkillər İTMİR: `BriefQuestionBankSeeder`
 * `mergeOptionImages()` ilə onları value üzrə köçürür.
 */
class BriefQuestionResource extends Resource
{
    protected static ?string $model = BriefQuestion::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Brif şəkilləri';

    protected static ?string $modelLabel = 'Brif sualı';

    protected static ?string $pluralModelLabel = 'Brif şəkilləri';

    protected static ?string $recordTitleAttribute = 'key';

    /**
     * Yalnız şəkil qəbul edən suallar. Qalan 100+ sualı burada göstərmək
     * siyahını istifadəsiz edərdi — onların şəkli olmur.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(fn (Builder $query) => $query
                ->whereIn('type', ['image_select', 'image_multiselect'])
                ->orWhere('supports_inspiration', true));
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Sual')
                ->description('Sual mətni və tipi bankdan gəlir — burada dəyişdirilmir.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('key')->label('Açar')->disabled(),
                    Forms\Components\TextInput::make('type')->label('Tip')->disabled(),
                    Forms\Components\TextInput::make('group')->label('Qrup')->disabled(),
                ]),

            Section::make('Variantların şəkilləri')
                ->description(fn (?BriefQuestion $record) => $record?->supports_inspiration
                    ? 'Hər variant üçün bir neçə nümunə şəkli yükləyin — onlar seçimə TƏSİR ETMİR, müştəriyə modalda nümunə kimi göstərilir. Şəkil yüklənməyən variantda ikon ümumiyyətlə görünmür.'
                    : 'Hər variant üçün bir şəkil — variant kartının özü budur. Şəkil yüklənməyən kart neytral placeholder kimi görünür.')
                ->schema([
                    Forms\Components\Repeater::make('options')
                        ->label('')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => $state['label']['az'] ?? ($state['value'] ?? null))
                        ->schema([
                            // Variantın özü bankın nəzarətindədir; formada yalnız
                            // oxunur ki, şəkil yanlış varianta bağlanmasın.
                            Forms\Components\Hidden::make('value'),
                            Forms\Components\Hidden::make('label'),

                            Forms\Components\FileUpload::make('image_url')
                                ->label('Kart şəkli')
                                ->image()
                                ->imageEditor()
                                ->directory('brief/options')
                                ->visibility('public')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(2048)
                                ->rules([SafeUpload::image()])
                                ->visible(fn (?BriefQuestion $record) => ! $record?->supports_inspiration)
                                ->helperText('4:3 nisbətdə ən yaxşı görünür.'),

                            Forms\Components\FileUpload::make('images')
                                ->label('Nümunə şəkilləri')
                                ->multiple()
                                ->image()
                                ->reorderable()
                                ->directory('brief/inspiration')
                                ->visibility('public')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(2048)
                                ->maxFiles(6)
                                ->rules([SafeUpload::image()])
                                ->visible(fn (?BriefQuestion $record) => (bool) $record?->supports_inspiration)
                                ->helperText('3-4 şəkil kifayətdir.'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('section.name')
                    ->label('Bölmə')
                    ->formatStateUsing(fn ($state) => is_array($state) ? ($state['az'] ?? reset($state)) : $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->label('Sual')
                    ->formatStateUsing(fn ($state) => is_array($state) ? ($state['az'] ?? reset($state)) : $state)
                    ->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (BriefQuestion $record) => $record->supports_inspiration
                        ? 'Nümunə qalereyası'
                        : 'Kart şəkli'),
                // Şəkilsiz variant sayı — «nə qalıb» sualının cavabı bir baxışda.
                Tables\Columns\TextColumn::make('missing')
                    ->label('Şəkilsiz variant')
                    ->badge()
                    ->color(fn (BriefQuestion $record) => static::missingCount($record) === 0 ? 'success' : 'warning')
                    ->getStateUsing(function (BriefQuestion $record) {
                        $missing = static::missingCount($record);
                        $total = count($record->options ?? []);

                        return $missing === 0 ? "hamısı hazır ({$total})" : "{$missing} / {$total}";
                    }),
            ])
            ->defaultSort('brief_section_id')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** Şəkli olmayan variantların sayı — həm rəng, həm mətn üçün. */
    private static function missingCount(BriefQuestion $question): int
    {
        $options = $question->options ?? [];

        if (! is_array($options) || ! array_is_list($options)) {
            return 0;
        }

        return collect($options)
            ->reject(fn ($option) => $question->supports_inspiration
                ? filled($option['images'] ?? null)
                : filled($option['image_url'] ?? null))
            ->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBriefQuestions::route('/'),
            'edit' => Pages\EditBriefQuestion::route('/{record}/edit'),
        ];
    }
}
