<?php

namespace App\Filament\Resources\BriefTemplateResource\Pages;

use App\Filament\Resources\BriefTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBriefTemplates extends ListRecords
{
    protected static string $resource = BriefTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Yeni brif')
                ->icon('heroicon-o-plus'),
        ];
    }
}
