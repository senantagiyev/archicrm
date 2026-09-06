<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Issued invoices are never physically deleted — they are cancelled.
            Actions\Action::make('cancel')
                ->label('Ləğv et')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Hesab-faktura ləğv ediləcək. Bu əməliyyat jurnalda qeyd olunur.')
                ->visible(fn (Invoice $record) => ! in_array($record->status, [InvoiceStatus::Draft, InvoiceStatus::Cancelled], true))
                ->action(fn (Invoice $record) => $record->update(['status' => InvoiceStatus::Cancelled])),
            Actions\DeleteAction::make()
                ->visible(fn (Invoice $record) => $record->status === InvoiceStatus::Draft),
        ];
    }
}
