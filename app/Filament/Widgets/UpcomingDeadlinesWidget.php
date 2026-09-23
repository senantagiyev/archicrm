<?php

namespace App\Filament\Widgets;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\StageStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Stage;
use App\Support\AccessMatrix;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Rəhbər üçün: yaxın 14 gündə bitməli və artıq gecikmiş mərhələlər. */
class UpcomingDeadlinesWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /**
     * Ekran mərhələ qrafikinə nəzarət üçündür, ona görə domen Mərhələ/Tapşırıq,
     * səviyyə isə Tam-dır. Matrisdə Tam yalnız sahibkarda və layihə menecerində
     * var (dizayner/vizualizator/komplektasiya = Redaktə, mühasib = Baxış) —
     * yəni standart altı rolun mövcud davranışı dəyişmir, amma icazə artıq rolun
     * adından yox, matrisdən gəlir: «koordinator» adlı xüsusi rola Mərhələ/
     * Tapşırıq = Tam verilsə, vidjet ona da açılır.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::Full);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Yaxınlaşan və gecikmiş mərhələlər')
            ->query(fn (): Builder => $this->scoped(Stage::query()
                ->whereNotIn('status', [StageStatus::Done->value])
                ->whereNotNull('date_plan_end')
                ->whereDate('date_plan_end', '<=', today()->addDays(14))
                ->with(['project', 'responsible'])
                ->orderBy('date_plan_end')))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Mərhələ')
                    ->description(fn (Stage $r) => $r->project?->name),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Məsul')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('date_plan_end')
                    ->label('Bitmə (plan)')
                    ->date('d.m.Y')
                    ->color(fn (Stage $r) => $r->isOverdue() ? 'danger' : 'warning')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StageStatus $state) => $state->label())
                    ->color(fn (StageStatus $state) => $state->color()),
            ])
            ->recordUrl(fn (Stage $record) => ProjectResource::getUrl('edit', ['record' => $record->project_id]))
            ->paginated([5, 10])
            ->emptyStateHeading('Yaxın 14 gündə bitməli mərhələ yoxdur');
    }

    /**
     * Mərhələ sətirlərini istifadəçinin görə bildiyi layihələrlə məhdudlaşdırır.
     * Vidjeti görmək hüququ (Mərhələ/Tapşırıq = Tam) hansı SƏTİRLƏRİ görmək
     * hüququ demək deyil: layihə meneceri matrisdə «öz layihələri» ilə
     * məhdudlaşır, ona görə üzvü olmadığı layihənin mərhələ adı və məsul şəxsi
     * cədvələ düşməməlidir. Sahibkar və mühasib üçün məhdudiyyət yoxdur.
     */
    private function scoped(Builder $query): Builder
    {
        $user = auth()->user();

        if ($user === null || ! AccessMatrix::requiresOwnProject($user)) {
            return $query;
        }

        return $query->whereIn('project_id', Project::query()
            ->where(fn (Builder $q) => $q
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id)))
            ->pluck('id')
            ->all());
    }
}
