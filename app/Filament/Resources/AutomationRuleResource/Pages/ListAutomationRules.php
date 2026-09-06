<?php

namespace App\Filament\Resources\AutomationRuleResource\Pages;

use App\Filament\Resources\AutomationRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListAutomationRules extends ListRecords
{
    protected static string $resource = AutomationRuleResource::class;

    // Catalog is seeded (Əlavə B) — rules are toggled, never created by hand.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
