@php
    $locale = app()->getLocale();
    $totalMinutes = $map->sum(fn ($e) => $e['section']->estimated_minutes ?? 0);
    $roomsHub = $map->firstWhere(fn ($e) => $e['section']->key === 'rooms_hub');
@endphp

<x-portal.shell :title="t('portal.nav_brief')" :project="$project" active="brief">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ t('portal.nav_brief') }}</h1>
            <p class="mt-1 text-sm text-black/50">{{ t('portal.brief_intro') }}</p>
        </div>
        <div class="min-w-[220px] rounded-ds-md border border-black/10 bg-white px-5 py-3">
            <div class="mb-1.5 flex items-center justify-between text-[13px]">
                <span class="font-semibold text-black/60">{{ t('portal.brief_total_progress') }}</span>
                <span class="font-bold">{{ $brief->progress }}%</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-pill bg-neutral-soft">
                <div class="h-full bg-yellow-line" style="width: {{ $brief->progress }}%"></div>
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-ds-md border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
            {{ session('status') }}
        </div>
    @endif

    @if ($brief->isCompleted())
        {{-- Screen 12 — göndərmənin təsdiqi --}}
        <div class="mb-6 rounded-ds-md border border-ok/30 bg-ok-soft p-6">
            <p class="text-lg font-bold text-ok">✓ {{ t('portal.brief_sent_title') }}</p>
            <p class="mt-1.5 text-sm text-ok/90">{{ t('portal.brief_sent_body') }}</p>
            <p class="mt-1 text-sm text-ok/90">{{ t('portal.brief_completed_note') }}</p>
        </div>
    @else
        {{-- Screen 00 — welcome: dəyər + vaxt qiymətləndirməsi + override ipucu --}}
        <div class="mb-6 rounded-ds-md border border-black/10 bg-white p-5">
            <p class="text-sm font-semibold">{{ t('portal.brief_welcome_title', ['minutes' => $totalMinutes]) }}</p>
            <ul class="mt-2 space-y-1 text-[13px] text-black/60">
                <li>• {{ t('portal.brief_welcome_hint_delegate') }}</li>
                <li>• {{ t('portal.brief_welcome_hint_resume') }}</li>
            </ul>
            @if ($roomsHub && $roomsHub['answered_count'] === 0)
                <a href="{{ route('portal.brief.section', [$project->id, $roomsHub['section']->id]) }}"
                    class="mt-3 inline-block text-[13px] font-semibold underline">{{ t('portal.brief_pick_rooms') }} →</a>
            @endif
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($map as $entry)
            <a href="{{ route('portal.brief.section', array_filter([$project->id, $entry['section']->id, $entry['room']?->id])) }}"
                class="group rounded-ds-md border border-black/10 bg-white p-5 transition-colors hover:border-black/30">
                <div class="mb-3 flex items-start justify-between gap-2">
                    <h2 class="text-[15px] font-bold group-hover:underline">
                        {{ $entry['room']?->label ?? $entry['section']->getTranslation('name', $locale) }}
                    </h2>
                    @if ($entry['status'] === 'submitted')
                        <span class="rounded-pill bg-ok-soft px-2.5 py-0.5 text-[11px] font-semibold text-ok">{{ t('portal.brief_submitted') }}</span>
                    @elseif ($entry['status'] === 'in_progress')
                        <span class="rounded-pill bg-warn-soft px-2.5 py-0.5 text-[11px] font-semibold text-warn">{{ t('portal.brief_in_progress') }}</span>
                    @endif
                </div>
                @if (($entry['section']->estimated_minutes ?? 0) > 0)
                    <p class="mb-2 text-[11px] text-black/40">≈ {{ $entry['section']->estimated_minutes }} {{ t('portal.brief_minutes') }}</p>
                @endif
                <div class="mb-1.5 flex items-center justify-between text-[12px] text-black/50">
                    <span>{{ $entry['answered_count'] }}/{{ $entry['question_count'] }}</span>
                    <span class="font-bold text-ink">{{ $entry['progress'] }}%</span>
                </div>
                <div class="h-1 overflow-hidden rounded-pill bg-neutral-soft">
                    <div class="h-full {{ $entry['status'] === 'submitted' ? 'bg-ok' : 'bg-yellow-line' }}" style="width: {{ $entry['progress'] }}%"></div>
                </div>
            </a>
        @endforeach
    </div>

    <div class="mt-8 flex justify-end">
        <a href="{{ route('portal.brief.summary', $project) }}" class="ui-btn ui-btn-dark h-11 px-6 text-sm font-bold" data-hover="true">
            {{ $brief->isCompleted() ? t('portal.brief_summary') : t('portal.brief_go_summary') }} →
        </a>
    </div>
</x-portal.shell>
