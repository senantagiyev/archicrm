<?php

namespace App\Filament\Widgets;

use App\Enums\StaffRole;
use App\Enums\StageStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Stage;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Rəhbər üçün: yaxın 14 gündə bitməli və artıq gecikmiş mərhələlər. */
class UpcomingDeadlinesWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->isOwner()
            || auth()->user()?->role === StaffRole::ProjectManager;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Yaxınlaşan və gecikmiş mərhələlər')
            ->query(fn (): Builder => Stage::query()
                ->whereNotIn('status', [StageStatus::Done->value])
                ->whereNotNull('date_plan_end')
                ->whereDate('date_plan_end', '<=', today()->addDays(14))
                ->with(['project', 'responsible'])
                ->orderBy('date_plan_end'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Mərhələ')
                    ->description(fn (Stage $r) => $r->project->name),
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
}
