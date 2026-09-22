<x-filament-panels::page>
    @php
        $blocks = $this->blocks();
        $total = $this->totalCount();
    @endphp

    <div class="space-y-6">

        {{-- Ümumi vəziyyət: siyahıya girməmişdən əvvəl «bu gün ağırdır / təmizdir» hissi --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white px-6 py-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>
                <h2 class="text-lg font-bold text-gray-950 dark:text-white">
                    @if ($total > 0)
                        {{ $total }} element diqqət tələb edir
                    @else
                        Hər şey qaydasındadır
                    @endif
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    @if ($total > 0)
                        Aşağıdakı bloklar bugünkü iş sırasıdır — hər blokda ilk 5 sətir göstərilir.
                    @else
                        Gecikmiş və ya cavab gözləyən heç nə yoxdur.
                    @endif
                </p>
            </div>
            <span @class([
                'rounded-full px-4 py-2 text-[13px] font-medium ring-1',
                'bg-amber-50 text-amber-700 ring-amber-500/20 dark:bg-amber-400/10 dark:text-amber-400' => $total > 0,
                'bg-emerald-50 text-emerald-700 ring-emerald-500/20 dark:bg-emerald-400/10 dark:text-emerald-400' => $total === 0,
            ])>
                {{ $total > 0 ? 'Açıq işlər var' : 'Açıq iş yoxdur' }}
            </span>
        </div>

        {{-- Bloklar --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @foreach ($blocks as $block)
                <div class="flex flex-col rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">

                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ $block['title'] }}</h3>
                            <p class="mt-1 text-[12px] text-gray-400">{{ $block['subtitle'] }}</p>
                        </div>
                        <span @class([
                            'shrink-0 rounded-full px-3 py-1 text-[13px] font-bold',
                            'bg-amber-100 text-amber-800 dark:bg-amber-400/15 dark:text-amber-400' => $block['count'] > 0,
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-400' => $block['count'] === 0,
                        ])>{{ $block['count'] }}</span>
                    </div>

                    @if ($block['count'] === 0)
                        {{-- Boş blok sakit qalmalıdır: narahat edən boşluq sırası yaratmasın --}}
                        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-[13px] font-medium text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400">
                            {{ $block['empty'] }}
                        </p>
                    @else
                        <ul class="flex-1 divide-y divide-gray-100 dark:divide-white/10">
                            @foreach ($block['items'] as $item)
                                <li class="py-3 first:pt-0">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            @if ($item['url'])
                                                <a href="{{ $item['url'] }}"
                                                   class="block truncate text-[13px] font-semibold text-gray-950 hover:underline dark:text-white">
                                                    {{ $item['title'] }}
                                                </a>
                                            @else
                                                <span class="block truncate text-[13px] font-semibold text-gray-950 dark:text-white">
                                                    {{ $item['title'] }}
                                                </span>
                                            @endif
                                            <p class="mt-0.5 truncate text-[12px] text-gray-400">{{ $item['meta'] }}</p>
                                        </div>
                                        @if ($item['badge'])
                                            <span @class([
                                                'shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                                'bg-red-100 text-red-700 dark:bg-red-400/15 dark:text-red-400' => $item['urgent'],
                                                'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $item['urgent'],
                                            ])>{{ $item['badge'] }}</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        @if ($block['url'])
                            <a href="{{ $block['url'] }}"
                               class="mt-4 inline-flex items-center gap-1 text-[13px] font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                Hamısına bax
                                @if ($block['count'] > count($block['items']))
                                    <span class="text-gray-400">(+{{ $block['count'] - count($block['items']) }})</span>
                                @endif
                            </a>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
