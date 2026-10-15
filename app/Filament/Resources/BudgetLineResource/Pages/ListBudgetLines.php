<?php

namespace App\Filament\Resources\BudgetLineResource\Pages;

use App\Filament\Resources\BudgetLineResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBudgetLines extends ListRecords
{
    protected static string $resource = BudgetLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Sətir əlavə et'),
        ];
    }
}
