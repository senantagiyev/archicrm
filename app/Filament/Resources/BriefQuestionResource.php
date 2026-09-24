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
 * Üç rol (docs/roomix-brief-parity.md):
 *  - `image_select` / `image_multiselect` / `image_rating` → variant başına BİR
 *    şəkil (kartın özü); `image_rating`-də şəkil opsionaldır, çünki kart şəkilsiz
 *    də rəng zolağı kimi işləyir;
 *  - `supports_inspiration` → variant başına BİR NEÇƏ nümunə şəkli (modal qalereya);
 *  - `std_or_custom` → variantlar `options.items` altındadır, hər sətrin nümunə
 *    qalereyası var (Roomix «Мебельные высоты»).
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
                ->whereIn('type', ['image_select', 'image_multiselect', 'image_rating', 'std_or_custom'])
                ->orWhere('supports_inspiration', true));
    }

    /** Variantları `options.items` altında saxlayan tip. */
    private static function isItemised(?BriefQuestion $record): bool
    {
        return $record?->type === 'std_or_custom';
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

            // «Mebel hündürlükləri» kimi suallarda variantlar `options.items`
            // altındadır — onları ayrıca repeater idarə edir, çünki `options`
            // özü siyahı deyil, konfiqdir.
            //
            // DİQQƏT — repeater-lər `options`-a BİRBAŞA bağlanmır. Əvvəl biri
            // `options`, digəri `options.items` yolunda idi, yəni state yolları
            // üst-üstə düşürdü. Filament gizli bölmənin komponentlərini də
            // hidratlaşdırır, ona görə `std_or_custom` sualında `options`
            // repeater-i bütün `items` siyahısını BİR sətrin içinə yığır,
            // `options.items` repeater-i isə sətirsiz qalırdı — nəticədə sətirlərə
            // şəkil yükləmək ümumiyyətlə mümkün deyildi (fayl diskə düşür, bazaya
            // düşmür). İndi hər repeater-in öz açarı var (`option_rows` /
            // `option_cards`), `options`-a çevirmə isə `EditBriefQuestion`-un
            // fill/save hook-larındadır.
            Section::make('Sətirlərin nümunə şəkilləri')
                ->description('Hər sətir üçün bir neçə nümunə şəkli — müştəri sətrin solundakı kiçik şəklə basaraq onları modalda görür.')
                ->visible(fn (?BriefQuestion $record) => static::isItemised($record))
                ->schema([
                    Forms\Components\Repeater::make('option_rows')
                        ->label('')
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state): ?string => $state['label']['az'] ?? ($state['value'] ?? null))
                        ->schema([
                            Forms\Components\Hidden::make('value'),
                            Forms\Components\Hidden::make('label'),
                            Forms\Components\Hidden::make('standard'),
                            Forms\Components\Hidden::make('unit'),

                            Forms\Components\FileUpload::make('images')
                                ->label('Nümunə şəkilləri')
                                ->multiple()
                                ->image()
                                ->reorderable()
                                ->disk('public')
                                ->directory('brief/inspiration')
                                ->visibility('public')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(2048)
                                ->maxFiles(6)
                                ->rules([SafeUpload::image()])
                                // Hidratlaşmada Filament defolt olaraq hər yolu diskdə
                                // YOXLAYIR və tapmadığını state-dən atır. Bu ekran üçün
                                // təhlükəlidir: diskə müvəqqəti çatmayanda sualı açıb
                                // saxlamaq bütün şəkil istinadlarını silərdi. İstinadı
                                // qorumaq önizləmədəki ölçü/tip məlumatından vacibdir —
                                // seeder-in `mergeOptionImages()` məntiqi də eyni qaydaya
                                // söykənir.
                                ->fetchFileInformation(false)
                                ->helperText('Birinci şəkil sətrin yanındakı kiçik önizləmə kimi görünür.'),
                        ]),
                ]),

            Section::make('Variantların şəkilləri')
                ->description(fn (?BriefQuestion $record) => match (true) {
                    (bool) $record?->supports_inspiration => 'Hər variant üçün bir neçə nümunə şəkli yükləyin — onlar seçimə TƏSİR ETMİR, müştəriyə modalda nümunə kimi göstərilir. Şəkil yüklənməyən variantda ikon ümumiyyətlə görünmür.',
                    $record?->type === 'image_rating' => 'Hər kart üçün bir şəkil — OPSİONALDIR. Şəkil yükləməsəniz kart bankdakı rəng zolağı kimi görünür.',
                    default => 'Hər variant üçün bir şəkil — variant kartının özü budur. Şəkil yüklənməyən kart neytral placeholder kimi görünür.',
                })
                ->visible(fn (?BriefQuestion $record) => ! static::isItemised($record))
                ->schema([
                    Forms\Components\Repeater::make('option_cards')
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
                                ->disk('public')
                                ->directory('brief/options')
                                ->visibility('public')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(2048)
                                ->rules([SafeUpload::image()])
                                // Səbəb yuxarıdakı ilə eynidir: mövcud istinad disk
                                // yoxlaması uğursuz olduğu üçün silinməməlidir.
                                ->fetchFileInformation(false)
                                ->visible(fn (?BriefQuestion $record) => ! $record?->supports_inspiration)
                                ->helperText('4:3 nisbətdə ən yaxşı görünür.'),

                            Forms\Components\FileUpload::make('images')
                                ->label('Nümunə şəkilləri')
                                ->multiple()
                                ->image()
                                ->reorderable()
                                ->disk('public')
                                ->directory('brief/inspiration')
                                ->visibility('public')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(2048)
                                ->maxFiles(6)
                                ->rules([SafeUpload::image()])
                                ->fetchFileInformation(false)
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
                    ->formatStateUsing(fn (BriefQuestion $record) => match (true) {
                        static::isItemised($record) => 'Sətir qalereyası',
                        (bool) $record->supports_inspiration => 'Nümunə qalereyası',
                        default => 'Kart şəkli',
                    }),
                // Şəkilsiz variant sayı — «nə qalıb» sualının cavabı bir baxışda.
                Tables\Columns\TextColumn::make('missing')
                    ->label('Şəkilsiz variant')
                    ->badge()
                    ->color(fn (BriefQuestion $record) => static::missingCount($record) === 0 ? 'success' : 'warning')
                    ->getStateUsing(function (BriefQuestion $record) {
                        $missing = static::missingCount($record);
                        $total = count(static::imageOptions($record));

                        return $missing === 0 ? "hamısı hazır ({$total})" : "{$missing} / {$total}";
                    }),
            ])
            ->defaultSort('brief_section_id')
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Şəkil qəbul edən variantların siyahısı — tipindən asılı olaraq ya
     * `options`, ya da `options.items`.
     *
     * @return array<int, mixed>
     */
    private static function imageOptions(BriefQuestion $question): array
    {
        $options = $question->options ?? [];

        if (! is_array($options)) {
            return [];
        }

        if (static::isItemised($question)) {
            $items = $options['items'] ?? [];

            return is_array($items) ? array_values($items) : [];
        }

        return array_is_list($options) ? $options : [];
    }

    /** Şəkli olmayan variantların sayı — həm rəng, həm mətn üçün. */
    private static function missingCount(BriefQuestion $question): int
    {
        // `std_or_custom` sətirləri də qalereyalıdır — orada da `images` axtarılır.
        $wantsGallery = $question->supports_inspiration || static::isItemised($question);

        return collect(static::imageOptions($question))
            ->reject(fn ($option) => $wantsGallery
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
