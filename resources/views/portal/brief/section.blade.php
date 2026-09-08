@php
    $locale = app()->getLocale();
    $sectionTitle = $room?->label ?? $section->getTranslation('name', $locale);
    $completed = $brief->isCompleted();
    $optLabel = fn ($option) => $option['label'][$locale] ?? $option['label']['az'] ?? $option['value'];
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
                <a href="{{ route('portal.brief.summary', $project) }}"
                    class="mt-2 flex items-center justify-between rounded-ds border border-black/15 px-3 py-2 text-[13px] font-semibold text-black/70 hover:bg-white">
                    {{ t('portal.brief_summary') }} →
                </a>
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

            @if ($errors->any())
                <div class="mb-5 rounded-ds-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm font-medium text-danger">
                    {{ $errors->first() }}
                </div>
            @endif
            @if (session('status'))
                <div class="mb-5 rounded-ds-md border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
                    {{ session('status') }}
                </div>
            @endif

            <form id="briefForm" method="post"
                action="{{ route('portal.brief.submit', [$project->id, $section->id]) }}"
                data-autosave-url="{{ route('portal.brief.autosave', [$project->id, $section->id]) }}"
                data-upload-url="{{ route('portal.brief.upload', [$project->id, $section->id]) }}"
                data-room-id="{{ $room?->id }}"
                class="space-y-5">
                @csrf
                <input type="hidden" name="room_id" value="{{ $room?->id }}">

                @foreach ($section->questions as $question)
                    @php
                        $answer = $answers->get($question->id);
                        $delegated = $answer?->delegated_to_designer ?? false;
                        $value = $answer?->value;
                        $visible = $question->shouldShow($values);
                    @endphp
                    <div class="rounded-ds-md border border-black/10 bg-white p-5" data-question="{{ $question->id }}"
                        data-key="{{ $question->key }}" data-type="{{ $question->type }}"
                        @unless ($visible) hidden @endunless
                        @if (! empty($question->skip_logic['question'])) data-skip="{{ json_encode($question->skip_logic) }}" @endif>
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
                                                {{ $optLabel($option) }}
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
                                                {{ $optLabel($option) }}
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
                                @case('date')
                                    <input data-field type="date" value="{{ $value }}" {{ $completed ? 'disabled' : '' }}
                                        class="h-11 w-52 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                    @break

                                {{-- Spec Part 9.3: büdcə diapazonu + valyuta --}}
                                @case('budget_range')
                                    <div class="flex flex-wrap items-end gap-3" data-budget>
                                        <div>
                                            <span class="mb-1 block text-[11px] font-semibold text-black/50">{{ t('portal.brief_budget_from') }}</span>
                                            <input data-budget-min type="number" min="0" value="{{ $value['min'] ?? '' }}" {{ $completed ? 'disabled' : '' }}
                                                class="h-11 w-36 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                        </div>
                                        <div>
                                            <span class="mb-1 block text-[11px] font-semibold text-black/50">{{ t('portal.brief_budget_to') }}</span>
                                            <input data-budget-max type="number" min="0" value="{{ $value['max'] ?? '' }}" {{ $completed ? 'disabled' : '' }}
                                                class="h-11 w-36 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                        </div>
                                        <div>
                                            <span class="mb-1 block text-[11px] font-semibold text-black/50">{{ t('portal.brief_currency') }}</span>
                                            <select data-budget-currency {{ $completed ? 'disabled' : '' }}
                                                class="h-11 rounded-ds border border-black/20 px-3 text-sm outline-none focus:border-ink">
                                                @foreach (['AZN', 'USD', 'EUR'] as $cur)
                                                    <option value="{{ $cur }}" @selected(($value['currency'] ?? 'AZN') === $cur)>{{ $cur }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    @break

                                {{-- Spec Part 8.4 / 8.5: 14 təkrarlanan bloku əvəz edən matrislər --}}
                                @case('matrix')
                                    @php
                                        $rows = $question->options['rows'] ?? [];
                                        $columns = $question->options['columns'] ?? [];
                                        $matrix = is_array($value) ? $value : [];
                                    @endphp
                                    <div class="overflow-x-auto" data-matrix>
                                        <table class="w-full min-w-[520px] border-collapse text-[13px]">
                                            <thead>
                                                <tr>
                                                    <th class="w-1/3 border-b border-black/10 px-2 py-2 text-left font-semibold text-black/50"></th>
                                                    @foreach ($columns as $col)
                                                        <th class="border-b border-black/10 px-2 py-2 text-center text-[12px] font-semibold text-black/50">{{ $optLabel($col) }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($rows as $rowOpt)
                                                    <tr data-matrix-row="{{ $rowOpt['value'] }}">
                                                        <td class="border-b border-black/5 px-2 py-2.5 font-semibold">{{ $optLabel($rowOpt) }}</td>
                                                        @foreach ($columns as $col)
                                                            <td class="border-b border-black/5 px-2 py-2.5 text-center">
                                                                <button type="button" data-matrix-cell value="{{ $col['value'] }}" {{ $completed ? 'disabled' : '' }}
                                                                    aria-label="{{ $optLabel($rowOpt) }} — {{ $optLabel($col) }}"
                                                                    class="h-5 w-5 rounded-full border-2 transition-colors
                                                                        {{ ($matrix[$rowOpt['value']] ?? null) === $col['value'] ? 'border-ink bg-ink' : 'border-black/25 bg-white hover:border-black/50' }}"></button>
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    @break

                                {{-- Spec Ə6: rəngin mətnlə deyil, vizual seçimi --}}
                                @case('color_swatch')
                                    @php
                                        $swatches = $question->options['swatches'] ?? [];
                                        $baseMax = (int) ($question->options['base_max'] ?? 4);
                                        $accentMax = (int) ($question->options['accent_max'] ?? 2);
                                        $base = (array) ($value['base'] ?? []);
                                        $accent = (array) ($value['accent'] ?? []);
                                    @endphp
                                    <div data-swatch data-base-max="{{ $baseMax }}" data-accent-max="{{ $accentMax }}">
                                        <div class="mb-3 flex gap-2">
                                            <button type="button" data-swatch-mode="base"
                                                class="rounded-pill border border-ink bg-ink px-4 py-1.5 text-[12px] font-semibold text-white">{{ t('portal.brief_swatch_base') }} ({{ $baseMax }})</button>
                                            <button type="button" data-swatch-mode="accent"
                                                class="rounded-pill border border-black/20 bg-white px-4 py-1.5 text-[12px] font-semibold">{{ t('portal.brief_swatch_accent') }} ({{ $accentMax }})</button>
                                        </div>
                                        <div class="grid grid-cols-8 gap-2">
                                            @foreach ($swatches as $hex)
                                                <button type="button" data-swatch-cell value="{{ $hex }}" {{ $completed ? 'disabled' : '' }}
                                                    data-role="{{ in_array($hex, $accent, true) ? 'accent' : (in_array($hex, $base, true) ? 'base' : '') }}"
                                                    title="{{ $hex }}"
                                                    class="relative aspect-square rounded-ds border-2 {{ in_array($hex, $base, true) || in_array($hex, $accent, true) ? 'border-ink' : 'border-black/10' }}"
                                                    style="background-color: {{ $hex }}">
                                                    <span data-swatch-tag class="absolute inset-x-0 bottom-0 bg-ink/80 text-[9px] font-bold uppercase text-white">{{ in_array($hex, $accent, true) ? 'A' : (in_array($hex, $base, true) ? 'F' : '') }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    @break

                                {{-- Spec Ə11 / Part 8.3: dinamik otaq tərkibi --}}
                                @case('room_inventory')
                                    @php $inventory = is_array($value) ? $value : []; @endphp
                                    <div class="grid gap-2 sm:grid-cols-2" data-inventory>
                                        @foreach ($question->options ?? [] as $option)
                                            @php $count = (int) ($inventory[$option['value']] ?? 0); @endphp
                                            <div data-inventory-row="{{ $option['value'] }}"
                                                class="flex items-center justify-between gap-3 rounded-ds border px-3.5 py-2.5 {{ $count > 0 ? 'border-ink bg-sel-bg' : 'border-black/15 bg-white' }}">
                                                <span class="text-[13px] font-semibold">{{ $optLabel($option) }}</span>
                                                <span class="flex shrink-0 items-center gap-2">
                                                    <button type="button" data-inv-step="-1" {{ $completed ? 'disabled' : '' }}
                                                        class="h-7 w-7 rounded-full border border-black/20 text-sm font-bold leading-none hover:border-ink">−</button>
                                                    <span data-inv-count class="w-5 text-center text-[13px] font-bold">{{ $count }}</span>
                                                    <button type="button" data-inv-step="1" {{ $completed ? 'disabled' : '' }}
                                                        class="h-7 w-7 rounded-full border border-black/20 text-sm font-bold leading-none hover:border-ink">+</button>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                    @break

                                {{-- Spec Ə8 / Part 10 №20: göndərməni bloklayan razılıq --}}
                                @case('consent')
                                    <label class="flex cursor-pointer items-start gap-3 text-[13px] font-medium">
                                        <input type="checkbox" data-consent {{ $value === '1' ? 'checked' : '' }} {{ $completed ? 'disabled' : '' }} class="mt-0.5 h-4 w-4 accent-ink">
                                        <span>{{ t('portal.brief_consent_label') }}</span>
                                    </label>
                                    @break

                                {{-- Spec Ə2: obmer / BTİ planı --}}
                                @case('file')
                                    <div data-file>
                                        @if (! empty($value['path']))
                                            <p class="mb-2 text-[13px] font-semibold text-ok">✓ {{ $value['name'] ?? $value['path'] }}</p>
                                        @endif
                                        @unless ($completed)
                                            <input type="file" data-file-input accept=".pdf,.jpg,.jpeg,.png"
                                                class="block w-full text-[13px] file:mr-3 file:rounded-ds file:border-0 file:bg-ink file:px-4 file:py-2 file:text-[13px] file:font-semibold file:text-white">
                                        @endunless
                                    </div>
                                    @break

                                @case('image_select')
                                    {{-- Uses the same single-choice mechanism: selected marker is the bg-ink class, read by collect(). --}}
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" data-single-choice>
                                        @foreach ($question->options ?? [] as $option)
                                            <button type="button" data-choice value="{{ $option['value'] }}" {{ $completed ? 'disabled' : '' }}
                                                class="overflow-hidden rounded-ds-md border text-left transition-colors
                                                    {{ $value === $option['value'] ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">
                                                <span class="block aspect-[4/3] w-full bg-gray-soft2 bg-cover bg-center"
                                                    style="background-image:url('{{ storage_url($option['image_url'] ?? '') }}')"></span>
                                                <span class="block px-3 py-2 text-[13px] font-semibold">{{ $optLabel($option) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
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
            const uploadUrl = form.dataset.uploadUrl;
            const roomId = form.dataset.roomId || null;
            const csrf = form.querySelector('input[name="_token"]').value;
            const saveState = document.getElementById('saveState');
            const timers = {};

            // Answers from the whole brief — conditional logic crosses sections.
            const seededValues = @json($values);

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

            // ── Value extraction, one branch per question type ──
            const collect = (block) => {
                switch (block.dataset.type) {
                    case 'matrix': {
                        const out = {};
                        block.querySelectorAll('[data-matrix-row]').forEach(row => {
                            const picked = row.querySelector('[data-matrix-cell].bg-ink');
                            if (picked) out[row.dataset.matrixRow] = picked.getAttribute('value');
                        });
                        return out;
                    }
                    case 'color_swatch': {
                        const pick = role => [...block.querySelectorAll(`[data-swatch-cell][data-role="${role}"]`)].map(b => b.getAttribute('value'));
                        return { base: pick('base'), accent: pick('accent') };
                    }
                    case 'room_inventory': {
                        const out = {};
                        block.querySelectorAll('[data-inventory-row]').forEach(row => {
                            const n = parseInt(row.querySelector('[data-inv-count]').textContent, 10) || 0;
                            if (n > 0) out[row.dataset.inventoryRow] = n;
                        });
                        return out;
                    }
                    case 'budget_range':
                        return {
                            min: block.querySelector('[data-budget-min]').value || null,
                            max: block.querySelector('[data-budget-max]').value || null,
                            currency: block.querySelector('[data-budget-currency]').value,
                        };
                    case 'consent':
                        return block.querySelector('[data-consent]').checked ? '1' : '0';
                    case 'file':
                        return null; // uploaded separately
                }

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

            // ── Skip logic — mirrors BriefQuestion::shouldShow() ──
            const currentValueByKey = () => {
                const map = Object.assign({}, seededValues);
                document.querySelectorAll('[data-question]').forEach(b => { map[b.dataset.key] = collect(b); });
                return map;
            };
            const matches = (rule, values) => {
                const actual = values[rule.question];
                const expected = rule.value;
                switch (rule.operator) {
                    case 'not_equals': return Array.isArray(actual) ? !actual.includes(expected) : actual !== expected;
                    case 'in': return [].concat(expected).includes(actual);
                    case 'gte': return actual !== null && actual !== '' && parseFloat(actual) >= parseFloat(expected);
                    case 'lte': return actual !== null && actual !== '' && parseFloat(actual) <= parseFloat(expected);
                    case 'filled': return Array.isArray(actual) ? actual.length > 0 : (actual !== null && actual !== undefined && actual !== '');
                    case 'has_room': return actual && typeof actual === 'object' && (parseInt(actual[expected], 10) || 0) > 0;
                    case 'matrix_row_filled': return !!(actual && typeof actual === 'object' && actual[expected]);
                }
                return Array.isArray(actual) ? actual.includes(expected) : actual === expected;
            };
            const applySkip = () => {
                const values = currentValueByKey();
                document.querySelectorAll('[data-skip]').forEach(b => {
                    let rule; try { rule = JSON.parse(b.dataset.skip); } catch (e) { return; }
                    b.hidden = !matches(rule, values);
                });
            };

            // Every write is debounced per question: composite widgets (matrix,
            // swatches, room counters) fire several clicks in a row and parallel
            // PATCHes for one answer would land out of order.
            const save = (block, delegate, delay = 300) => {
                applySkip();
                const id = block.dataset.question;
                clearTimeout(timers[id]);
                timers[id] = setTimeout(() => send(id, collect(block), delegate?.checked ?? false), delay);
            };

            document.querySelectorAll('[data-question]').forEach(block => {
                const id = block.dataset.question;
                const delegate = block.querySelector('[data-delegate]');
                const zone = block.querySelector('[data-input-zone]');
                const active = ['border-ink', 'bg-ink', 'text-white'];
                const inactive = ['border-black/20', 'bg-white'];

                // Debounced free-text / numeric / date fields.
                block.querySelectorAll('[data-field], [data-budget-min], [data-budget-max]').forEach(field => {
                    field.addEventListener('input', () => save(block, delegate, 800));
                });
                block.querySelector('[data-budget-currency]')?.addEventListener('change', () => save(block, delegate));
                block.querySelector('[data-consent]')?.addEventListener('change', () => save(block, delegate));

                block.querySelectorAll('[data-choice]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const group = btn.closest('[data-multi-choice], [data-single-choice]');

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

                        save(block, delegate);
                    });
                });

                // Matrix: one exclusive pick per row.
                block.querySelectorAll('[data-matrix-cell]').forEach(cell => {
                    cell.addEventListener('click', () => {
                        const row = cell.closest('[data-matrix-row]');
                        row.querySelectorAll('[data-matrix-cell]').forEach(c => {
                            c.classList.remove('border-ink', 'bg-ink');
                            c.classList.add('border-black/25', 'bg-white');
                        });
                        cell.classList.remove('border-black/25', 'bg-white');
                        cell.classList.add('border-ink', 'bg-ink');
                        save(block, delegate);
                    });
                });

                // Swatch picker: current mode decides whether a tap sets base or accent.
                const swatch = block.querySelector('[data-swatch]');
                if (swatch) {
                    let mode = 'base';
                    swatch.querySelectorAll('[data-swatch-mode]').forEach(btn => {
                        btn.addEventListener('click', () => {
                            mode = btn.dataset.swatchMode;
                            swatch.querySelectorAll('[data-swatch-mode]').forEach(b => {
                                const on = b === btn;
                                b.classList.toggle('border-ink', on);
                                b.classList.toggle('bg-ink', on);
                                b.classList.toggle('text-white', on);
                                b.classList.toggle('border-black/20', !on);
                                b.classList.toggle('bg-white', !on);
                            });
                        });
                    });
                    const limit = r => parseInt(r === 'base' ? swatch.dataset.baseMax : swatch.dataset.accentMax, 10);
                    swatch.querySelectorAll('[data-swatch-cell]').forEach(cell => {
                        cell.addEventListener('click', () => {
                            const tag = cell.querySelector('[data-swatch-tag]');
                            if (cell.dataset.role === mode) {
                                cell.dataset.role = '';
                            } else {
                                const used = swatch.querySelectorAll(`[data-swatch-cell][data-role="${mode}"]`).length;
                                if (used >= limit(mode)) return;
                                cell.dataset.role = mode;
                            }
                            tag.textContent = cell.dataset.role === 'accent' ? 'A' : (cell.dataset.role === 'base' ? 'F' : '');
                            cell.classList.toggle('border-ink', !!cell.dataset.role);
                            cell.classList.toggle('border-black/10', !cell.dataset.role);
                            save(block, delegate);
                        });
                    });
                }

                // Room inventory counters — drive which room accordions exist.
                block.querySelectorAll('[data-inv-step]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const row = btn.closest('[data-inventory-row]');
                        const el = row.querySelector('[data-inv-count]');
                        const next = Math.max(0, Math.min(9, (parseInt(el.textContent, 10) || 0) + parseInt(btn.dataset.invStep, 10)));
                        el.textContent = next;
                        row.classList.toggle('border-ink', next > 0);
                        row.classList.toggle('bg-sel-bg', next > 0);
                        row.classList.toggle('border-black/15', next === 0);
                        row.classList.toggle('bg-white', next === 0);
                        save(block, delegate);
                    });
                });

                // Files bypass autosave: multipart POST, then reload to show the link.
                block.querySelector('[data-file-input]')?.addEventListener('change', (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    const data = new FormData();
                    data.append('_token', csrf);
                    data.append('question_id', id);
                    data.append('file', file);
                    if (roomId) data.append('room_id', roomId);
                    saveState.textContent = '…';
                    fetch(uploadUrl, { method: 'POST', body: data, headers: { 'X-CSRF-TOKEN': csrf } })
                        .then(r => r.ok ? window.location.reload() : Promise.reject())
                        .catch(() => { saveState.textContent = @json(t('portal.brief_save_error')); });
                });

                delegate?.addEventListener('change', () => {
                    zone.classList.toggle('pointer-events-none', delegate.checked);
                    zone.classList.toggle('opacity-40', delegate.checked);
                    send(id, delegate.checked ? null : collect(block), delegate.checked);
                });
            });

            applySkip(); // initial evaluation on load
        })();
    </script>
    @endunless
</x-portal.shell>
