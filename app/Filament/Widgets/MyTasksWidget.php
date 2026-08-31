<?php

namespace App\Filament\Widgets;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource;
use App\Models\Task;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** İşçi dashboardu — bugünkü/bu həftəki tapşırıqlarım (TZ §5.7). */
class MyTasksWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Mənim tapşırıqlarım (bu həftə)')
            ->query(fn (): Builder => Task::query()
                ->where('assignee_user_id', auth()->id())
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value])
                ->whereDate('deadline', '<=', today()->endOfWeek())
                ->with(['project', 'stage'])
                ->orderBy('deadline'))
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Tapşırıq')
                    ->description(fn (Task $r) => $r->project->name.' — '.$r->stage->name)
                    ->wrap(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->badge()
                    ->formatStateUsing(fn (TaskPriority $state) => $state->label())
                    ->color(fn (TaskPriority $state) => $state->color()),
                Tables\Columns\TextColumn::make('deadline')
                    ->label('Son tarix')
                    ->date('d.m.Y')
                    ->color(fn (Task $r) => $r->isOverdue() ? 'danger' : null)
                    ->weight(fn (Task $r) => $r->isOverdue() ? 'bold' : null),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->color()),
            ])
            ->recordUrl(fn (Task $record) => TaskResource::getUrl('edit', ['record' => $record]))
            ->paginated([5, 10])
            ->emptyStateHeading('Bu həftə tapşırığınız yoxdur');
    }
}
