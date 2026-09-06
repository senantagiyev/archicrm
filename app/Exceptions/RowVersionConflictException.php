<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Optimistic-concurrency conflict (TZ §11.3): the row changed in the database
 * after this model instance was loaded, so saving would silently overwrite
 * someone else's edit. Over HTTP it renders as 409 with {current_version,
 * your_version}; in Filament the edit surface catches it and asks the user to
 * reload (see App\Filament\Concerns\OptimisticLock).
 */
class RowVersionConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $currentVersion,
        public readonly int $yourVersion,
    ) {
        parent::__construct('Bu qeyd siz baxarkən başqası tərəfindən dəyişdirilib. Zəhmət olmasa səhifəni yeniləyib yenidən cəhd edin.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current_version' => $this->currentVersion,
            'your_version' => $this->yourVersion,
        ], 409);
    }
}
