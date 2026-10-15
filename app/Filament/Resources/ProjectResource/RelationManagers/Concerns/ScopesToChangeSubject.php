<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers\Concerns;

use App\Models\Project;
use App\Support\ChangeSubjects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Dəyişiklik sorğuları / qərarlar relation manager-ini BİR obyektə
 * (material, mərhələ, irad) məhdudlaşdırır — `ChangesAndDecisionsAction`
 * həmin RM-i obyektin sətrindən açılan panelə bu xassələrlə yerləşdirir.
 *
 * İş axını (statuslar, keçidlər, portal razılaşdırması) RM-in özündədir və
 * dəyişmir; burada yalnız süzgəc və yaradılan qeydin obyektə bağlanması var.
 * Xassələr `#[Locked]`-dur: brauzerdən başqa obyektə «keçmək» olmaz, obyekt
 * isə hər sorğuda serverdə layihəyə + studiyaya qarşı yenidən yoxlanılır.
 */
trait ScopesToChangeSubject
{
    #[Locked]
    public ?string $subjectType = null;

    #[Locked]
    public ?int $subjectId = null;

    protected ?Model $cachedChangeSubject = null;

    public function mountScopesToChangeSubject(): void
    {
        if (! $this->isScopedToSubject()) {
            return;
        }

        // Səhifədə RM-i Filament özü yoxlayır; panelə yerləşdirilən nüsxə isə
        // birbaşa mount olunur — eyni yoxlama burada təkrarlanır.
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        $subject = $this->changeSubject();

        abort_if($subject === null, 404);
        abort_unless(auth()->user()?->can('view', $subject), 403);
    }

    public function isScopedToSubject(): bool
    {
        return $this->subjectType !== null || $this->subjectId !== null;
    }

    public function changeSubject(): ?Model
    {
        if (! $this->isScopedToSubject()) {
            return null;
        }

        $owner = $this->getOwnerRecord();

        return $this->cachedChangeSubject ??= $owner instanceof Project
            ? ChangeSubjects::resolve($owner, $this->subjectType, $this->subjectId)
            : null;
    }

    /** Cədvəl sorğusu: panel rejimində yalnız bu obyektin qeydləri. */
    protected function scopeToChangeSubject(Builder $query): Builder
    {
        if (! $this->isScopedToSubject()) {
            return $query;
        }

        $subject = $this->changeSubject();

        if ($subject === null) {
            // Obyekt yoxa çıxıbsa (silinib) heç nə göstərilmir — layihənin
            // BÜTÜN qeydləri əvəzinə.
            return $query->whereRaw('1 = 0');
        }

        [$typeColumn, $idColumn] = ChangeSubjects::columnsFor($query->getModel());

        return $query
            ->where($query->qualifyColumn($typeColumn), $subject->getMorphClass())
            ->where($query->qualifyColumn($idColumn), $subject->getKey());
    }

    /**
     * Yaradılan qeyd panelin obyektinə bağlanır — istifadəçi obyekti yenidən
     * seçmir, brauzerdən gələn dəyərə də güvənilmir.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withChangeSubject(array $data, string $modelClass): array
    {
        [$typeColumn, $idColumn] = ChangeSubjects::columnsFor($modelClass);

        unset($data[$typeColumn], $data[$idColumn]);

        $subject = $this->changeSubject();

        if ($subject !== null) {
            $data[$typeColumn] = $subject->getMorphClass();
            $data[$idColumn] = $subject->getKey();
        }

        return $data;
    }

    protected function changeSubjectDescription(): ?string
    {
        return ChangeSubjects::describe($this->changeSubject());
    }
}
