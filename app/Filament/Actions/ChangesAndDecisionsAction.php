<?php

namespace App\Filament\Actions;

use App\Filament\Resources\ProjectResource\Pages\EditProject;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Support\ChangeSubjects;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * «Dəyişikliklər və qərarlar» — material, mərhələ və ya irad sətrindən
 * açılan panel (TZ §8, §9; plan qərarı 8).
 *
 * Panel bu obyektin dəyişiklik sorğularını və qərarlarını göstərir və yeni
 * qeydi avtomatik ona bağlayır. Ayrı məntiq YAZILMIR: panelin içində
 * mövcud `ChangeRequestsRelationManager` və `DecisionsRelationManager`
 * Livewire komponentləri obyektə məhdudlaşdırılmış halda işləyir
 * (`ScopesToChangeSubject`) — statuslar, keçidlər, müştəri portalında
 * razılaşdırma (ApprovalService) eynidir.
 *
 * İstifadə (cədvəl sətri): `ChangesAndDecisionsAction::make()`.
 */
class ChangesAndDecisionsAction
{
    public static function make(string $name = 'changesAndDecisions'): Action
    {
        return Action::make($name)
            ->label('Dəyişikliklər və qərarlar')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (?Model $record) => self::canOpen($record))
            ->slideOver()
            ->modalWidth(Width::FiveExtraLarge)
            ->modalHeading(fn (Model $record) => 'Dəyişikliklər və qərarlar')
            ->modalDescription(fn (Model $record) => ChangeSubjects::describe($record))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Bağla')
            ->modalContent(fn (Model $record) => view('filament.actions.changes-and-decisions', [
                'project' => self::project($record),
                'subjectType' => $record->getMorphClass(),
                'subjectId' => (int) $record->getKey(),
                'pageClass' => EditProject::class,
                'showChanges' => auth()->user()?->can('viewAny', ChangeRequest::class) ?? false,
                'showDecisions' => auth()->user()?->can('viewAny', ProjectDecision::class) ?? false,
            ]));
    }

    private static function canOpen(?Model $record): bool
    {
        $user = auth()->user();

        if ($record === null || $user === null || ! array_key_exists($record->getMorphClass(), ChangeSubjects::TYPES)) {
            return false;
        }

        $project = self::project($record);

        return $project !== null
            && ChangeSubjects::belongsTo($record, $project)
            && $user->can('view', $record)
            && ($user->can('viewAny', ChangeRequest::class) || $user->can('viewAny', ProjectDecision::class));
    }

    private static function project(Model $record): ?Project
    {
        $project = $record->relationLoaded('project')
            ? $record->getRelation('project')
            : Project::query()->find($record->getAttribute('project_id'));

        return $project instanceof Project ? $project : null;
    }
}
