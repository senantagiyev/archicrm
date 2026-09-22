<x-portal.shell :title="t('portal.nav_documents')" active="documents">
    @php
        $icons = [
            'contract'     => 'M4 4h10l6 6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2ZM14 4v6h6M8 16c1.5-2 3-2 4 0s2.5 2 4 0',
            'act'          => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5M9 14l2 2 4-4',
            'brief_export' => 'M9 3h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2V4a1 1 0 0 1 1-1ZM9 12h6M9 16h4',
            'drawing'      => 'm12 3 9 5-9 5-9-5 9-5ZM3 13l9 5 9-5M3 17l9 5 9-5',
            'other'        => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5',
        ];
    @endphp

    <h1 class="mb-1 font-b2b text-[26px] font-extrabold tracking-tight">{{ t('portal.nav_documents') }}</h1>
    <p class="mb-6 text-[14px] text-black/50">{{ t('portal.documents_all_intro') }}</p>

    @forelse ($documents as $document)
        <a href="{{ route('portal.documents.download', [$document->project_id, $document]) }}"
           class="group mb-3 flex items-center gap-4 rounded-[16px] border border-black/8 bg-card px-5 py-4 transition-all last:mb-0
                  hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_10px_30px_-16px_rgba(0,0,0,.25)]">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-neutral-soft text-ink transition-colors group-hover:bg-sel-bg">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="{{ $icons[$document->type->value] ?? $icons['other'] }}"/>
                </svg>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-[15px] font-bold">{{ $document->title }}</p>
                <p class="mt-0.5 truncate text-[13px] text-black/45">
                    {{ $document->project?->name ?? '—' }}
                    · {{ $document->type->label() }} · {{ $document->created_at->format('d.m.Y') }}
                    @if ($document->size)
                        · {{ number_format($document->size / 1024, 0, '.', ' ') }} KB
                    @endif
                </p>
            </div>

            <span class="hidden shrink-0 rounded-pill bg-neutral-soft px-3 py-1 text-[13px] font-semibold text-black/55 sm:inline-block">
                {{ t('portal.download') }}
            </span>
            <span class="shrink-0 text-black/30 transition-colors group-hover:text-ink" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </span>
        </a>
    @empty
        <div class="rounded-[16px] border border-black/8 bg-card px-5 py-12 text-center">
            <p class="text-[14px] text-black/40">{{ t('portal.no_documents') }}</p>
        </div>
    @endforelse
</x-portal.shell>
