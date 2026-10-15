<?php

namespace App\Filament\Concerns;

use App\Models\Project;
use App\Support\AccessMatrix;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;

/**
 * Layihəyə bağlı maliyyə resursları (Ödənişlər, Smeta, Müqavilələr) üçün
 * «yalnız öz layihələri» süzgəci — `InvoiceResource::getEloquentQuery()` ilə
 * eyni qayda. Policy hər qeydi ayrıca yoxlayır; bu isə siyahının, layihə
 * süzgəcinin və formadakı layihə seçiminin yad layihəni ümumiyyətlə
 * göstərməməsi üçündür. Tenant izolyasiyasını `BelongsToTenant` edir.
 */
trait ScopesToOwnProjects
{
    protected static function scopeToOwnProjects(Builder $query, string $relation = 'project'): Builder
    {
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user)) {
            $query->whereHas($relation, fn (Builder $project) => static::ownProjectConstraint($project, $user->id));
        }

        return $query;
    }

    /** @return array<int, string> */
    public static function projectOptions(): array
    {
        $query = Project::query()->orderBy('name');
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user)) {
            static::ownProjectConstraint($query, $user->id);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Formanın layihəsi: resurs səhifəsində `project_id` sahəsindən, köhnə
     * layihə tabında (relation manager) isə sahib qeyddən.
     */
    protected static function contextProjectId(Get $get, mixed $livewire): ?int
    {
        $projectId = $get('project_id');

        if (blank($projectId) && $livewire instanceof RelationManager) {
            $projectId = $livewire->getOwnerRecord()->getKey();
        }

        return blank($projectId) ? null : (int) $projectId;
    }

    private static function ownProjectConstraint(Builder $project, int $userId): Builder
    {
        return $project->where(fn (Builder $inner) => $inner
            ->where('manager_user_id', $userId)
            ->orWhereHas('members', fn (Builder $member) => $member->whereKey($userId)));
    }
}
