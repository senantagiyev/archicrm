<?php

namespace App\Filament\Resources\TranslationResource\Pages;

use App\Filament\Resources\TranslationResource;
use App\Models\Translation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTranslation extends CreateRecord
{
    protected static string $resource = TranslationResource::class;

    /**
     * Fold the three per-locale form fields into the translatable `value` column.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['value'] = [
            'az' => $data['az'] ?? '',
            'ru' => $data['ru'] ?? '',
            'en' => $data['en'] ?? '',
        ];

        unset($data['az'], $data['ru'], $data['en']);

        return $data;
    }

    /**
     * The (group, key) pair is unique — upsert instead of letting a duplicate
     * insert throw a raw QueryException.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return Translation::updateOrCreate(
            ['group' => $data['group'], 'key' => $data['key']],
            ['value' => $data['value']],
        );
    }
}
