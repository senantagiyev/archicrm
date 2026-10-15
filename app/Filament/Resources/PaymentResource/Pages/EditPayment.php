<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Concerns\HandlesRowVersionConflict;
use App\Filament\Resources\PaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPayment extends EditRecord
{
    use HandlesRowVersionConflict;

    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->requiresConfirmation(),
        ];
    }
}
