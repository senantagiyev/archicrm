<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Yeni hesab-faktura'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Invoice::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $tabs = [
            'all' => Tab::make('Hamısı')->badge($counts->sum()),
        ];

        foreach (InvoiceStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->badge($counts[$status->value] ?? 0)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status->value));
        }

        return $tabs;
    }
}
