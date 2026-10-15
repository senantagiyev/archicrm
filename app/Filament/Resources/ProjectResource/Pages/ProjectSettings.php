<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\RelationManagers\MembersRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * «Layihə parametrləri» (TZ «Layihə naviqasiyası» §2): layihənin geniş
 * redaktə forması və komanda tərkibinin idarə edilməsi.
 *
 * Əvvəl forma layihə kartının hər tabının yuxarısını tuturdu; indi kart
 * (EditProject) İcmal + bölmələrdir, parametrlər isə buradadır. Səlahiyyət
 * EditProject ilə eynidir — `ProjectPolicy::update` (EditRecord::authorizeAccess).
 */
class ProjectSettings extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected static ?string $title = 'Layihə parametrləri';

    protected static ?string $breadcrumb = 'Parametrlər';

    /** Bu səhifədə yalnız komanda — qalan bölmələr layihə kartındadır. */
    protected function getAllRelationManagers(): array
    {
        return [MembersRelationManager::class];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getRecordTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('overview')
                ->label('Layihə kartına qayıt')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('edit', ['record' => $this->record])),

            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Layihəni sil')
                ->modalDescription('Layihə bütün mərhələləri və tapşırıqları ilə birlikdə silinəcək. Bu kritik əməliyyatdır və əməliyyat jurnalında qeyd olunur.'),
        ];
    }

    protected function getRedirectUrl(): ?string
    {
        return ProjectResource::getUrl('settings', ['record' => $this->record]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Layihə parametrləri yadda saxlanıldı';
    }
}
