<?php

namespace App\Filament\Resources\BriefTemplateResource\Pages;

use App\Filament\Resources\BriefTemplateResource;
use App\Services\Brief\BriefBuilderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBriefTemplate extends CreateRecord
{
    protected static string $resource = BriefTemplateResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Şablon tək başına yaranmır — bölməsi ilə birlikdə yaranır, açarı və
     * studiyası servisdə verilir. Forma yalnız ad və təsviri bilir.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(BriefBuilderService::class)->createTemplate(
            (string) $data['title'],
            $data['summary'] ?? null,
            auth()->user(),
        );
    }

    /** Ad yazılan kimi suallara keçilir — konstruktorun əsas işi oradadır. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Brif yaradıldı — indi suallarını əlavə edin';
    }
}
