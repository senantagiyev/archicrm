<?php

namespace App\Filament\Resources\BudgetLineResource\Pages;

use App\Enums\ApprovalStatus;
use App\Filament\Concerns\HandlesRowVersionConflict;
use App\Filament\Resources\BudgetLineResource;
use App\Models\BudgetLine;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBudgetLine extends EditRecord
{
    use HandlesRowVersionConflict;

    protected static string $resource = BudgetLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->hidden(fn (BudgetLine $record) => $record->approval_status === ApprovalStatus::Approved),
        ];
    }
}
