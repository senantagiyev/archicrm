<?php

namespace App\Filament\Resources\TranslationResource\Pages;

use App\Filament\Resources\TranslationResource;
use App\Models\Translation;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTranslation extends EditRecord
{
    protected static string $resource = TranslationResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    /**
     * Expand the translatable `value` column into the three per-locale form fields.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Translation $record */
        $record = $this->record;

        $data['az'] = $record->getTranslation('value', 'az', false);
        $data['ru'] = $record->getTranslation('value', 'ru', false);
        $data['en'] = $record->getTranslation('value', 'en', false);

        return $data;
    }

    /**
     * Fold the three per-locale form fields back into the translatable `value` column.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['value'] = [
            'az' => $data['az'] ?? '',
            'ru' => $data['ru'] ?? '',
            'en' => $data['en'] ?? '',
        ];

        unset($data['az'], $data['ru'], $data['en']);

        return $data;
    }
}
