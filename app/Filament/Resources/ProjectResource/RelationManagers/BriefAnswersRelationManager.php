<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Models\BriefAnswer;
use App\Models\BriefSection;
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
                Tables\Columns\TextColumn::make('value')
                    ->label('Cavab')
                    ->state(function (BriefAnswer $r) {
                        if ($r->delegated_to_designer) {
                            return '💡 Dizaynerin tövsiyəsi lazımdır';
                        }
                        if (is_array($r->value)) {
                            return collect($r->value)->map(fn ($v) => $r->question?->optionLabel((string) $v) ?? $v)->implode(', ');
                        }
                        if ($r->question?->type === 'boolean') {
                            return $r->value === '1' || $r->value === true ? 'Bəli' : 'Xeyr';
                        }
                        if ($r->question?->type === 'select' && $r->value !== null) {
                            return $r->question->optionLabel((string) $r->value);
                        }

                        return $r->value;
                    })
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
            ->headerActions([])
            ->actions([]);
    }
}
