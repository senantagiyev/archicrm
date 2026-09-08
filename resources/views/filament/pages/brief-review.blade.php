<x-filament-panels::page>
    @php
        $brief = $this->brief();
        $status = $brief->statusEnum();
        $project = $this->record;
        $summary = $this->summary();
        $risks = $this->risks();
        $missing = $this->missing();
        $priorities = $this->priorities();
        $attachments = $this->attachments();
        $versions = $this->versions();
        $open = $this->openComments();

        $badge = [
            'critical' => ['🔴', 'Critical', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
            'important' => ['🟠', 'Important', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'missing' => ['⚫', 'Missing', 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'],
            'normal' => ['⚪', 'Normal', 'bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400'],
        ];
        $statusColor = match ($status->color()) {
            'success' => 'bg-emerald-50 text-emerald-700',
            'danger' => 'bg-red-50 text-red-700',
            'warning' => 'bg-amber-50 text-amber-700',
            'info' => 'bg-sky-50 text-sky-700',
            default => 'bg-gray-100 text-gray-600',
        };
        $grouped = $priorities->groupBy(fn ($p) => $p['section']->id.':'.($p['room']?->id ?? 0));
        $counts = $priorities->countBy('priority');
    @endphp

    <div class="space-y-6">
        {{-- 1. Header --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white px-6 py-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>
                <p class="text-[12px] font-semibold uppercase tracking-wide text-gray-400">{{ $project->client?->name }}</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">{{ $project->name }}</h1>
                <p class="mt-1 text-[13px] text-gray-500">
                    @if ($brief->submitted_at) Göndərilib: {{ $brief->submitted_at->format('d.m.Y H:i') }} @endif
                    @if ($brief->approved_at) · Təsdiq: {{ $brief->approved_at->format('d.m.Y') }} @endif
                    · v{{ $brief->current_version }}
                </p>
            </div>
            <span class="rounded-full px-3 py-1.5 text-[12px] font-bold {{ $statusColor }}">{{ $status->label() }}</span>
        </div>

        {{-- 2. Sticky summary panel --}}
        <div class="sticky top-16 z-10 rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="grid grid-cols-2 gap-4 text-[13px] md:grid-cols-4 xl:grid-cols-7">
                @foreach ([
                    ['Obyekt', $summary['address'] ?? '—'],
                    ['Tip', $summary['type'] ?? '—'],
                    ['Sahə', ($summary['design_area'] ?? '—').' / '.($summary['total_area'] ?? '—').' m²'],
                    ['Büdcə', $summary['budget'] ?? '—'],
                    ['Müddət', $summary['timeline'] ?: '—'],
                    ['Format', $summary['scope'] ?? '—'],
                    ['Otaqlar', count($summary['rooms'])],
                ] as [$label, $value])
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $label }}</p>
                        <p class="mt-0.5 truncate font-semibold text-gray-950 dark:text-white" title="{{ $value }}">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
            @if (!empty($summary['styles']) || !empty($summary['rooms']))
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($summary['styles'] as $s)<span class="rounded-full bg-[#fff8c2] px-2 py-0.5 text-[11px] font-semibold text-[#7a6f00]">{{ $s }}</span>@endforeach
                    @foreach ($summary['rooms'] as $r)<span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $r }}</span>@endforeach
                </div>
            @endif
        </div>

        {{-- 3 + 4. Risks & missing --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 {{ $risks ? 'ring-red-200' : '' }}">
                <h2 class="text-base font-bold text-gray-950 dark:text-white">Risklər ({{ count($risks) }})</h2>
                <ul class="mt-3 space-y-2">
                    @forelse ($risks as $risk)
                        <li class="flex items-start gap-2 text-[13px]"><span>{{ $badge[$risk['level']][0] ?? '⚪' }}</span><span><b>{{ $risk['code'] }}</b> — {{ $risk['message'] }}</span></li>
                    @empty
                        <li class="text-[13px] text-gray-400">Risk aşkarlanmadı</li>
                    @endforelse
                </ul>
            </div>
            <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-base font-bold text-gray-950 dark:text-white">Doldurulmamış məcburi sahələr ({{ $missing->count() }})</h2>
                <ul class="mt-3 space-y-2">
                    @forelse ($missing as $item)
                        <li class="flex items-center justify-between gap-3 text-[13px]">
                            <span><span class="text-gray-400">{{ $item['room']?->label ?? $item['section']->getTranslation('name', 'az') }} ·</span> {{ $item['question']->getTranslation('label', 'az') }}</span>
                            @if ($status !== \App\Enums\BriefStatus::Approved)
                                <button type="button" class="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-[11px] font-semibold hover:bg-[#fff8c2] dark:bg-white/10"
                                    wire:click="mountAction('requestClarification', { question: {{ $item['question']->id }}, room: {{ $item['room']?->id ?? 'null' }} })">Dəqiqləşdir</button>
                            @endif
                        </li>
                    @empty
                        <li class="text-[13px] text-gray-400">Hamısı doldurulub</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Open clarification threads --}}
        @if ($open->isNotEmpty())
            <div class="rounded-2xl bg-[#fff8c2]/60 p-5 ring-1 ring-[#f2d900]/60 dark:bg-yellow-500/10">
                <h2 class="text-base font-bold text-gray-950 dark:text-white">Müştəridən gözlənilən dəqiqləşdirmələr ({{ $open->count() }})</h2>
                <ul class="mt-3 space-y-2 text-[13px]">
                    @foreach ($open as $c)
                        <li><b>{{ $c->room?->label ? $c->room->label.' · ' : '' }}{{ $c->question?->getTranslation('label', 'az') }}</b> — {{ $c->body }} <span class="text-gray-500">({{ $c->user?->name }}, {{ $c->created_at->format('d.m.Y') }})</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- 5 + 6. Sections with answer-level priorities --}}
        <div class="rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-white/10">
                <h2 class="text-base font-bold text-gray-950 dark:text-white">Cavablar</h2>
                <div class="flex gap-2 text-[11px] font-semibold">
                    @foreach (['critical', 'important', 'missing', 'normal'] as $lvl)
                        <span class="rounded-full px-2 py-0.5 {{ $badge[$lvl][2] }}">{{ $badge[$lvl][0] }} {{ $badge[$lvl][1] }} {{ $counts[$lvl] ?? 0 }}</span>
                    @endforeach
                </div>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($grouped as $items)
                    @php $first = $items->first(); $sec = $first['section']; $room = $first['room']; @endphp
                    <details class="group" {{ $items->contains(fn ($p) => in_array($p['priority'], ['critical', 'important'], true)) ? 'open' : '' }}>
                        <summary class="flex cursor-pointer items-center justify-between px-5 py-3.5 text-sm font-semibold text-gray-950 hover:bg-gray-50 dark:text-white dark:hover:bg-white/5">
                            <span>{{ $room?->label ?? $sec->getTranslation('name', 'az') }}</span>
                            <span class="text-[11px] font-medium text-gray-400">{{ $items->count() }} sual</span>
                        </summary>
                        <div class="divide-y divide-gray-50 dark:divide-white/5">
                            @foreach ($items as $p)
                                @php
                                    $q = $p['question'];
                                    $a = $p['answer'];
                                    $delegated = (bool) ($a?->delegated_to_designer ?? false);
                                    $display = $delegated ? 'Dizaynerə etibar edilib' : ($a && $a->isAnswered() ? $q->displayValue($a->value) : '—');
                                @endphp
                                <div class="flex items-start gap-3 px-5 py-3 {{ $delegated ? 'opacity-60' : '' }}">
                                    <span class="mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $badge[$p['priority']][2] }}">{{ $badge[$p['priority']][0] }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[12px] font-medium text-gray-500">{{ $q->getTranslation('label', 'az') }}@if ($q->is_required) <span class="text-red-500">*</span>@endif</p>
                                        <p class="mt-0.5 break-words text-[13px] text-gray-950 dark:text-white">{{ $display }}</p>
                                        @if ($p['note'])<p class="mt-0.5 text-[11px] text-gray-400">{{ $p['note'] }}</p>@endif
                                    </div>
                                    @if ($status !== \App\Enums\BriefStatus::Approved)
                                        <button type="button" class="shrink-0 rounded-md px-2 py-1 text-[11px] font-semibold text-gray-400 hover:bg-[#fff8c2] hover:text-gray-950"
                                            wire:click="mountAction('requestClarification', { question: {{ $q->id }}, room: {{ $room?->id ?? 'null' }} })">Dəqiqləşdir</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </div>

        {{-- 7 + 8. Attachments & versions --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-base font-bold text-gray-950 dark:text-white">Əlavələr ({{ $attachments->count() }})</h2>
                <ul class="mt-3 space-y-2">
                    @forelse ($attachments as $f)
                        <li class="flex items-center justify-between gap-3 text-[13px]">
                            <span class="min-w-0"><a href="{{ $f['url'] }}" target="_blank" class="font-semibold text-gray-950 underline-offset-2 hover:underline dark:text-white">{{ $f['name'] }}</a>
                                <span class="block truncate text-[11px] text-gray-400">{{ $f['room'] ? $f['room'].' · ' : '' }}{{ $f['question'] }}</span></span>
                            <span class="shrink-0 text-[11px] text-gray-400">{{ $f['answered_at']?->format('d.m.Y') }}</span>
                        </li>
                    @empty
                        <li class="text-[13px] text-gray-400">Fayl yüklənməyib</li>
                    @endforelse
                </ul>
            </div>
            <div class="rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-base font-bold text-gray-950 dark:text-white">Versiyalar ({{ $versions->count() }})</h2>
                <ul class="mt-3 space-y-2">
                    @forelse ($versions as $i => $v)
                        @php $prev = $versions[$i + 1] ?? null; $changed = $v->changedKeysFrom($prev); @endphp
                        <li class="text-[13px]">
                            <span class="font-bold text-gray-950 dark:text-white">v{{ $v->version }}</span>
                            <span class="text-gray-500">— {{ $v->created_at?->format('d.m.Y H:i') }}@if ($v->note) · {{ $v->note }}@endif</span>
                            @if ($prev)<span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] dark:bg-white/10">{{ count($changed) }} dəyişiklik</span>@endif
                            @if ($prev && $changed)<p class="mt-0.5 truncate text-[11px] text-gray-400" title="{{ implode(', ', $changed) }}">{{ implode(', ', array_slice($changed, 0, 6)) }}{{ count($changed) > 6 ? '…' : '' }}</p>@endif
                        </li>
                    @empty
                        <li class="text-[13px] text-gray-400">Hələ göndərilməyib</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
