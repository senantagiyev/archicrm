<x-portal.shell :title="t('portal.nav_files')" :project="$project" active="files">
    @php
        // Roomix-dəki çip sırası. `all` həmişə birincidir ki, müştəri filtrdən
        // asanlıqla geri qayıtsın. Enum tam adı ilə yazılır: `use` ifadəsi
        // komponent slotunun içində PHP səviyyəsində qanunsuzdur.
        $chips = [
            'all' => [t('portal.files_all'), null],
            'media' => [t('portal.files_media'), $counts['media'] ?? 0],
            'files' => [t('portal.files_files'), $counts['files'] ?? 0],
            'links' => [t('portal.files_links'), $counts['links'] ?? 0],
            'docs' => [t('portal.files_docs'), $counts['docs'] ?? 0],
        ];

        $icons = [
            'image' => 'M3 3h18v18H3zM3 15l5-5 4 4 3-3 6 6',
            'visualization' => 'M3 3h18v18H3zM3 15l5-5 4 4 3-3 6 6',
            'plan' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5',
            'link' => 'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1',
            'other' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z',
        ];
    @endphp

    <h1 class="mb-4 text-heading font-semibold">{{ t('portal.nav_files') }}</h1>

    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($chips as $key => [$label, $count])
            @php $on = $filter === $key; @endphp
            <a href="{{ $key === 'all' ? route('portal.files', $project) : route('portal.files', [$project, 'filter' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="flex items-center gap-1.5 rounded-pill border px-3.5 py-1.5 text-helper font-medium transition-colors
                      {{ $on ? 'border-ink bg-accent-dark text-white' : 'border-black/15 bg-card hover:border-black/35' }}">
                {{ $label }}
                @if ($count !== null && $count > 0)
                    <span class="text-[11px] font-bold {{ $on ? 'text-white/70' : 'text-black/45' }}">{{ $count }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-ds-xl border border-black/8 bg-card">
        @forelse ($files as $file)
            <a href="{{ route('portal.files.download', [$project, $file]) }}"
               class="group flex items-center gap-4 border-b border-black/5 px-5 py-4 transition-colors last:border-0 hover:bg-neutral-soft/50">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-ink">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="{{ $icons[$file->category->value] ?? $icons['other'] }}"/>
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-body font-semibold">{{ $file->title }}</p>
                    <p class="mt-1 text-helper text-black/55">
                        {{ $file->category->label() }} · {{ $file->created_at->format('d.m.Y') }}
                        @if ($file->size) · {{ number_format($file->size / 1024, 0, '.', ' ') }} KB @endif
                    </p>
                </div>

                <span class="shrink-0 text-black/45 transition-colors group-hover:text-ink" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 19h16"/></svg>
                </span>
            </a>
        @empty
            <p class="px-5 py-12 text-center text-body text-black/55">{{ t('portal.no_files') }}</p>
        @endforelse
    </div>
</x-portal.shell>
