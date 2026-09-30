<?php

namespace App\Filament\Resources\BriefTemplateResource\Pages;

use App\Filament\Resources\BriefTemplateResource;
use App\Services\Brief\BriefBuilderService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBriefTemplate extends EditRecord
{
    protected static string $resource = BriefTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BriefTemplateResource::sendToProjectAction(),
            Actions\DeleteAction::make()
                ->label(fn () => $this->getRecord()->isFork() ? 'Sistem versiyasına qayıt' : 'Sil')
                ->requiresConfirmation()
                ->modalDescription(fn () => BriefTemplateResource::deleteDescription($this->getRecord())),
        ];
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record->isFork()) {
            return null;
        }

        return 'Bu, «'.($record->forkedFrom?->getTranslation('name', 'az') ?? 'sistem şablonu')
            .'» şablonunun studiyanıza məxsus versiyasıdır. Dəyişikliklər yalnız sizin studiyaya aiddir; orijinal şablonla göndərilmiş briflərə təsir etmir.';
    }

    /** Tərcümə massivi formada düz mətndir. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['title'] = $this->getRecord()->getTranslation('name', 'az');
        $data['summary'] = $this->getRecord()->getTranslation('description', 'az') ?: null;

        return $data;
    }

    /** Ad dəyişəndə bölmənin adı da dəyişir — servisdə bir yerdə. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(BriefBuilderService::class)->renameTemplate($record, (string) $data['title'], $data['summary'] ?? null);

        $record->forceFill(['active' => (bool) ($data['active'] ?? true)])->save();

        return $record->refresh();
    }
}
