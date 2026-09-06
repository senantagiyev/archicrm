<?php

namespace App\Filament\Concerns;

use App\Exceptions\RowVersionConflictException;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Bridges RowVersionConflictException (TZ §11.3) into a friendly Filament flow:
 * on a version clash the user gets a danger notification asking them to reload,
 * and the action halts instead of throwing a 500. Use the EditRecord trait on
 * standalone Edit pages and updateUsing() on RelationManager / table EditActions.
 */
class OptimisticLock
{
    /** Show the conflict as a notification and stop the action. */
    public static function reportConflict(RowVersionConflictException $e): never
    {
        Notification::make()
            ->danger()
            ->title('Konflikt: qeyd yeniləndi')
            ->body($e->getMessage())
            ->persistent()
            ->send();

        throw new Halt;
    }

    /**
     * Drop-in for EditAction::make()->using(...) on RelationManagers and tables
     * so their inline edits get the same conflict handling as full Edit pages.
     */
    public static function updateUsing(): Closure
    {
        return function (Model $record, array $data): Model {
            try {
                $record->update($data);
            } catch (RowVersionConflictException $e) {
                self::reportConflict($e);
            }

            return $record;
        };
    }
}
