<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Yeni lid'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Lead::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $tabs = [
            'all' => Tab::make('Hamısı')->badge($counts->sum()),
        ];

        foreach (LeadStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge($counts[$status->value] ?? 0)
                ->badgeColor($status->color())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
