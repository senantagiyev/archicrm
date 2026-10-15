{{-- «Dəyişikliklər və qərarlar» paneli: mövcud relation manager-lər obyektə
     məhdudlaşdırılmış halda (ScopesToChangeSubject). Açar obyektə görədir —
     başqa sətrin paneli açılanda Livewire köhnə komponenti təkrar işlətmir. --}}
<div class="space-y-6">
    @if ($project && $showChanges)
        @livewire(\App\Filament\Resources\ProjectResource\RelationManagers\ChangeRequestsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => $pageClass,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
        ], key('subject-changes-'.$subjectType.'-'.$subjectId))
    @endif

    @if ($project && $showDecisions)
        @livewire(\App\Filament\Resources\ProjectResource\RelationManagers\DecisionsRelationManager::class, [
            'ownerRecord' => $project,
            'pageClass' => $pageClass,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
        ], key('subject-decisions-'.$subjectType.'-'.$subjectId))
    @endif
</div>
