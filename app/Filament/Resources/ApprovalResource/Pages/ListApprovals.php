<?php

namespace App\Filament\Resources\ApprovalResource\Pages;

use App\Filament\Resources\ApprovalResource;
use App\Filament\Resources\ApprovalResource\ApprovalRequestForm;
use Filament\Resources\Pages\ListRecords;

class ListApprovals extends ListRecords
{
    protected static string $resource = ApprovalResource::class;

    // Əvvəl bu səhifədə heç bir düymə yox idi — studiya razılaşdırmanı harada
    // yaradacağını tapa bilmirdi və bölməni yalnız oxunan siyahı sanırdı.
    protected function getHeaderActions(): array
    {
        return [
            ApprovalRequestForm::action(),
        ];
    }

    public function getSubheading(): ?string
    {
        return ApprovalRequestForm::HELP.' Smeta və komplektasiya sətirlərini layihənin öz tablarından da göndərmək olar.';
    }
}
