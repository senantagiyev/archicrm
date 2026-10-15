{{--
    Layihə kartı → «İcmal» tabı. Məlumat ProjectOverviewService-dən gəlir
    (sadə massivlər) — burada sorğu və model yoxdur.
--}}
@php
    $card = 'rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $stage = $summary['current_stage'];
@endphp

<div class="archi-project-overview space-y-4">

    {{-- Əsas məlumatlar --}}
    <div class="{{ $card }}">
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13px] md:grid-cols-3 xl:grid-cols-6">
            <div class="min-w-0">
                <dt class="text-[12px] text-gray-400">Müştəri</dt>
                <dd class="truncate font-semibold text-gray-950 dark:text-white">{{ $summary['client'] ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[12px] text-gray-400">Obyekt</dt>
                <dd class="truncate font-semibold text-gray-950 dark:text-white">
                    {{ collect([$summary['type'], $summary['address']])->filter()->implode(' · ') ?: '—' }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[12px] text-gray-400">Menecer</dt>
                <dd class="truncate font-semibold text-gray-950 dark:text-white">{{ $summary['manager'] ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[12px] text-gray-400">Ümumi irəliləyiş</dt>
                <dd class="flex items-center gap-2 font-semibold text-gray-950 dark:text-white">
                    <span class="h-1.5 w-full max-w-24 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <span class="block h-full rounded-full bg-primary-600 dark:bg-primary-400" style="width: {{ max(0, min(100, $summary['readiness'])) }}%"></span>
                    </span>
                    {{ $summary['readiness'] }}%
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[12px] text-gray-400">Cari mərhələ</dt>
                <dd class="truncate font-semibold text-gray-950 dark:text-white">
                    @if ($stage)
                        <a href="{{ $stage['url'] }}" class="hover:underline">{{ $stage['name'] }}</a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[12px] text-gray-400">Təhvil müddəti</dt>
                <dd @class([
                    'truncate font-semibold',
                    'text-red-600 dark:text-red-400' => $summary['deadline_overdue'],
                    'text-gray-950 dark:text-white' => ! $summary['deadline_overdue'],
                ])>{{ $summary['deadline'] ?? '—' }}</dd>
            </div>
        </dl>
    </div>

    {{-- Sizdən gözlənilən əməliyyatlar --}}
    <div class="{{ $card }}">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h3 class="text-base font-bold text-gray-950 dark:text-white">Sizdən gözlənilən əməliyyatlar</h3>
            <span @class([
                'shrink-0 rounded-full px-3 py-1 text-[13px] font-bold',
                'bg-amber-100 text-amber-800 dark:bg-amber-400/15 dark:text-amber-400' => count($actions) > 0,
                'bg-emerald-100 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-400' => count($actions) === 0,
            ])>{{ count($actions) }}</span>
        </div>

        @if ($actions === [])
            <p class="rounded-xl bg-emerald-50 px-4 py-3 text-[13px] font-medium text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400">
                Bu layihədə sizi gözləyən əməliyyat yoxdur.
            </p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($actions as $item)
                    <li class="py-3 first:pt-0 last:pb-0" wire:key="overview-action-{{ $item['key'] }}">
                        <a href="{{ $item['url'] }}" class="group flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-gray-950 group-hover:underline dark:text-white">{{ $item['action'] }}</p>
                                <p class="mt-0.5 truncate text-[12px] text-gray-500 dark:text-gray-400">
                                    {{ $item['kind'] }} · {{ $item['title'] }}
                                </p>
                            </div>
                            @if ($item['due'])
                                <span @class([
                                    'shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                    'bg-red-100 text-red-700 dark:bg-red-400/15 dark:text-red-400' => $item['overdue'],
                                    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $item['overdue'],
                                ])>{{ $item['due'] }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {{-- Cari mərhələ --}}
        <div class="{{ $card }}">
            <h3 class="mb-3 text-base font-bold text-gray-950 dark:text-white">Cari mərhələ</h3>
            @if ($stage)
                <a href="{{ $stage['url'] }}" class="block rounded-xl bg-gray-50 px-4 py-3 hover:bg-gray-100 dark:bg-white/5 dark:hover:bg-white/10">
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate text-[14px] font-semibold text-gray-950 dark:text-white">{{ $stage['name'] }}</span>
                        <x-filament::badge :color="$stage['color']">{{ $stage['status'] }}</x-filament::badge>
                    </div>
                    <p class="mt-1 text-[12px] text-gray-500 dark:text-gray-400">
                        Hazırlıq {{ $stage['readiness'] }}%
                        @if ($stage['plan_end'])
                            · plan bitmə:
                            <span @class(['font-semibold text-red-600 dark:text-red-400' => $stage['overdue']])>{{ $stage['plan_end'] }}</span>
                        @endif
                        @if ($stage['responsible'])
                            · {{ $stage['responsible'] }}
                        @endif
                    </p>
                </a>
            @else
                <p class="text-[13px] text-gray-500 dark:text-gray-400">Mərhələ hələ qurulmayıb.</p>
            @endif
        </div>

        {{-- Yaxınlaşan son tarixlər --}}
        <div class="{{ $card }}">
            <h3 class="mb-3 text-base font-bold text-gray-950 dark:text-white">Yaxınlaşan son tarixlər</h3>
            @if ($deadlines === [])
                <p class="text-[13px] text-gray-500 dark:text-gray-400">Yaxın {{ \App\Services\Projects\ProjectOverviewService::UPCOMING_DAYS }} gündə son tarix yoxdur.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($deadlines as $deadline)
                        <li class="py-2 first:pt-0 last:pb-0">
                            <a href="{{ $deadline['url'] }}" class="group flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate text-[13px] text-gray-950 group-hover:underline dark:text-white">
                                    <span class="text-gray-400">{{ $deadline['kind'] }} ·</span> {{ $deadline['title'] }}
                                </span>
                                <span @class([
                                    'shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                    'bg-red-100 text-red-700 dark:bg-red-400/15 dark:text-red-400' => $deadline['overdue'],
                                    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $deadline['overdue'],
                                ])>{{ $deadline['overdue'] ? 'Gecikib · ' : '' }}{{ $deadline['date'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        {{-- Mühüm hadisələr --}}
        <div class="{{ $card }} xl:col-span-2">
            <h3 class="mb-3 text-base font-bold text-gray-950 dark:text-white">Mühüm hadisələr</h3>
            @if ($feed === [])
                <p class="text-[13px] text-gray-500 dark:text-gray-400">Hələ qeydə alınmış hadisə yoxdur.</p>
            @else
                <ul class="space-y-3">
                    @foreach ($feed as $event)
                        <li class="flex gap-3">
                            <span @class([
                                'mt-1.5 size-2 shrink-0 rounded-full',
                                'bg-emerald-500' => $event['type'] === 'approved',
                                'bg-red-500' => $event['type'] === 'revision',
                                'bg-amber-500' => $event['type'] === 'sent',
                                'bg-primary-600 dark:bg-primary-400' => in_array($event['type'], ['decision', 'change_request', 'version'], true),
                            ])></span>
                            <div class="min-w-0">
                                @if ($event['url'])
                                    <a href="{{ $event['url'] }}" class="text-[13px] font-medium text-gray-950 hover:underline dark:text-white">{{ $event['text'] }}</a>
                                @else
                                    <span class="text-[13px] font-medium text-gray-950 dark:text-white">{{ $event['text'] }}</span>
                                @endif
                                <p class="text-[12px] text-gray-400">
                                    {{ $event['at_label'] }}@if ($event['actor']) · {{ $event['actor'] }}@endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- İştirakçılar --}}
        <div class="{{ $card }}">
            <h3 class="mb-3 text-base font-bold text-gray-950 dark:text-white">İştirakçılar</h3>
            @if ($participants['team'] === [])
                <p class="text-[13px] text-gray-500 dark:text-gray-400">Komanda təyin edilməyib.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($participants['team'] as $person)
                        <li class="flex items-center justify-between gap-3 text-[13px]">
                            <span class="truncate font-medium text-gray-950 dark:text-white">{{ $person['name'] }}</span>
                            <span class="shrink-0 text-[12px] text-gray-400">{{ $person['role'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($participants['client'])
                <div class="mt-4 border-t border-gray-100 pt-3 dark:border-white/10">
                    <p class="text-[12px] text-gray-400">Müştəri</p>
                    <p class="text-[13px] font-medium text-gray-950 dark:text-white">{{ $participants['client']['name'] }}</p>
                    @if ($participants['client']['phone'] || $participants['client']['email'])
                        <p class="text-[12px] text-gray-500 dark:text-gray-400">
                            {{ collect([$participants['client']['phone'], $participants['client']['email']])->filter()->implode(' · ') }}
                        </p>
                    @endif
                    @if ($participants['client']['portal_users'] !== [])
                        <p class="mt-1 text-[12px] text-gray-500 dark:text-gray-400">
                            Portal: {{ implode(', ', $participants['client']['portal_users']) }}
                        </p>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Obyektə bağlanmamış köhnə qeydlər (TZ §10) --}}
    @if ($unchecked !== [])
        <div id="yoxlanmali-qeydler" class="{{ $card }}">
            <h3 class="text-base font-bold text-gray-950 dark:text-white">Yoxlanmalı qeydlər</h3>
            <p class="mb-3 mt-1 text-[12px] text-gray-400">
                Bu qeydlər heç bir materiala, mərhələyə və ya irada bağlanmayıb. Onlar silinməyib və avtomatik bölüşdürülməyib — yoxlayıb müvafiq obyektə bağlayın.
            </p>
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($unchecked as $record)
                    <li class="flex items-center justify-between gap-3 py-2 text-[13px] first:pt-0 last:pb-0">
                        <span class="min-w-0 truncate text-gray-950 dark:text-white">
                            <span class="text-gray-400">{{ $record['kind'] }} ·</span> {{ $record['title'] }}
                        </span>
                        <span class="flex shrink-0 items-center gap-3 text-[12px] text-gray-400">
                            {{ collect([$record['status'], $record['date']])->filter()->implode(' · ') }}
                            @if ($record['can_link'])
                                <x-filament::link
                                    tag="button"
                                    size="sm"
                                    color="warning"
                                    icon="heroicon-o-link"
                                    wire:click="mountAction('linkRecord', {{ \Illuminate\Support\Js::from(['type' => $record['type'], 'id' => $record['id']]) }})"
                                >
                                    Əlaqələndir
                                </x-filament::link>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
