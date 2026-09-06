<x-portal.shell :title="t('portal.my_projects')">
    <h1 class="mb-1 font-b2b text-[26px] font-extrabold tracking-tight">{{ t('portal.my_projects') }}</h1>
    <p class="mb-6 text-sm text-black/50">Layihələrinizin gedişatını buradan izləyin.</p>

    @if ($projects->isEmpty())
        <div class="rounded-[16px] border border-black/8 bg-white p-12 text-center text-black/50">
            {{ t('portal.no_projects') }}
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($projects as $project)
                <a href="{{ route('portal.projects.show', $project) }}"
                    class="group rounded-[16px] border border-black/8 bg-white p-6 transition-all hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_12px_34px_-18px_rgba(0,0,0,.28)]">
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-[17px] font-bold">{{ $project->name }}</h2>
                            <p class="mt-0.5 truncate text-[13px] text-black/45">{{ $project->address ?: '—' }}</p>
                        </div>
                        <span class="shrink-0 rounded-pill bg-neutral-soft px-3 py-1 text-[12px] font-semibold">{{ $project->type->translatedLabel() }}</span>
                    </div>
                    <div class="mb-1.5 flex items-center justify-between text-[13px]">
                        <span class="font-medium text-black/55">{{ t('portal.readiness') }}</span>
                        <span class="font-extrabold">{{ $project->readiness }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-pill bg-neutral-soft">
                        <div class="h-full rounded-pill bg-yellow-line" style="width: {{ $project->readiness }}%"></div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-portal.shell>
