<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Resources\ProjectResource;
use App\Models\Brief;
use App\Models\BriefAnswer;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\User;
use App\Services\Brief\BriefService;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Layihənin «Brif» tabı — müştərinin cavabları və brif göndərmə.
 *
 * Layihədə bir neçə brif ola bilər (hər şablondan biri). Cədvəl bütün
 * briflərin cavablarını göstərir; iki və daha çox brif olanda «Brif» sütunu
 * və süzgəci açılır.
 *
 *  • «Brifi müştəriyə göndər» / «Brif şablonunu dəyiş» (`briefTemplate`) —
 *    MÖVCUD brifin şablonunu dəyişir (məs. Quick → Premium; cavablar açar üzrə
 *    köçür) və onu müştəriyə açır. Brif hələ yoxdursa, bu, ilk göndərişdir.
 *  • «Yeni brif göndər» (`sendBrief`) — layihəyə ƏLAVƏ brif; əvvəlkilər olduğu
 *    kimi qalır, müştəri hamısını portalda ayrıca görür.
 */
class BriefAnswersRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'briefAnswers';

    protected static ?string $title = 'Brif';

    protected static ?string $modelLabel = 'Cavab';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['question.section', 'room', 'brief.template']))
            ->description(fn () => $this->stateDescription())
            ->emptyStateHeading(fn () => $this->presentedBriefs()->isNotEmpty() ? 'Müştəri hələ cavab yazmayıb' : 'Brif göndərilməyib')
            ->emptyStateDescription(fn () => $this->presentedBriefs()->isNotEmpty()
                ? 'Cavablar müştəri portalda yazdıqca burada görünəcək.'
                : 'Müştəri brifi yalnız siz göndərdikdən sonra görür.')
            ->columns([
                Tables\Columns\TextColumn::make('brief_name')
                    ->label('Brif')
                    ->state(fn (BriefAnswer $r) => $r->brief?->template?->getTranslation('name', 'az'))
                    ->badge()
                    ->color('info')
                    ->visible(fn () => $this->projectBriefs()->count() > 1),
                Tables\Columns\TextColumn::make('section')
                    ->label('Bölmə')
                    ->state(fn (BriefAnswer $r) => ($r->room?->label ?? $r->question?->section?->getTranslation('name', 'az')))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('question.label')
                    ->label('Sual')
                    ->state(fn (BriefAnswer $r) => $r->question?->getTranslation('label', 'az'))
                    ->wrap(),
                // Spec Part 12 §6: cavab səviyyəsində prioritet.
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->state(fn (BriefAnswer $r) => $r->delegated_to_designer ? 'Dizaynerə etibar edilib' : 'Normal')
                    ->badge()
                    ->color(fn (BriefAnswer $r) => $r->delegated_to_designer ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('value')
                    ->label('Cavab')
                    ->state(fn (BriefAnswer $r) => $r->delegated_to_designer
                        ? '💡 Dizaynerin tövsiyəsi lazımdır'
                        : ($r->question?->displayValue($r->value) ?: '—'))
                    ->wrap(),
                Tables\Columns\TextColumn::make('answered_at')
                    ->label('Tarix')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('id')
            ->filters([
                Tables\Filters\SelectFilter::make('brief_id')
                    ->label('Brif')
                    ->options(fn () => $this->projectBriefs()
                        ->mapWithKeys(fn (Brief $b) => [$b->id => $b->template?->getTranslation('name', 'az') ?? 'Brif #'.$b->id])
                        ->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $id) => $q->where('brief_answers.brief_id', $id),
                    ))
                    ->visible(fn () => $this->projectBriefs()->count() > 1),
                Tables\Filters\SelectFilter::make('brief_question_id')
                    ->label('Bölmə')
                    // Yalnız BU layihənin briflərinin bölmələri. Əvvəl siyahı bütün
                    // `brief_sections`-i çəkirdi — fərdi briflər gələndən sonra bu,
                    // başqa studiyanın brifinin adını («Filankəs üçün brif») hər
                    // studiyanın filtrinə düşürürdü.
                    ->options(function () {
                        $templateIds = $this->projectBriefs()->pluck('brief_template_id')->filter()->all();

                        if ($templateIds === []) {
                            return [];
                        }

                        return BriefSection::whereIn('brief_template_id', $templateIds)
                            ->orderBy('brief_template_id')
                            ->orderBy('position')
                            ->get()
                            ->mapWithKeys(fn ($s) => [$s->id => $s->getTranslation('name', 'az')])
                            ->all();
                    })
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] ?? null) {
                            $query->whereHas('question', fn (Builder $q) => $q->where('brief_section_id', $data['value']));
                        }
                    }),
            ])
            ->headerActions([
                // Spec Part 12 / Screen 14: full Designer View (summary, risks, priorities, versions).
                Actions\Action::make('briefReview')
                    ->label('Dizayner baxışı')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->url(fn () => ProjectResource::getUrl('brief-review', ['record' => $this->getOwnerRecord()])),
                $this->sendBriefAction(),
                $this->switchTemplateAction(),
            ])
            ->actions([]);
    }

    /**
     * Layihəyə ƏLAVƏ brif — yalnız artıq göndərilmiş brif olanda görünür (ilk
     * göndəriş `briefTemplate` ilədir). Layihədə istifadə olunan şablonlar
     * siyahıda yoxdur: bir şablondan bir brif olur.
     */
    private function sendBriefAction(): Actions\Action
    {
        return Actions\Action::make('sendBrief')
            ->label('Yeni brif göndər')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->visible(fn () => self::mayManageBriefs() && $this->presentedBriefs()->isNotEmpty())
            ->authorize(fn () => self::mayManageBriefs())
            ->modalHeading('Layihəyə yeni brif göndər')
            ->modalDescription('Müştəri yeni brifi portalda əvvəlkilərin yanında ayrıca görəcək və bildiriş alacaq. Mövcud briflər və cavablar dəyişmir.')
            ->schema([
                Forms\Components\Select::make('brief_template_id')
                    ->label('Brif / şablon')
                    ->options(fn () => $this->templateOptions(exclude: $this->projectBriefs()->pluck('brief_template_id')->filter()->all()))
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $template = $this->catalogTemplate((int) $data['brief_template_id']);

                app(BriefService::class)->present($this->getOwnerRecord(), $template, auth()->user());

                Notification::make()->success()->title('Yeni brif müştəriyə göndərildi')->send();
            });
    }

    /**
     * Mövcud brifin şablonunu dəyişir və onu müştəriyə açır. Brif hələ
     * göndərilməyibsə (qaralama və ya heç nə) — bu, ilk göndərişdir.
     */
    private function switchTemplateAction(): Actions\Action
    {
        return Actions\Action::make('briefTemplate')
            ->label(fn () => $this->presentedBriefs()->isNotEmpty() ? 'Brif şablonunu dəyiş' : 'Brifi müştəriyə göndər')
            ->icon(fn () => $this->presentedBriefs()->isNotEmpty() ? 'heroicon-o-rectangle-stack' : 'heroicon-o-paper-airplane')
            ->color(fn () => $this->presentedBriefs()->isNotEmpty() ? 'gray' : 'primary')
            // Şablonu dəyişmək cavabları yenidən ünvanlayır, otaq
            // bölmələrini qurur və proqresi yenidən hesablayır — bu, oxu
            // deyil, brif üzərində TAM səlahiyyətdir. Əvvəl heç bir
            // yoxlama yox idi: Brif = Baxış olan rol (komplektasiya,
            // vizualizator) düyməni görür və basa bilirdi.
            ->visible(fn () => self::mayManageBriefs())
            ->authorize(fn () => self::mayManageBriefs())
            ->modalHeading(fn () => $this->presentedBriefs()->isNotEmpty() ? 'Brif şablonunu dəyiş' : 'Brifi müştəriyə göndər')
            ->modalDescription(fn () => $this->presentedBriefs()->isNotEmpty()
                ? 'Seçilmiş brifin şablonu dəyişir: eyni açarlı suallar üzrə cavablar köçürülür, köhnə cavablar bazada qalır. Brif artıq göndərilmişdisə, yeni suallar üçün yenidən açılır. Ayrıca brif lazımdırsa, «Yeni brif göndər» düyməsini işlədin.'
                : 'Müştəri bildiriş alacaq və brif portalda açılacaq. Ona qədər müştəri heç bir brif görmür.')
            ->schema([
                Forms\Components\Select::make('brief_id')
                    ->label('Hansı brif')
                    ->options(fn () => $this->projectBriefs()
                        ->mapWithKeys(fn (Brief $b) => [$b->id => ($b->template?->getTranslation('name', 'az') ?? 'Brif #'.$b->id)
                            .($b->isPresented() ? '' : ' (göndərilməyib)')])
                        ->all())
                    ->default(fn () => $this->getOwnerRecord()->brief?->id)
                    ->visible(fn () => $this->projectBriefs()->count() > 1)
                    ->required(fn () => $this->projectBriefs()->count() > 1)
                    ->live()
                    ->native(false),
                Forms\Components\Select::make('brief_template_id')
                    ->label('Brif / şablon')
                    // Hər qrupun İÇİ də massiv olmalıdır. Xarici `all()`
                    // yalnız üst səviyyəni çevirirdi, qruplar Collection
                    // qalırdı; Filament isə qruplu siyahını yalnız massiv
                    // kimi tanıyır, ona görə `in:` qaydasının icazə
                    // siyahısı BOŞ qalırdı və seçilən hər dəyər
                    // validasiyadan geri qayıdırdı — yəni Quick → Premium
                    // keçidi paneldən ümumiyyətlə saxlanıla bilmirdi.
                    ->options(fn (Get $get) => $this->templateOptions(
                        exclude: $this->projectBriefs()
                            ->reject(fn (Brief $b) => $b->id === $this->targetBrief($get('brief_id'))?->id)
                            ->pluck('brief_template_id')->filter()->all(),
                    ))
                    ->default(fn () => $this->defaultTemplateId())
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $template = $this->catalogTemplate((int) $data['brief_template_id']);
                $service = app(BriefService::class);
                $brief = $this->targetBrief($data['brief_id'] ?? null);

                try {
                    if ($brief === null) {
                        $service->present($this->getOwnerRecord(), $template, auth()->user());
                    } else {
                        $service->switchTemplate($brief, $template);
                        $service->presentBrief($brief->fresh());
                    }
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                }
            })
            ->successNotificationTitle('Brif müştəriyə göndərildi');
    }

    /**
     * Studiyanın göndərə biləcəyi şablonlar, səviyyəyə görə qruplu.
     * `catalogFor()` başqa studiyanın fərdi brifini çıxarır, studiyanın redaktə
     * etdiyi sistem şablonunu isə nüsxəsi ilə əvəzləyir.
     *
     * @param  array<int, int>  $exclude  bu layihədə artıq istifadə olunan şablonlar
     * @return array<string, array<int, string>>
     */
    private function templateOptions(array $exclude = []): array
    {
        return BriefTemplate::query()
            ->catalogFor(auth()->user()?->tenant_id)
            ->where('active', true)
            ->when($exclude !== [], fn (Builder $q) => $q->whereNotIn('id', $exclude))
            ->orderByRaw('tenant_id is null')
            ->orderBy('position')
            ->get()
            ->groupBy(fn ($t) => $t->levelLabel())
            ->map(fn ($group) => $group->mapWithKeys(fn ($t) => [$t->id => $t->getTranslation('name', 'az')])->all())
            ->all();
    }

    /** `catalogFor()` təkrar yoxlanılır: seçim siyahısı UI-dır, id isə payload-dan gəlir. */
    private function catalogTemplate(int $id): BriefTemplate
    {
        return BriefTemplate::query()
            ->catalogFor(auth()->user()?->tenant_id)
            ->findOrFail($id);
    }

    /** Formadan gələn brif id-si — yalnız BU layihənin brifi; boşdursa cari brif. */
    private function targetBrief(mixed $briefId): ?Brief
    {
        if (filled($briefId)) {
            return $this->projectBriefs()->firstWhere('id', (int) $briefId) ?? abort(404);
        }

        return $this->getOwnerRecord()->brief()->first();
    }

    /**
     * Seçimdə əvvəlcədən işarələnən şablon: layihənin cari şablonu, o, studiyanın
     * redaktə etdiyi sistem şablonudursa — nüsxəsi (orijinal siyahıda yoxdur),
     * brif yoxdursa studiyanın defoltu.
     */
    private function defaultTemplateId(): ?int
    {
        $tenantId = auth()->user()?->tenant_id;
        $current = $this->getOwnerRecord()->brief?->template;

        if ($current !== null) {
            return $current->forkFor($tenantId)?->id ?? $current->id;
        }

        return BriefTemplate::defaultFor($tenantId)?->id;
    }

    /** @return Collection<int, Brief> layihənin bütün brifləri */
    private function projectBriefs(): Collection
    {
        return $this->getOwnerRecord()->briefs()->with('template')->get();
    }

    /** @return Collection<int, Brief> müştəriyə göndərilmiş briflər */
    private function presentedBriefs(): Collection
    {
        return $this->projectBriefs()->filter(fn (Brief $b) => $b->isPresented())->values();
    }

    /**
     * Cədvəlin başındakı vəziyyət sətri — admin bir baxışda görür: hansı
     * briflər göndərilib, nə vaxt, hansı statusda, nə qədər doldurulub.
     */
    private function stateDescription(): string
    {
        $presented = $this->presentedBriefs();

        if ($presented->isEmpty()) {
            return 'Brif hələ müştəriyə göndərilməyib — müştəri portalda brif görmür. «Brifi müştəriyə göndər» ilə fərdi brif və ya sistem şablonu seçin.';
        }

        return $presented
            ->map(fn (Brief $brief) => sprintf(
                '«%s» · göndərilib %s · %s · %d%% doldurulub',
                $brief->template?->getTranslation('name', 'az') ?? 'Şablon silinib',
                $brief->presented_at->format('d.m.Y H:i'),
                $brief->statusEnum()->label(),
                (int) $brief->progress,
            ))
            ->implode('  |  ');
    }

    /** Şablon keçidi brifi yenidən qurur — `BriefReview::canManageBrief()` ilə eyni sədd. */
    private static function mayManageBriefs(): bool
    {
        $user = auth()->user();

        // Yalnız İŞÇİ: matris müştəri hesabını tanımır, ona görə tip yoxlanılır.
        return $user instanceof User && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }
}
