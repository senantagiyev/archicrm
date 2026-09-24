<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Resources\ProjectResource;
use App\Models\BriefAnswer;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Services\Brief\BriefService;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BriefAnswersRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'briefAnswers';

    protected static ?string $title = 'Brif';

    protected static ?string $modelLabel = 'Cavab';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['question.section', 'room']))
            ->columns([
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
                Tables\Filters\SelectFilter::make('brief_question_id')
                    ->label('Bölmə')
                    ->options(fn () => BriefSection::orderBy('position')->get()
                        ->mapWithKeys(fn ($s) => [$s->id => $s->getTranslation('name', 'az')]))
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
                Actions\Action::make('briefTemplate')
                    ->label('Brif şablonu')
                    ->icon('heroicon-o-rectangle-stack')
                    // Şablonu dəyişmək cavabları yenidən ünvanlayır, otaq
                    // bölmələrini qurur və proqresi yenidən hesablayır — bu, oxu
                    // deyil, brif üzərində TAM səlahiyyətdir. Əvvəl heç bir
                    // yoxlama yox idi: Brif = Baxış olan rol (komplektasiya,
                    // vizualizator) düyməni görür və basa bilirdi.
                    ->visible(fn () => self::maySwitchTemplate())
                    ->authorize(fn () => self::maySwitchTemplate())
                    ->modalDescription('Brifin səviyyəsini/şablonunu seçin. Quick Brief-dən Premium-a keçəndə müştərinin verdiyi cavablar avtomatik köçürülür (eyni suallar üzrə).')
                    ->schema([
                        Forms\Components\Select::make('brief_template_id')
                            ->label('Şablon')
                            // Hər qrupun İÇİ də massiv olmalıdır. Xarici `all()`
                            // yalnız üst səviyyəni çevirirdi, qruplar Collection
                            // qalırdı; Filament isə qruplu siyahını yalnız massiv
                            // kimi tanıyır, ona görə `in:` qaydasının icazə
                            // siyahısı BOŞ qalırdı və seçilən hər dəyər
                            // validasiyadan geri qayıdırdı — yəni Quick → Premium
                            // keçidi paneldən ümumiyyətlə saxlanıla bilmirdi.
                            ->options(fn () => BriefTemplate::where('active', true)->orderBy('position')->get()
                                ->groupBy(fn ($t) => $t->levelLabel())
                                ->map(fn ($group) => $group->mapWithKeys(fn ($t) => [$t->id => $t->getTranslation('name', 'az')])->all())
                                ->all())
                            ->default(fn () => optional($this->getOwnerRecord()->brief)->brief_template_id
                                ?? optional(BriefTemplate::default())->id)
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data) {
                        $service = app(BriefService::class);
                        $service->switchTemplate(
                            $service->forProject($this->getOwnerRecord()),
                            BriefTemplate::findOrFail($data['brief_template_id']),
                        );
                    }),
            ])
            ->actions([]);
    }

    /** Şablon keçidi brifi yenidən qurur — `BriefReview::canManageBrief()` ilə eyni sədd. */
    private static function maySwitchTemplate(): bool
    {
        $user = auth()->user();

        return $user !== null && AccessMatrix::allows($user, Domain::Brief, AccessLevel::Full);
    }
}
