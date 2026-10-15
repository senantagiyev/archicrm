<x-portal.shell :title="$project->name" :project="$project" active="overview">
    @php
        $ring = 163.363; // 2πr, r=26
        $readiness = (int) $project->readiness;

        // Enum tam adı ilə yazılır: `use` ifadəsi komponent slotunun içində
        // PHP səviyyəsində qanunsuzdur.
        $stageChip = fn ($status) => match ($status) {
            \App\Enums\StageStatus::Done => 'bg-ok-soft text-ok',
            \App\Enums\StageStatus::Overdue => 'bg-error-soft text-error',
            \App\Enums\StageStatus::InProgress, \App\Enums\StageStatus::Review => 'bg-sel-bg text-ink',
            default => 'bg-neutral-soft text-black/55',
        };
    @endphp

    {{-- Hero --}}
    <div class="overflow-hidden rounded-ds-xl border border-black/8 bg-card">
        <div class="flex flex-wrap items-center justify-between gap-6 bg-accent-dark px-6 py-7 text-white sm:px-8">
            <div class="min-w-0">
                <span class="inline-flex items-center gap-2 text-helper font-medium text-white/70">
                    <span class="h-2 w-2 rounded-[2px] bg-yellow"></span>{{ $project->type->translatedLabel() }}
                </span>
                <h1 class="mt-2 font-b2b text-heading font-semibold leading-tight sm:text-display">{{ $project->name }}</h1>
                <p class="mt-2 text-helper text-white/70">
                    @if ($project->address){{ $project->address }}@endif
                    @if ($project->area) · {{ rtrim(rtrim(number_format((float) $project->area, 2, '.', ' '), '0'), '.') }} m²@endif
                </p>
            </div>
            {{-- Readiness ring --}}
            <div class="flex items-center gap-4">
                <div class="relative h-[74px] w-[74px]">
                    <svg viewBox="0 0 64 64" class="h-full w-full -rotate-90">
                        <circle cx="32" cy="32" r="26" fill="none" stroke="rgba(255,255,255,.15)" stroke-width="7"/>
                        <circle cx="32" cy="32" r="26" fill="none" stroke="#fdfe00" stroke-width="7" stroke-linecap="round"
                            stroke-dasharray="{{ $readiness / 100 * $ring }} {{ $ring }}"/>
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center text-body-lg font-bold">{{ $readiness }}%</div>
                </div>
                <div class="max-w-[90px] text-helper leading-snug text-white/70">{{ t('portal.overview_progress') }}</div>
            </div>
        </div>

        {{-- Meta strip --}}
        <div class="grid grid-cols-2 divide-x divide-black/8 border-t border-black/8 sm:grid-cols-3">
            <div class="px-6 py-4">
                <p class="text-helper font-medium text-black/55">{{ t('portal.deadline') }}</p>
                <p class="mt-1 text-body font-semibold">{{ $project->deadline?->format('d.m.Y') ?? '—' }}</p>
            </div>
            <div class="px-6 py-4">
                <p class="text-helper font-medium text-black/55">{{ t('portal.manager') }}</p>
                <p class="mt-1 truncate text-body font-semibold">{{ $project->manager?->name ?? '—' }}</p>
            </div>
            <div class="col-span-2 border-t border-black/8 px-6 py-4 sm:col-span-1 sm:border-t-0">
                <p class="text-helper font-medium text-black/55">{{ t('portal.overview_current_stage') }}</p>
                <p class="mt-1 truncate text-body font-semibold">{{ $currentStage?->name ?? '—' }}</p>
            </div>
        </div>
    </div>

    {{-- «Sizdən gözlənilən əməliyyatlar» — İcmalın ən yuxarısı (TZ §2). Hər
         element mövcud qeyddən (razılaşdırma, brif) qurulur və birbaşa
         obyektə aparır: material → «Fayllar»dakı kartı. --}}
    <section id="sizden-gozlenilen" data-block="client-actions" class="mt-5 overflow-hidden rounded-ds-xl border border-yellow-line bg-card">
        <div class="flex items-center gap-3 border-b border-black/8 bg-sel-bg px-5 py-3.5">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-yellow text-on-yellow">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            </span>
            <h2 class="flex-1 text-body font-semibold">{{ t('portal.overview_actions_title') }}</h2>
            @if (count($actions))
                <span class="rounded-pill bg-yellow px-2.5 py-0.5 text-helper font-bold text-on-yellow">{{ count($actions) }}</span>
            @endif
        </div>

        @forelse ($actions as $item)
            <a href="{{ $item['url'] }}" data-action="{{ $item['key'] }}"
               class="flex items-center gap-4 border-b border-black/5 px-5 py-3.5 transition-colors last:border-0 hover:bg-neutral-soft/50">
                <div class="min-w-0 flex-1">
                    <p class="text-helper font-medium text-black/55">{{ $item['kind'] }} · {{ $item['action'] }}</p>
                    <p class="mt-0.5 truncate text-body font-semibold">{{ $item['title'] }}</p>
                </div>
                @if ($item['due'])
                    <span @class([
                        'shrink-0 text-helper',
                        'font-semibold text-error' => $item['overdue'],
                        'text-black/55' => ! $item['overdue'],
                    ])>{{ t('portal.respond_by') }}: {{ $item['due'] }}</span>
                @endif
                <span class="shrink-0 text-body-lg text-black/45" aria-hidden="true">→</span>
            </a>
        @empty
            <p class="px-5 py-6 text-center text-body text-black/55">{{ t('portal.overview_actions_empty') }}</p>
        @endforelse
    </section>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        {{-- Cari mərhələ --}}
        <section class="rounded-ds-xl border border-black/8 bg-card p-5" data-block="current-stage">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-body font-semibold">{{ t('portal.overview_current_stage') }}</h2>
                <a href="{{ route('portal.stages', $project) }}" class="text-helper font-semibold text-black/55 underline underline-offset-2 hover:text-ink">{{ t('portal.nav_stages') }} →</a>
            </div>
            @if ($currentStage)
                <div class="flex flex-wrap items-center gap-2">
                    <p class="min-w-0 flex-1 truncate text-title font-semibold">{{ $currentStage->name }}</p>
                    <span @class(['rounded-pill px-3 py-1 text-helper font-medium', $stageChip($currentStage->status)])>{{ $currentStage->status->translatedLabel() }}</span>
                </div>
                <p class="mt-2 text-helper text-black/55">
                    @if ($currentStage->date_plan_start || $currentStage->date_plan_end)
                        {{ $currentStage->date_plan_start?->format('d.m.Y') ?? '…' }} — {{ $currentStage->date_plan_end?->format('d.m.Y') ?? '…' }}
                    @endif
                    @if ($currentStage->responsible) · {{ $currentStage->responsible->name }} @endif
                </p>
                <div class="mt-3 h-1.5 overflow-hidden rounded-pill bg-neutral-soft">
                    <div class="h-full rounded-pill bg-yellow-line" style="width: {{ (int) $currentStage->readiness }}%"></div>
                </div>
            @else
                <p class="text-body text-black/55">{{ t('portal.no_stages') }}</p>
            @endif
        </section>

        {{-- Yaxınlaşan son tarixlər --}}
        <section class="rounded-ds-xl border border-black/8 bg-card p-5" data-block="deadlines">
            <h2 class="mb-3 text-body font-semibold">{{ t('portal.overview_deadlines') }}</h2>
            @forelse ($deadlines as $deadline)
                <div class="flex items-center gap-3 border-b border-black/5 py-2 last:border-0">
                    <span class="w-24 shrink-0 text-helper text-black/55">{{ $deadline['kind'] }}</span>
                    @if ($deadline['url'])
                        <a href="{{ $deadline['url'] }}" class="min-w-0 flex-1 truncate text-body font-medium hover:underline">{{ $deadline['title'] }}</a>
                    @else
                        <span class="min-w-0 flex-1 truncate text-body font-medium">{{ $deadline['title'] }}</span>
                    @endif
                    <span @class(['shrink-0 text-helper', 'font-semibold text-error' => $deadline['overdue'], 'text-black/60' => ! $deadline['overdue']])>{{ $deadline['date'] }}</span>
                </div>
            @empty
                <p class="text-body text-black/55">{{ t('portal.overview_no_deadlines') }}</p>
            @endforelse
        </section>
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-3">
        {{-- Hadisə lenti: yalnız müştəriyə açıq hadisələr (daxili qərar, şərh,
             dərc edilməmiş versiya və iradlar buraya düşmür). --}}
        <section class="rounded-ds-xl border border-black/8 bg-card p-5 lg:col-span-2" data-block="feed">
            <h2 class="mb-3 text-body font-semibold">{{ t('portal.overview_feed') }}</h2>
            @forelse ($feed as $event)
                <div class="flex gap-3 border-b border-black/5 py-2.5 last:border-0">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ match ($event['type']) { 'approved' => 'bg-ok', 'revision' => 'bg-error', 'sent' => 'bg-yellow-line', default => 'bg-black/25' } }}"></span>
                    <div class="min-w-0 flex-1">
                        @if ($event['url'])
                            <a href="{{ $event['url'] }}" class="text-body hover:underline">{{ $event['text'] }}</a>
                        @else
                            <p class="text-body">{{ $event['text'] }}</p>
                        @endif
                        <p class="mt-0.5 text-helper text-black/50">
                            {{ $event['at_label'] }}@if ($event['actor']) · {{ $event['actor'] }}@endif
                        </p>
                    </div>
                </div>
            @empty
                <p class="text-body text-black/55">{{ t('portal.overview_feed_empty') }}</p>
            @endforelse
        </section>

        <div class="space-y-5">
            {{-- İştirakçılar --}}
            <section class="rounded-ds-xl border border-black/8 bg-card p-5" data-block="participants">
                <h2 class="mb-3 text-body font-semibold">{{ t('portal.overview_participants') }}</h2>
                @forelse ($participants as $person)
                    <div class="flex items-center gap-3 py-1.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-soft text-[11px] font-bold uppercase">{{ mb_substr($person['name'], 0, 2) }}</span>
                        <div class="min-w-0">
                            <p class="truncate text-body font-medium">{{ $person['name'] }}</p>
                            <p class="text-helper text-black/55">{{ $person['role'] }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-body text-black/55">—</p>
                @endforelse
            </section>

            {{-- Ödənişlər və müqavilələr — bölmə deyil, ikinci dərəcəli keçidlər
                 (TZ: mühasibatlıq ayrıdır). Köhnə ünvanlar dəyişməyib. --}}
            <section class="rounded-ds-xl border border-black/8 bg-card p-5" data-block="secondary-links">
                <h2 class="mb-2 text-body font-semibold">{{ t('portal.overview_finance_links') }}</h2>
                @foreach ([
                    [route('portal.payments', $project), t('portal.nav_payments'), $paymentsDue > 0 ? $paymentsDue : null],
                    [route('portal.documents', $project), t('portal.overview_contracts_documents'), $documentsCount ?: null],
                    [route('portal.estimate', $project), t('portal.nav_estimate'), null],
                    [route('portal.approvals', $project), t('portal.overview_all_approvals'), $pendingApprovals > 0 ? $pendingApprovals : null],
                ] as [$url, $label, $badge])
                    <a href="{{ $url }}" class="flex items-center gap-2 border-b border-black/5 py-2 text-body last:border-0 hover:underline">
                        <span class="flex-1">{{ $label }}</span>
                        @if ($badge)
                            <span class="rounded-pill bg-neutral-soft px-2 py-0.5 text-helper font-semibold text-black/60">{{ $badge }}</span>
                        @endif
                        <span class="text-black/40" aria-hidden="true">→</span>
                    </a>
                @endforeach
            </section>
        </div>
    </div>
</x-portal.shell>
