<?php

namespace App\Filament\Actions;

use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Support\ChangeSubjects;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * «Əlaqələndir» — köhnə (obyektə bağlanmamış) dəyişiklik sorğusunu və ya
 * qərarı eyni layihənin materialına, mərhələsinə və ya iradına bağlayır.
 *
 * TZ §10: təyinatı aydın olmayan qeydlər avtomatik bölüşdürülmür — onlar
 * yoxlama siyahısına düşür və bu action ilə ƏLLƏ bağlanır. Seçim serverdə
 * yenidən yoxlanılır (`ChangeSubjects::resolveKey` + `link`): obyekt eyni
 * layihəyə və eyni studiyaya aid deyilsə, heç nə yazılmır.
 *
 * İstifadə: cədvəl sətrində `LinkToSubjectAction::make()`; cədvəldən kənarda
 * `LinkToSubjectAction::make()->record($changeRequest)`.
 */
class LinkToSubjectAction
{
    public static function make(string $name = 'linkToSubject'): Action
    {
        return Action::make($name)
            ->label(fn (?Model $record) => self::isLinked($record) ? 'Əlaqəni dəyiş' : 'Əlaqələndir')
            ->icon('heroicon-o-link')
            ->color(fn (?Model $record) => self::isLinked($record) ? 'gray' : 'warning')
            ->visible(fn (?Model $record) => ($record instanceof ChangeRequest || $record instanceof ProjectDecision)
                && auth()->user()?->can('update', $record))
            ->modalHeading(fn (?Model $record) => $record instanceof ChangeRequest
                ? 'Dəyişiklik sorğusunu obyektə bağla'
                : 'Qərarı obyektə bağla')
            ->modalDescription('Qeyd seçilmiş materialın, mərhələnin və ya iradın daxilində görünəcək. Yalnız bu layihənin obyektləri seçilə bilər.')
            ->modalSubmitActionLabel('Əlaqələndir')
            ->fillForm(fn (Model $record) => [
                'subject' => self::isLinked($record) && ($subject = self::currentSubject($record)) !== null
                    ? ChangeSubjects::key($subject)
                    : null,
            ])
            ->schema(fn (Model $record) => [
                Select::make('subject')
                    ->label('Obyekt')
                    ->options(fn () => self::project($record) ? ChangeSubjects::options(self::project($record)) : [])
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->action(function (Model $record, array $data, Action $action): void {
                $project = self::project($record);
                $subject = $project ? ChangeSubjects::resolveKey($project, $data['subject'] ?? null) : null;

                if ($subject === null || ! auth()->user()?->can('view', $subject)) {
                    Notification::make()
                        ->danger()
                        ->title('Əlaqələndirilmədi')
                        ->body('Seçilmiş obyekt bu layihəyə aid deyil və ya ona giriş yoxdur.')
                        ->send();

                    $action->halt();

                    return;
                }

                ChangeSubjects::link($record, $subject);
            })
            ->successNotificationTitle('Əlaqələndirildi');
    }

    private static function isLinked(?Model $record): bool
    {
        if (! $record instanceof ChangeRequest && ! $record instanceof ProjectDecision) {
            return false;
        }

        [$typeColumn, $idColumn] = ChangeSubjects::columnsFor($record);

        return filled($record->getAttribute($typeColumn)) && filled($record->getAttribute($idColumn));
    }

    private static function currentSubject(Model $record): ?Model
    {
        $project = self::project($record);

        if ($project === null) {
            return null;
        }

        [$typeColumn, $idColumn] = ChangeSubjects::columnsFor($record);

        return ChangeSubjects::resolve($project, $record->getAttribute($typeColumn), $record->getAttribute($idColumn));
    }

    private static function project(Model $record): ?Project
    {
        $project = $record->relationLoaded('project')
            ? $record->getRelation('project')
            : Project::query()->find($record->getAttribute('project_id'));

        return $project instanceof Project ? $project : null;
    }
}
