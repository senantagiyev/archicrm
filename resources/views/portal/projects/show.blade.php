<x-portal.shell :title="$project->name" :project="$project" active="overview">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ $project->name }}</h1>
            <p class="mt-1 text-sm text-black/50">
                {{ $project->type->translatedLabel() }}
                @if ($project->address) · {{ $project->address }} @endif
                @if ($project->area) · {{ rtrim(rtrim(number_format((float) $project->area, 2, '.', ' '), '0'), '.') }} m² @endif
            </p>
        </div>
        @if ($pendingApprovals > 0)
            <a href="{{ route('portal.approvals', $project) }}"
                class="ui-btn ui-btn-primary h-11 px-5 text-sm font-bold" data-hover="true">
                {{ t('portal.pending_approvals', ['count' => $pendingApprovals]) }}
            </a>
        @endif
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-3">
        <div class="rounded-ds-md border border-black/10 bg-white p-5">
            <p class="text-[12px] font-semibold uppercase text-black/40">{{ t('portal.readiness') }}</p>
            <p class="mt-1 text-2xl font-bold">{{ $project->readiness }}%</p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-pill bg-neutral-soft">
                <div class="h-full bg-yellow-line" style="width: {{ $project->readiness }}%"></div>
            </div>
        </div>
        <div class="rounded-ds-md border border-black/10 bg-white p-5">
            <p class="text-[12px] font-semibold uppercase text-black/40">{{ t('portal.deadline') }}</p>
            <p class="mt-1 text-2xl font-bold">{{ $project->deadline?->format('d.m.Y') ?? '—' }}</p>
        </div>
        <div class="rounded-ds-md border border-black/10 bg-white p-5">
            <p class="text-[12px] font-semibold uppercase text-black/40">{{ t('portal.manager') }}</p>
            <p class="mt-1 text-2xl font-bold">{{ $project->manager?->name ?? '—' }}</p>
        </div>
    </div>

    <h2 class="mb-4 text-lg font-bold">{{ t('portal.stages') }}</h2>
    <div class="overflow-hidden rounded-ds-md border border-black/10 bg-white">
        @forelse ($project->stages as $stage)
            <div class="flex items-center gap-4 border-b border-black/5 px-5 py-4 last:border-0">
                <span @class([
                    'inline-block h-2.5 w-2.5 shrink-0 rounded-pill',
                    'bg-ok' => $stage->status === \App\Enums\StageStatus::Done,
                    'bg-yellow-line' => in_array($stage->status, [\App\Enums\StageStatus::InProgress, \App\Enums\StageStatus::Review], true),
                    'bg-danger' => $stage->status === \App\Enums\StageStatus::Overdue,
                    'bg-black/20' => $stage->status === \App\Enums\StageStatus::NotStarted,
                ])></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ $stage->name }}</p>
                    @if ($stage->date_plan_end)
                        <p class="text-[12px] text-black/40">{{ $stage->date_plan_end->format('d.m.Y') }}</p>
                    @endif
                </div>
                <span @class([
                    'rounded-pill px-3 py-1 text-[12px] font-semibold',
                    'bg-ok-soft text-ok' => $stage->status === \App\Enums\StageStatus::Done,
                    'bg-warn-soft text-warn' => in_array($stage->status, [\App\Enums\StageStatus::InProgress, \App\Enums\StageStatus::Review], true),
                    'bg-error-soft text-error' => $stage->status === \App\Enums\StageStatus::Overdue,
                    'bg-neutral-soft text-black/50' => $stage->status === \App\Enums\StageStatus::NotStarted,
                ])>{{ $stage->status->translatedLabel() }}</span>
                <span class="w-12 text-right text-sm font-bold">{{ $stage->readiness }}%</span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-sm text-black/40">{{ t('portal.no_stages') }}</p>
        @endforelse
    </div>
</x-portal.shell>
