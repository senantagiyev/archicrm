<x-filament-panels::page>
    @php
        $stats = $this->stats();
        $series = $this->activitySeries();
        $maxV = max(1, collect($series)->max('value'));
        $donut = $this->statusBreakdown();
        $recent = $this->recentProjects();
        $tasks = $this->todayTasks();
        $circ = 339.292; // 2πr, r=54
        $offset = 0;
    @endphp

    <div class="space-y-6">

        {{-- Welcome header --}}
        <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white px-6 py-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                    Xoş gəlmisiniz, {{ $this->greetingName() }}!
                </h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Büronuzun bugünkü mənzərəsi bir baxışda.</p>
            </div>
            <span class="rounded-full bg-gray-50 px-4 py-2 text-[13px] font-medium text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                {{ $this->todayLabel() }}
            </span>
        </div>

        {{-- KPI tiles --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($stats as $s)
                <div class="relative overflow-hidden rounded-2xl bg-white p-5 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <span class="absolute left-0 top-5 h-8 w-1 rounded-r-full
                        @class(['bg-[#f2d900]' => $s['tone']==='ink', 'bg-amber-400' => $s['tone']==='warn', 'bg-emerald-400' => $s['tone']==='ok'])"></span>
                    <p class="pl-3 text-[12px] font-semibold uppercase tracking-wide text-gray-400">{{ $s['label'] }}</p>
                    <p class="mt-2 pl-3 text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">{{ $s['value'] }}</p>
                    <p class="mt-1 pl-3 text-[12px] font-medium
                        @class(['text-emerald-600 dark:text-emerald-400' => $s['tone']==='ok', 'text-amber-600 dark:text-amber-400' => $s['tone']==='warn', 'text-gray-400' => $s['tone']==='ink'])">{{ $s['hint'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Charts row --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Activity bar chart --}}
            <div class="rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 lg:col-span-2">
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-950 dark:text-white">Layihələrin dinamikası</h2>
                    <span class="text-[12px] font-medium text-gray-400">Son 6 ay</span>
                </div>
                <div class="flex h-44 items-end justify-between gap-3">
                    @foreach ($series as $i => $pt)
                        <div class="flex flex-1 flex-col items-center gap-2">
                            <div class="flex w-full flex-1 items-end">
                                <div class="w-full rounded-t-md transition-all
                                    {{ $i === count($series) - 1 ? 'bg-[#f2d900]' : 'bg-gray-200 dark:bg-white/15' }}"
                                    style="height: {{ max(6, (int) round($pt['value'] / $maxV * 100)) }}%"
                                    title="{{ $pt['value'] }}"></div>
                            </div>
                            <span class="text-[11px] font-medium text-gray-400">{{ $pt['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Status donut --}}
            <div class="rounded-2xl bg-white p-6 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="mb-4 text-base font-bold text-gray-950 dark:text-white">Statuslar üzrə</h2>
                @if ($donut['total'] > 0)
                    <div class="flex items-center gap-5">
                        <div class="relative h-[132px] w-[132px] shrink-0">
                            <svg viewBox="0 0 128 128" class="h-full w-full -rotate-90">
                                <circle cx="64" cy="64" r="54" fill="none" stroke="currentColor" class="text-gray-100 dark:text-white/10" stroke-width="16"/>
                                @foreach ($donut['slices'] as $slice)
                                    @php $len = $slice['value'] / $donut['total'] * $circ; @endphp
                                    <circle cx="64" cy="64" r="54" fill="none" stroke="{{ $slice['color'] }}" stroke-width="16"
                                        stroke-dasharray="{{ $len }} {{ $circ - $len }}" stroke-dashoffset="{{ -$offset }}"/>
                                    @php $offset += $len; @endphp
                                @endforeach
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-extrabold text-gray-950 dark:text-white">{{ $donut['total'] }}</span>
                                <span class="text-[10px] font-medium uppercase tracking-wide text-gray-400">layihə</span>
                            </div>
                        </div>
                        <ul class="flex-1 space-y-2">
                            @foreach ($donut['slices'] as $slice)
                                <li class="flex items-center justify-between text-[13px]">
                                    <span class="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                                        <span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $slice['color'] }}"></span>{{ $slice['label'] }}
                                    </span>
                                    <span class="font-bold text-gray-950 dark:text-white">{{ $slice['value'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <p class="py-10 text-center text-sm text-gray-400">Hələ layihə yoxdur</p>
                @endif
            </div>
        </div>

        {{-- Lists row --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Recent projects --}}
            <div class="rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 lg:col-span-2">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-white/10">
                    <h2 class="text-base font-bold text-gray-950 dark:text-white">Son layihələr</h2>
                    <a href="{{ \App\Filament\Resources\ProjectResource::getUrl() }}" class="text-[13px] font-semibold text-gray-400 hover:text-gray-950 dark:hover:text-white">Hamısına bax →</a>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($recent as $p)
                        <a href="{{ \App\Filament\Resources\ProjectResource::getUrl('edit', ['record' => $p]) }}"
                           class="flex items-center gap-4 px-6 py-3.5 transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-[13px] font-bold text-gray-500 dark:bg-white/10 dark:text-gray-300">
                                {{ mb_strtoupper(mb_substr($p->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $p->name }}</p>
                                <p class="truncate text-[12px] text-gray-400">{{ $p->client?->name ?? '—' }}</p>
                            </div>
                            <span @class([
                                'rounded-full px-2.5 py-1 text-[11px] font-semibold',
                                'bg-[#fff8c2] text-[#7a6f00]' => $p->status === \App\Enums\ProjectStatus::Active,
                                'bg-emerald-50 text-emerald-700' => $p->status === \App\Enums\ProjectStatus::Done,
                                'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300' => ! in_array($p->status, [\App\Enums\ProjectStatus::Active, \App\Enums\ProjectStatus::Done], true),
                            ])>{{ $p->status->label() }}</span>
                            <span class="w-10 text-right text-sm font-bold tabular-nums text-gray-950 dark:text-white">{{ $p->readiness }}%</span>
                        </a>
                    @empty
                        <p class="px-6 py-10 text-center text-sm text-gray-400">Hələ layihə yoxdur</p>
                    @endforelse
                </div>
            </div>

            {{-- Today's tasks --}}
            <div class="rounded-2xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-white/10">
                    <h2 class="text-base font-bold text-gray-950 dark:text-white">Diqqət tələb edən tapşırıqlar</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($tasks as $t)
                        <div class="flex items-start gap-3 px-6 py-3.5">
                            <span @class([
                                'mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full',
                                'bg-red-500' => $t->deadline && $t->deadline->isPast(),
                                'bg-[#f2d900]' => ! ($t->deadline && $t->deadline->isPast()),
                            ])></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $t->title }}</p>
                                <p class="truncate text-[12px] text-gray-400">{{ $t->project?->name ?? '—' }}</p>
                            </div>
                            <span class="shrink-0 text-[11px] font-medium text-gray-400">{{ $t->deadline?->format('d.m') }}</span>
                        </div>
                    @empty
                        <p class="px-6 py-10 text-center text-sm text-gray-400">Bu gün üçün tapşırıq yoxdur 🎉</p>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</x-filament-panels::page>
