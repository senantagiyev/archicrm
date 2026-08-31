<x-portal.shell :title="t('portal.my_projects')">
    <h1 class="mb-6 text-2xl font-bold">{{ t('portal.my_projects') }}</h1>

    @if ($projects->isEmpty())
        <div class="rounded-ds-md border border-black/10 bg-white p-10 text-center text-black/50">
            {{ t('portal.no_projects') }}
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($projects as $project)
                <a href="{{ route('portal.projects.show', $project) }}"
                    class="group rounded-ds-md border border-black/10 bg-white p-6 transition-colors hover:border-black/30">
                    <div class="mb-2 flex items-center justify-between">
                        <h2 class="text-lg font-bold group-hover:underline">{{ $project->name }}</h2>
                        <span class="rounded-pill bg-neutral-soft px-3 py-1 text-[12px] font-semibold">{{ $project->type->translatedLabel() }}</span>
                    </div>
                    <p class="mb-4 text-sm text-black/50">{{ $project->address }}</p>
                    <div class="mb-1.5 flex items-center justify-between text-[13px]">
                        <span class="font-medium text-black/60">{{ t('portal.readiness') }}</span>
                        <span class="font-bold">{{ $project->readiness }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-pill bg-neutral-soft">
                        <div class="h-full bg-yellow-line" style="width: {{ $project->readiness }}%"></div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-portal.shell>
