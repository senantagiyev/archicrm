<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Yeni tapşırıq'),
        ];
    }

    public function getTabs(): array
    {
        // NOTE: the closure parameter MUST be named $query — Filament injects
        // evaluate() arguments by parameter name, and an unmatched name gets a
        // model-less Builder resolved from the container.
        $open = fn (Builder $query) => $query->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value]);
        $mine = fn (Builder $query) => $query->where('assignee_user_id', auth()->id());

        return [
            'my_today' => Tab::make('Bu gün (mənim)')
                ->modifyQueryUsing(fn (Builder $query) => $open($mine($query))->whereDate('deadline', '<=', today())),
            'my_week' => Tab::make('Bu həftə (mənim)')
                ->modifyQueryUsing(fn (Builder $query) => $open($mine($query))->whereDate('deadline', '<=', today()->endOfWeek())),
            'overdue' => Tab::make('Gecikmiş')
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereDate('deadline', '<', today())),
            'open' => Tab::make('Açıq')
                ->modifyQueryUsing(fn (Builder $query) => $open($query)),
            'all' => Tab::make('Hamısı'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
