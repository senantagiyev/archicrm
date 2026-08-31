@php
    $locale = app()->getLocale();
    $sectionTitle = $room?->label ?? $section->getTranslation('name', $locale);
    $completed = $brief->isCompleted();
@endphp

<x-portal.shell :title="$sectionTitle" :project="$project" active="brief">
    <div class="flex gap-8">
        {{-- Side map: jump to any section (TZ: breadcrumb/progress navigation) --}}
        <aside class="hidden w-60 shrink-0 lg:block">
            <div class="sticky top-6 space-y-1">
                @foreach ($map as $entry)
                    @php $isCurrent = $entry['section']->id === $section->id && ($entry['room']?->id === $room?->id); @endphp
                    <a href="{{ route('portal.brief.section', array_filter([$project->id, $entry['section']->id, $entry['room']?->id])) }}"
                        class="flex items-center justify-between rounded-ds px-3 py-2 text-[13px] {{ $isCurrent ? 'bg-ink font-bold text-white' : 'font-medium text-black/60 hover:bg-white' }}">
                        <span class="truncate">{{ $entry['room']?->label ?? $entry['section']->getTranslation('name', $locale) }}</span>
                        <span class="{{ $isCurrent ? 'text-yellow' : ($entry['status'] === 'submitted' ? 'text-ok' : 'text-black/40') }}">{{ $entry['progress'] }}%</span>
                    </a>
                @endforeach
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <div class="mb-6 flex items-center justify-between gap-4">
                <div>
                    <a href="{{ route('portal.brief', $project) }}" class="text-[13px] font-semibold text-black/50 hover:text-ink">← {{ t('portal.brief_back_to_map') }}</a>
                    <h1 class="mt-1 text-2xl font-bold">{{ $sectionTitle }}</h1>
                </div>
                <span id="saveState" class="text-[12px] font-medium text-black/40"></span>
            </div>

            <form id="briefForm" method="post"
                action="{{ route('portal.brief.submit', [$project->id, $section->id]) }}"
                data-autosave-url="{{ route('portal.brief.autosave', [$project->id, $section->id]) }}"
                data-room-id="{{ $room?->id }}"
                class="space-y-5">
                @csrf
                <input type="hidden" name="room_id" value="{{ $room?->id }}">

                @foreach ($section->questions as $question)
                    @php
                        $answer = $answers->get($question->id);
                        $delegated = $answer?->delegated_to_designer ?? false;
                        $value = $answer?->value;
                    @endphp
                    <div class="rounded-ds-md border border-black/10 bg-white p-5" data-question="{{ $question->id }}">
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <label class="text-sm font-bold">
                                {{ $question->getTranslation('label', $locale) }}
                                @if ($question->is_required)<span class="text-danger">*</span>@endif
                            </label>
                            @if ($question->allows_designer_choice && ! $completed)
                                <label class="flex shrink-0 cursor-pointer items-center gap-2 text-[12px] font-semibold text-black/50">
                                    <input type="checkbox" data-delegate {{ $delegated ? 'checked' : '' }} class="accent-ink">
                                    {{ t('portal.brief_delegate') }}
                                </label>
                            @endif
                        </div>

                        @if ($question->getTranslation('help', $locale))
                            <p class="mb-3 rounded-ds bg-sel-bg px-3 py-2 text-[12px] text-black/60">{{ $question->getTranslation('help', $locale) }}</p>
                        @endif

                        <div data-input-zone class="{{ $delegated ? 'pointer-events-none opacity-40' : '' }}">
                            @switch($question->type)
                                @case('textarea')
                                    <textarea data-field rows="3" {{ $completed ? 'disabled' : '' }}
                                        class="w-full rounded-ds border border-black/20 px-3.5 py-2.5 text-sm outline-none focus:border-ink">{{ is_array($value) ? implode("\n", $value) : $value }}</textarea>
                                    @break
                                @case('select')
                                    <div class="flex flex-wrap gap-2" data-single-choice>
                                        @foreach ($question->options ?? [] as $option)
                                            <button type="button" data-choice value="{{ $option['value'] }}" {{ $completed ? 'disabled' : '' }}
                                                class="rounded-pill border px-4 py-2 text-[13px] font-semibold transition-colors
                                                    {{ $value === $option['value'] ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">
                                                {{ $option['label'][$locale] ?? $option['label']['az'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                    @break
                                @case('multiselect')
                                    <div class="flex flex-wrap gap-2" data-multi-choice>
                                        @foreach ($question->options ?? [] as $option)
                                            <button type="button" data-choice value="{{ $option['value'] }}" {{ $completed ? 'disabled' : '' }}
                                                class="rounded-pill border px-4 py-2 text-[13px] font-semibold transition-colors
                                                    {{ in_array($option['value'], (array) $value, true) ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">
                                                {{ $option['label'][$locale] ?? $option['label']['az'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                    @break
                                @case('boolean')
                                    <div class="flex gap-2" data-single-choice>
                                        <button type="button" data-choice value="1" {{ $completed ? 'disabled' : '' }}
                                            class="rounded-pill border px-5 py-2 text-[13px] font-semibold {{ $value === '1' || $value === true ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">{{ t('portal.yes') }}</button>
                                        <button type="button" data-choice value="0" {{ $completed ? 'disabled' : '' }}
                                            class="rounded-pill border px-5 py-2 text-[13px] font-semibold {{ $value === '0' || $value === false ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">{{ t('portal.no') }}</button>
                                    </div>
                                    @break
                                @case('number')
                                    <input data-field type="number" value="{{ $value }}" {{ $completed ? 'disabled' : '' }}
                                        class="h-11 w-40 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                    @break
                                @default
                                    <input data-field type="text" value="{{ $value }}" {{ $completed ? 'disabled' : '' }}
                                        class="h-11 w-full rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                            @endswitch
                        </div>
                    </div>
                @endforeach

                @unless ($completed)
                    <div class="flex items-center justify-between gap-4">
                        <a href="{{ route('portal.brief', $project) }}" class="ui-btn ui-btn-outline h-11 px-5 text-sm font-semibold" data-hover="true">
                            {{ t('portal.brief_save_exit') }}
                        </a>
                        <button type="submit" class="ui-btn ui-btn-primary h-11 px-6 text-sm font-bold" data-hover="true">
                            {{ t('portal.brief_submit_section') }}
                        </button>
                    </div>
                @endunless
            </form>
        </div>
    </div>

    @unless ($completed)
    <script>
        (() => {
            const form = document.getElementById('briefForm');
            const url = form.dataset.autosaveUrl;
            const roomId = form.dataset.roomId || null;
            const csrf = form.querySelector('input[name="_token"]').value;
            const saveState = document.getElementById('saveState');
            const timers = {};

            const send = (questionId, value, delegated) => {
                fetch(url, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ question_id: questionId, value, delegated, room_id: roomId }),
                })
                .then(r => r.json())
                .then(d => { if (d.ok) saveState.textContent = @json(t('portal.brief_saved')) + ' ' + d.saved_at; })
                .catch(() => { saveState.textContent = @json(t('portal.brief_save_error')); });
            };

            const collect = (block) => {
                const multi = block.querySelector('[data-multi-choice]');
                if (multi) {
                    return [...multi.querySelectorAll('[data-choice].bg-ink')].map(b => b.getAttribute('value'));
                }
                const single = block.querySelector('[data-single-choice]');
                if (single) {
                    return single.querySelector('[data-choice].bg-ink')?.getAttribute('value') ?? null;
                }
                return block.querySelector('[data-field]')?.value ?? null;
            };

            document.querySelectorAll('[data-question]').forEach(block => {
                const id = block.dataset.question;
                const delegate = block.querySelector('[data-delegate]');
                const zone = block.querySelector('[data-input-zone]');

                block.querySelectorAll('[data-field]').forEach(field => {
                    field.addEventListener('input', () => {
                        clearTimeout(timers[id]);
                        timers[id] = setTimeout(() => send(id, collect(block), delegate?.checked ?? false), 800);
                    });
                });

                block.querySelectorAll('[data-choice]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const group = btn.closest('[data-multi-choice], [data-single-choice]');
                        const active = ['border-ink', 'bg-ink', 'text-white'];
                        const inactive = ['border-black/20', 'bg-white'];

                        if (group.hasAttribute('data-single-choice')) {
                            group.querySelectorAll('[data-choice]').forEach(b => { b.classList.remove(...active); b.classList.add(...inactive); });
                            btn.classList.remove(...inactive); btn.classList.add(...active);
                        } else {
                            const isActive = btn.classList.contains('bg-ink');
                            btn.classList.toggle('border-ink', !isActive);
                            btn.classList.toggle('bg-ink', !isActive);
                            btn.classList.toggle('text-white', !isActive);
                            btn.classList.toggle('border-black/20', isActive);
                            btn.classList.toggle('bg-white', isActive);
                        }

                        send(id, collect(block), delegate?.checked ?? false);
                    });
                });

                delegate?.addEventListener('change', () => {
                    zone.classList.toggle('pointer-events-none', delegate.checked);
                    zone.classList.toggle('opacity-40', delegate.checked);
                    send(id, delegate.checked ? null : collect(block), delegate.checked);
                });
            });
        })();
    </script>
    @endunless
</x-portal.shell>
