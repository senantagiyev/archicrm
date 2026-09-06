<?php

namespace App\Filament\Concerns;

use App\Exceptions\RowVersionConflictException;
use Illuminate\Database\Eloquent\Model;

/**
 * For EditRecord pages of optimistically-locked resources: catch a save-time
 * version clash (TZ §11.3) and surface it as a Filament notification + Halt
 * rather than a fatal error.
 */
trait HandlesRowVersionConflict
{
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $record->update($data);
        } catch (RowVersionConflictException $e) {
            OptimisticLock::reportConflict($e);
        }

        return $record;
    }
}
