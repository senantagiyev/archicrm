@php
    $locale = app()->getLocale();
    $completed = $brief->isCompleted();
    $answers = $brief->answers->keyBy(fn ($a) => $a->brief_question_id.':'.($a->brief_room_id ?? 0));
    // Part 10 №12 / Risk R5 — the one conflict the client is asked to resolve here.
    $curtainsConflict = ($values['curtains_type'] ?? null) === 'none' && filled($values['curtains_blackout_location'] ?? null);
    $canSend = $missing->isEmpty() && $consented && ! $completed;
@endphp

<x-portal.shell :title="t('portal.brief_summary')" :project="$project" active="brief">
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('portal.brief', $project) }}" class="text-[13px] font-semibold text-black/50 hover:text-ink">← {{ t('portal.brief_back_to_map') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ t('portal.brief_summary') }}</h1>
        <p class="mt-1 text-sm text-black/50">{{ t('portal.brief_summary_intro') }}</p>

        @if ($errors->any())
            <div class="mt-5 rounded-ds-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm font-medium text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- «Zəhmət olmasa yoxlayın» — doldurulmamış məcburi sahələr --}}
        @if ($missing->isNotEmpty())
            <div class="mt-6 rounded-ds-md border border-warn/40 bg-warn-soft p-5">
                <h2 class="mb-3 text-sm font-bold text-warn">{{ t('portal.brief_check_please') }} ({{ $missing->count() }})</h2>
                <ul class="space-y-1.5">
                    @foreach ($missing as $item)
                        <li class="text-[13px]">
                            <a class="font-semibold underline"
                                href="{{ route('portal.brief.section', array_filter([$project->id, $item['section']->id, $item['room']?->id])) }}">
                                {{ $item['room']?->label ?? $item['section']->getTranslation('name', $locale) }}
                            </a>
                            <span class="text-black/60"> — {{ $item['question']->getTranslation('label', $locale) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($curtainsConflict)
            <div class="mt-4 rounded-ds-md border border-warn/40 bg-warn-soft px-4 py-3 text-[13px] font-medium text-warn">
                {{ t('portal.brief_conflict_curtains') }}
            </div>
        @endif

        {{-- Bölmə kartları: əsas cavablar + «Dəyiş» --}}
        <div class="mt-6 space-y-3">
            @foreach ($map as $entry)
                @php $section = $entry['section']; @endphp
                <div class="rounded-ds-md border border-black/10 bg-white p-5">
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <h2 class="text-[15px] font-bold">{{ $entry['room']?->label ?? $section->getTranslation('name', $locale) }}</h2>
                        <a href="{{ route('portal.brief.section', array_filter([$project->id, $section->id, $entry['room']?->id])) }}"
                            class="shrink-0 text-[12px] font-semibold text-black/50 underline hover:text-ink">{{ t('portal.brief_edit') }}</a>
                    </div>
                    <dl class="space-y-1.5">
                        @foreach ($section->questions->filter(fn ($q) => $q->shouldShow($entry['values'])) as $question)
                            @php $answer = $answers->get($question->id.':'.($entry['room']->id ?? 0)); @endphp
                            @continue (! $answer?->isAnswered())
                            <div class="flex gap-3 text-[13px]">
                                <dt class="w-1/2 shrink-0 text-black/50">{{ $question->getTranslation('label', $locale) }}</dt>
                                <dd class="font-semibold">
                                    @if ($answer->delegated_to_designer)
                                        <span class="text-black/40">{{ t('portal.brief_delegate') }}</span>
                                    @else
                                        {{ $question->displayValue($answer->value) ?: '—' }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                        @if ($entry['answered_count'] === 0)
                            <p class="text-[13px] text-black/35">{{ t('portal.brief_nothing_filled') }}</p>
                        @endif
                    </dl>
                </div>
            @endforeach
        </div>

        {{-- Göndərmə --}}
        <div class="mt-6 rounded-ds-md border border-black/10 bg-white p-5">
            @if ($completed)
                <p class="text-sm font-semibold text-ok">{{ t('portal.brief_completed_note') }}</p>
            @else
                @unless ($consented)
                    <p class="mb-3 text-[13px] font-medium text-danger">{{ t('portal.brief_consent_required') }}</p>
                @endunless
                <form method="post" action="{{ route('portal.brief.send', $project) }}">
                    @csrf
                    <button type="submit" @disabled(! $canSend)
                        class="ui-btn h-12 w-full text-sm font-bold {{ $canSend ? 'ui-btn-primary' : 'cursor-not-allowed bg-neutral-soft text-black/35' }}"
                        data-hover="{{ $canSend ? 'true' : 'false' }}">
                        {{ t('portal.brief_send') }}
                    </button>
                </form>
                @if ($missing->isNotEmpty())
                    <p class="mt-2 text-center text-[12px] text-black/50">
                        {{ t('portal.brief_required_missing', ['count' => $missing->count()]) }}
                    </p>
                @endif
            @endif
        </div>
    </div>
</x-portal.shell>
