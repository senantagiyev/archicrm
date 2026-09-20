<x-portal.shell :title="t('portal.my_projects')">
    <h1 class="mb-1.5 font-b2b text-heading font-semibold tracking-normal">{{ t('portal.my_projects') }}</h1>
    <p class="mb-7 text-body text-black/55">Layihələrinizin gedişatını buradan izləyin.</p>

    @if ($projects->isEmpty())
        <div class="rounded-ds-xl border border-black/8 bg-white p-12 text-center text-body text-black/55">
            {{ t('portal.no_projects') }}
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($projects as $project)
                <a href="{{ route('portal.projects.show', $project) }}"
                    class="group rounded-ds-xl border border-black/8 bg-white p-6 transition-all hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_12px_34px_-18px_rgba(0,0,0,.28)]">
                    <div class="mb-3.5 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-body-lg font-semibold">{{ $project->name }}</h2>
                            <p class="mt-1 truncate text-helper text-black/55">{{ $project->address ?: '—' }}</p>
                        </div>
                        <span class="shrink-0 rounded-pill bg-neutral-soft px-3 py-1 text-helper font-medium text-black/70">{{ $project->type->translatedLabel() }}</span>
                    </div>
                    <div class="mb-2 flex items-center justify-between text-helper">
                        <span class="font-medium text-black/60">{{ t('portal.readiness') }}</span>
                        <span class="text-body font-semibold">{{ $project->readiness }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-pill bg-neutral-soft">
                        <div class="h-full rounded-pill bg-yellow-line" style="width: {{ $project->readiness }}%"></div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-portal.shell>
