<x-portal.shell :title="t('portal.nav_documents')" :project="$project" active="documents">
    @php
        // Sənəd tipinə görə ikon — siyahı «boz sətirlər» yığını yox, tanınan
        // kartlar dəsti kimi oxunsun deyə. Yalnız mövcud tip məlumatından
        // istifadə olunur: Archi-də sənədin status sahəsi yoxdur, ona görə
        // Roomix-dəki «Не начат / Заполняется» çipinin qarşılığı da yoxdur.
        $icons = [
            'contract'     => 'M4 4h10l6 6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2ZM14 4v6h6M8 16c1.5-2 3-2 4 0s2.5 2 4 0',
            'act'          => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5M9 14l2 2 4-4',
            'brief_export' => 'M9 3h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2V4a1 1 0 0 1 1-1ZM9 12h6M9 16h4',
            'technical_spec' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5M9 13l2 2 4-4',
            'drawing'      => 'm12 3 9 5-9 5-9-5 9-5ZM3 13l9 5 9-5M3 17l9 5 9-5',
            'other'        => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5',
        ];
    @endphp

    <h1 class="mb-5 text-heading font-semibold">{{ t('portal.nav_documents') }}</h1>

    @forelse ($documents as $document)
        <a href="{{ route('portal.documents.download', [$project, $document]) }}"
           class="group mb-3 flex items-center gap-4 rounded-ds-xl border border-black/8 bg-card px-5 py-4 transition-all last:mb-0
                  hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_10px_30px_-16px_rgba(0,0,0,.25)]">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-ink transition-colors group-hover:bg-sel-bg">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="{{ $icons[$document->type->value] ?? $icons['other'] }}"/>
                </svg>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-body font-semibold">{{ $document->title }}</p>
                <p class="mt-1 text-helper text-black/55">
                    {{ $document->type->label() }} · {{ $document->created_at->format('d.m.Y') }}
                    @if ($document->size)
                        · {{ number_format($document->size / 1024, 0, '.', ' ') }} KB
                    @endif
                </p>
            </div>

            <span class="hidden shrink-0 rounded-pill bg-neutral-soft px-3.5 py-1.5 text-helper font-medium text-black/70 sm:inline-block">
                {{ t('portal.download') }}
            </span>
            <span class="shrink-0 text-black/55 transition-colors group-hover:text-ink" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </span>
        </a>
    @empty
    @endforelse

    {{-- Roomix-də smeta və komplektasiya fayl deyil, canlı sənəd səhifəsidir və
         məhz buradan açılır — ona görə onlar tab zolağında deyil, bu siyahıdadır. --}}
    @foreach ($sheets as $sheet)
        <a href="{{ $sheet['url'] }}"
           class="group mb-3 flex items-center gap-4 rounded-ds-xl border border-black/8 bg-card px-5 py-4 transition-all last:mb-0
                  hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_10px_30px_-16px_rgba(0,0,0,.25)]">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-ink transition-colors group-hover:bg-sel-bg">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM9 6h6M9 10h6M9 14h3"/>
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-body font-semibold">{{ $sheet['label'] }}</p>
                <p class="mt-1 text-helper text-black/55">
                    {{ t('portal.sheet_items', ['count' => $sheet['count']]) }}
                    · {{ number_format($sheet['total'], 2, '.', ' ') }} ₼
                </p>
            </div>
            <span class="shrink-0 text-black/45 transition-colors group-hover:text-ink" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </span>
        </a>
    @endforeach

    {{-- Razılaşdırmalar da Roomix-də ayrıca tab deyil — sol paneldə qlobal
         bölmə və çatın içindədir. Layihə daxilində buradan açılır. --}}
    <a href="{{ route('portal.approvals', $project) }}"
       class="group mb-3 flex items-center gap-4 rounded-ds-xl border border-black/8 bg-card px-5 py-4 transition-all last:mb-0
              hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_10px_30px_-16px_rgba(0,0,0,.25)]">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-ink transition-colors group-hover:bg-sel-bg">
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3"/>
            </svg>
        </span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-body font-semibold">{{ t('portal.nav_approvals') }}</p>
        </div>
        @if ($pendingApprovals > 0)
            <span class="shrink-0 rounded-pill bg-yellow px-3 py-1 text-helper font-bold text-ink">
                {{ t('portal.project_your_turn') }} {{ $pendingApprovals }}
            </span>
        @endif
        <span class="shrink-0 text-black/45 transition-colors group-hover:text-ink" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
        </span>
    </a>

    {{-- Roomix: hələ hazır olmayan sənədlər də siyahıda «Не начат» kimi durur —
         müştəri nəyin gözlənildiyini bilir və dizaynerdən soruşmur. --}}
    @foreach ($missing as $type)
        <div class="mb-3 flex items-center gap-4 rounded-ds-xl border border-dashed border-black/15 px-5 py-4 last:mb-0">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-black/30">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="{{ $icons[$type->value] ?? $icons['other'] }}"/>
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-body font-semibold text-black/45">{{ $type->label() }}</p>
            </div>
            <span class="shrink-0 rounded-pill bg-neutral-soft px-3.5 py-1.5 text-helper font-medium text-black/45">
                {{ t('portal.doc_not_started') }}
            </span>
        </div>
    @endforeach

    @if ($documents->isEmpty() && $missing->isEmpty() && $sheets === [])
        <div class="rounded-ds-xl border border-black/8 bg-card px-5 py-12 text-center">
            <p class="text-body text-black/55">{{ t('portal.no_documents') }}</p>
        </div>
    @endif
</x-portal.shell>
