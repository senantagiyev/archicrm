<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Yeni sifariş'),
        ];
    }

    public function getTabs(): array
    {
        $counts = PurchaseOrder::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $tabs = [
            'all' => Tab::make('Hamısı')->badge($counts->sum()),
        ];

        foreach (PurchaseOrderStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge($counts[$status->value] ?? 0)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
