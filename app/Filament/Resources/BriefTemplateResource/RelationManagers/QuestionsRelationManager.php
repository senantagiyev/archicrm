<?php

namespace App\Filament\Resources\BriefTemplateResource\RelationManagers;

use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Rules\SafeUpload;
use App\Services\Brief\BriefBuilderService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Fərdi brifin sualları — konstruktorun özü.
 *
 * Əlaqə `BriefTemplate::questions()` (bölmə üzərindən HasManyThrough) ilə
 * OXUNUR; yaratma isə bölməyə bağlı olduğu üçün `BriefBuilderService`-ə
 * verilir — o, açarı, bölməni və variantların formasını özü qurur.
 *
 * Sıralama sürüklə-burax deyil, «Sıra» rəqəmi ilədir: Filament-in reorder
 * yeniləməsi HasManyThrough sorğusunun join-i üzərində `whereIn('id')` işlədir
 * və iki cədvəldə də `id` olduğu üçün etibarsızdır.
 */
class QuestionsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'questions';

    protected static ?string $title = 'Suallar';

    protected static ?string $modelLabel = 'Sual';

    protected static ?string $pluralModelLabel = 'Suallar';

    /** Sistem şablonunun sualları paneldən qurulmur — cədvəl ümumiyyətlə görünmür. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof BriefTemplate
            && $ownerRecord->isCustom()
            && (auth()->user()?->can('update', $ownerRecord) ?? false);
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Sual')
                ->columns(2)
                ->schema([
                    // Çox bölməli brifdə (sistem şablonunun nüsxəsi və ya bölmə
                    // əlavə edilmiş fərdi brif) sual hansı bölməyə düşür.
                    // Tək bölməli brifdə seçim mənasızdır — gizlənir, servis
                    // yeganə bölməni özü götürür.
                    Forms\Components\Select::make('brief_section_id')
                        ->label('Bölmə')
                        ->options(fn () => $this->sectionOptions())
                        ->default(fn () => array_key_first($this->sectionOptions()))
                        ->visible(fn () => count($this->sectionOptions()) > 1)
                        ->required(fn () => count($this->sectionOptions()) > 1)
                        ->native(false)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('type')
                        ->label('Sual tipi')
                        // Bankın xüsusi tipli sualı (matris, otaq siyahısı…) nüsxədə
                        // görünür və mətni redaktə olunur, amma tipi dəyişmir: onun
                        // konfiqi konstruktorda qurulmur. Disabled sahə göndərilmir,
                        // servis isə tipi mövcud sətirdən götürür.
                        ->options(fn (?BriefQuestion $record) => $record !== null && ! BriefBuilderService::isBuilderType($record->type)
                            ? [$record->type => BriefBuilderService::typeLabel($record->type).' (xüsusi tip — dəyişdirilmir)']
                            : BriefBuilderService::questionTypes())
                        ->disabled(fn (?BriefQuestion $record) => $record !== null && ! BriefBuilderService::isBuilderType($record->type))
                        ->helperText(fn (?BriefQuestion $record) => $record !== null && ! BriefBuilderService::isBuilderType($record->type)
                            ? 'Bu sual sistem bankının xüsusi tipindədir: mətni, izahı və məcburiliyi dəyişə bilərsiniz, cavab strukturu olduğu kimi qalır.'
                            : null)
                        ->default('text')
                        ->required()
                        ->live()
                        ->native(false)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('label')
                        ->label('Sualın mətni')
                        ->rows(2)
                        ->autosize()
                        ->required()
                        ->maxLength(500)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('help')
                        ->label('İzah (istəyə görə)')
                        ->rows(2)
                        ->autosize()
                        ->maxLength(1000)
                        ->helperText('Sualın altında kiçik köməkçi mətn kimi görünür.')
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('is_required')
                        ->label('Məcburi sual')
                        ->helperText('Müştəri bu suala cavab vermədən brifi göndərə bilməz.')
                        ->default(false),
                    Forms\Components\Toggle::make('allows_designer_choice')
                        ->label('«Dizaynerin ixtiyarına» seçimi')
                        ->helperText('Açıqdırsa müştəri cavab əvəzinə qərarı dizaynerə həvalə edə bilər.')
                        ->default(false),
                    Forms\Components\TextInput::make('position')
                        ->label('Sıra')
                        ->numeric()
                        ->minValue(0)
                        ->default(fn () => (int) $this->getOwnerRecord()->questions()->max('brief_questions.position') + 1)
                        ->helperText('Bölmə daxilində kiçik rəqəm əvvəl göstərilir.'),
                ]),

            Section::make('Cavab variantları')
                ->description(fn (Get $get) => BriefBuilderService::typeHasImages((string) $get('type'))
                    ? 'Hər variant bir kartdır: şəkil + başlıq. Müştəri kartlardan seçir. İstənilən sayda variant əlavə edə bilərsiniz.'
                    : 'Müştəri bu variantlardan seçir. İstənilən sayda variant əlavə edə bilərsiniz.')
                ->visible(fn (Get $get) => BriefBuilderService::typeHasOptions((string) $get('type')))
                ->schema([
                    Forms\Components\Repeater::make('option_list')
                        ->label('')
                        ->addActionLabel('Variant əlavə et')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->defaultItems(2)
                        ->minItems(fn (Get $get) => BriefBuilderService::typeHasOptions((string) $get('type')) ? 2 : 0)
                        ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null) ? (string) $state['label'] : null)
                        ->columns(fn (Get $get) => BriefBuilderService::typeHasImages((string) $get('type')) ? 2 : 1)
                        ->schema([
                            // Cavabın açarı — müştərinin cavabında saxlanır, ona görə
                            // sabit qalmalıdır; etiketdən çıxarılmır ki, etiketi
                            // düzəldəndə köhnə cavablar «tanınmayan variant» olmasın.
                            Forms\Components\Hidden::make('value'),
                            Forms\Components\TextInput::make('label')
                                ->label('Variantın adı')
                                ->maxLength(191)
                                ->required(fn (Get $get) => ! BriefBuilderService::typeHasImages((string) $get('../../type'))),
                            Forms\Components\FileUpload::make('image_url')
                                ->label('Şəkil')
                                ->image()
                                ->imageEditor()
                                ->disk('public')
                                ->directory('brief/custom')
                                ->visibility('public')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(3072)
                                ->rules([SafeUpload::image()])
                                // Mövcud istinad disk yoxlaması uğursuz olduğu üçün
                                // silinməməlidir — bax BriefQuestionResource-dakı eyni qeyd.
                                ->fetchFileInformation(false)
                                ->visible(fn (Get $get) => BriefBuilderService::typeHasImages((string) $get('../../type')))
                                ->helperText('4:3 nisbətdə ən yaxşı görünür.'),
                        ]),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            // Portaldakı sıra ilə: əvvəl bölmə, sonra bölmə daxilində sual.
            // HasManyThrough `brief_sections`-i onsuz da join edir.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('section')
                ->reorder()
                ->orderBy('brief_sections.position')
                ->orderBy('brief_sections.id')
                ->orderBy('brief_questions.position')
                ->orderBy('brief_questions.id'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('section_name')
                    ->label('Bölmə')
                    ->state(fn (BriefQuestion $record) => $record->section?->getTranslation('name', 'az'))
                    ->badge()
                    ->color(fn (BriefQuestion $record) => $record->section?->isRoomSection() ? 'info' : 'gray')
                    ->visible(fn () => count($this->sectionOptions()) > 1),
                Tables\Columns\TextColumn::make('position')
                    ->label('#')
                    ->width('3rem')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('label')
                    ->label('Sual')
                    ->formatStateUsing(fn ($state) => is_array($state) ? ($state['az'] ?? reset($state)) : $state)
                    ->description(fn (BriefQuestion $record) => $record->getTranslation('help', 'az') ?: null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('brief_questions.label', 'like', '%'.$search.'%'))
                    ->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->color(fn (string $state) => BriefBuilderService::isBuilderType($state) ? 'gray' : 'warning')
                    ->formatStateUsing(fn (string $state) => BriefBuilderService::typeLabel($state)),
                Tables\Columns\IconColumn::make('conditional')
                    ->label('Şərti')
                    ->state(fn (BriefQuestion $record) => filled($record->skip_logic))
                    ->boolean()
                    ->trueIcon('heroicon-o-arrows-right-left')
                    ->falseIcon('')
                    ->tooltip(fn (BriefQuestion $record) => filled($record->skip_logic) ? 'Başqa sualın cavabından asılı olaraq göstərilir' : null)
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('options_count')
                    ->label('Variant')
                    ->state(fn (BriefQuestion $record) => is_array($record->options) && array_is_list($record->options) ? count($record->options) : null)
                    ->placeholder('—')
                    ->alignCenter(),
                Tables\Columns\IconColumn::make('is_required')
                    ->label('Məcburi')
                    ->boolean(),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Sual əlavə et')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Yeni sual')
                    ->modalWidth(Width::Full)
                    ->using(fn (array $data) => $this->guard(fn () => app(BriefBuilderService::class)->addQuestion($this->getOwnerRecord(), $data)))
                    ->successNotificationTitle('Sual əlavə edildi'),
            ])
            ->actions([
                Actions\EditAction::make()
                    ->modalHeading('Sualı düzəlt')
                    ->modalWidth(Width::Full)
                    ->mutateRecordDataUsing(fn (array $data, BriefQuestion $record) => static::recordToForm($record, $data))
                    ->using(fn (BriefQuestion $record, array $data) => $this->guard(fn () => app(BriefBuilderService::class)->updateQuestion($record, $data))),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Sual brifdən çıxarılacaq. Müştərilər bu suala artıq cavab veribsə, sual silinmir — gizlədilir, cavablar layihədə oxunaqlı qalır.')
                    ->using(fn (BriefQuestion $record) => app(BriefBuilderService::class)->removeQuestion($record) || true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('brief_section_id')
                    ->label('Bölmə')
                    ->options(fn () => $this->sectionOptions())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $id) => $q->where('brief_questions.brief_section_id', $id),
                    ))
                    ->visible(fn () => count($this->sectionOptions()) > 1),
            ])
            ->emptyStateHeading('Hələ sual yoxdur')
            ->emptyStateDescription('«Sual əlavə et» ilə başlayın — şəkilli, variantlı, mətnli və ya bəli/xeyr tipli suallar qura bilərsiniz.');
    }

    /**
     * Sətir → forma: tərcümə massivləri düz mətnə, `options` isə repeater
     * sətirlərinə açılır. Filament formanı `attributesToArray()`-dən doldurur,
     * spatie/translatable isə `label`-ı cari dilə çevirir — ona görə açıq
     * yazırıq ki, hansı dilin gəldiyi təsadüfdən asılı olmasın.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function recordToForm(BriefQuestion $record, array $data): array
    {
        $data['label'] = $record->getTranslation('label', 'az');
        $data['help'] = $record->getTranslation('help', 'az') ?: null;
        $data['option_list'] = is_array($record->options) && array_is_list($record->options)
            ? array_map(fn (array $option) => [
                'value' => $option['value'] ?? null,
                'label' => $option['label']['az'] ?? ($option['label'] ?? null),
                'image_url' => $option['image_url'] ?? null,
            ], $record->options)
            : [];

        unset($data['options']);

        return $data;
    }

    /**
     * Brifin bölmələri seçim üçün: `[id => ad]`, otaq bölmələri işarələnir.
     * Deaktiv bölmə də siyahıdadır — sualı ora köçürmək onu gizlətməkdir və
     * admin bunu bilərəkdən edə bilər; etiket bunu açıq deyir.
     *
     * @return array<int, string>
     */
    private function sectionOptions(): array
    {
        return $this->sectionOptionsCache ??= BriefSection::query()
            ->where('brief_template_id', $this->getOwnerRecord()->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (BriefSection $s) => [$s->id => $s->getTranslation('name', 'az')
                .($s->isRoomSection() ? ' (otaq bölməsi)' : '')
                .($s->active ? '' : ' — deaktiv')])
            ->all();
    }

    /** @var array<int, string>|null bir sorğu ərzində yaddaş */
    private ?array $sectionOptionsCache = null;

    /**
     * Servis qayda pozuntusunu `InvalidArgumentException` ilə bildirir —
     * admin ağ xəta səhifəsi yox, izahlı bildiriş görsün və modal açıq qalsın.
     */
    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
