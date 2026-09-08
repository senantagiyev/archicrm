<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Models\BriefAnswer;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Services\Brief\BriefRiskDetector;
use App\Services\Brief\BriefService;
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
                // Spec Part 12 §3–4: risklər + doldurulmamış məcburi sahələr.
                Actions\Action::make('briefReview')
                    ->label('Risklər və boşluqlar')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Bağla')
                    ->modalContent(function () {
                        $brief = app(BriefService::class)->forProject($this->getOwnerRecord());

                        return view('filament.brief-review', [
                            'risks' => app(BriefRiskDetector::class)->detect($brief),
                            'missing' => app(BriefService::class)->missingRequired($brief),
                        ]);
                    }),
                Actions\Action::make('briefTemplate')
                    ->label('Brif şablonu')
                    ->icon('heroicon-o-rectangle-stack')
                    ->modalDescription('Layihənin brif şablonunu seçin. Müştəri brifi doldurmağa başladıqdan sonra şablonu dəyişmək tövsiyə olunmur.')
                    ->schema([
                        Forms\Components\Select::make('brief_template_id')
                            ->label('Şablon')
                            ->options(fn () => BriefTemplate::where('active', true)->orderBy('position')->get()
                                ->mapWithKeys(fn ($t) => [$t->id => $t->getTranslation('name', 'az')]))
                            ->default(fn () => optional($this->getOwnerRecord()->brief)->brief_template_id
                                ?? optional(BriefTemplate::default())->id)
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data) {
                        $brief = app(BriefService::class)->forProject($this->getOwnerRecord());
                        $brief->update(['brief_template_id' => $data['brief_template_id']]);
                    }),
            ])
            ->actions([]);
    }
}
