@php
    $locale = app()->getLocale();
@endphp

{{-- Layihəyə bir neçə brif göndərilib — hər biri ayrıca doldurulur və göndərilir. --}}
<x-portal.shell :title="t('portal.nav_brief')" :project="$project" active="brief">
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ t('portal.nav_brief') }}</h1>
        <p class="mt-1 text-sm text-black/50">{{ t('portal.brief_list_intro', ['count' => $briefs->count()]) }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($briefs as $item)
            @php
                $status = $item->statusEnum();
                $description = $item->template?->getTranslation('description', $locale, true);
            @endphp
            <a href="{{ route('portal.brief', [$project, 'brief' => $item->id]) }}"
                class="group flex flex-col rounded-ds-md border border-black/10 bg-card p-5 transition-all hover:-translate-y-0.5 hover:border-black/30 hover:shadow-[0_10px_30px_-18px_rgba(0,0,0,.3)]"
                data-brief-card="{{ $item->id }}">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <span class="text-[13px] font-semibold text-black/40">
                        {{ t('portal.brief_presented_on', ['date' => $item->presented_at->format('d.m.Y')]) }}
                    </span>
                    @if ($item->needsClarification())
                        <span class="rounded-pill bg-warn-soft px-2.5 py-1 text-[13px] font-medium text-warn">{{ t('portal.brief_needs_clar_title') }}</span>
                    @elseif ($item->isLocked())
                        <span class="rounded-pill bg-ok-soft px-2.5 py-1 text-[13px] font-medium text-ok">{{ t('portal.brief_submitted') }}</span>
                    @elseif ((int) $item->progress > 0)
                        <span class="rounded-pill bg-warn-soft px-2.5 py-1 text-[13px] font-medium text-warn">{{ t('portal.brief_in_progress') }}</span>
                    @else
                        <span class="rounded-pill bg-neutral-soft px-2.5 py-1 text-[13px] font-medium text-black/45">{{ t('portal.brief_not_started') }}</span>
                    @endif
                </div>
                <h2 class="text-[16px] font-semibold group-hover:underline">
                    {{ $item->template?->getTranslation('name', $locale, true) ?? t('portal.nav_brief') }}
                </h2>
                @if (filled($description))
                    <p class="mt-1 line-clamp-2 text-[13px] leading-relaxed text-black/50">{{ $description }}</p>
                @endif
                <div class="mt-auto pt-4">
                    <div class="mb-1.5 flex items-center justify-between text-[13px] text-black/50">
                        <span>{{ t('portal.brief_total_progress') }}</span>
                        <span class="font-semibold text-ink">{{ (int) $item->progress }}%</span>
                    </div>
                    <div class="h-1 overflow-hidden rounded-pill bg-neutral-soft">
                        <div class="h-full {{ $item->isLocked() ? 'bg-ok' : 'bg-yellow-line' }}" style="width: {{ (int) $item->progress }}%"></div>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
</x-portal.shell>
