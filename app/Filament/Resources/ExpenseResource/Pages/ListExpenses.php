<?php

namespace App\Filament\Resources\ExpenseResource\Pages;

use App\Enums\ExpenseStatus;
use App\Filament\Resources\ExpenseResource;
use App\Models\Expense;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Yeni xərc'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Expense::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $tabs = [
            'all' => Tab::make('Hamısı')->badge($counts->sum()),
        ];

        foreach (ExpenseStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge($counts[$status->value] ?? 0)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
