<x-portal.shell :title="$project->name" :project="$project" active="overview">
    @php
        $ring = 163.363; // 2πr, r=26
        $readiness = (int) $project->readiness;
    @endphp

    {{-- Hero --}}
    <div class="overflow-hidden rounded-[18px] border border-black/8 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-6 bg-ink px-6 py-6 text-white sm:px-8">
            <div class="min-w-0">
                <span class="inline-flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-white/50">
                    <span class="h-2 w-2 rounded-[2px] bg-yellow"></span>{{ $project->type->translatedLabel() }}
                </span>
                <h1 class="mt-2 font-b2b text-[26px] font-extrabold leading-tight sm:text-[30px]">{{ $project->name }}</h1>
                <p class="mt-1.5 text-[13px] text-white/55">
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
                    <div class="absolute inset-0 flex items-center justify-center text-[17px] font-extrabold">{{ $readiness }}%</div>
                </div>
                <div class="text-[12px] leading-tight text-white/55">
                    Layihənin<br>gedişatı
                </div>
            </div>
        </div>

        {{-- Meta strip --}}
        <div class="grid grid-cols-2 divide-x divide-black/8 border-t border-black/8 sm:grid-cols-3">
            <div class="px-6 py-4">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-black/35">{{ t('portal.deadline') }}</p>
                <p class="mt-1 text-[15px] font-bold">{{ $project->deadline?->format('d.m.Y') ?? '—' }}</p>
            </div>
            <div class="px-6 py-4">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-black/35">{{ t('portal.manager') }}</p>
                <p class="mt-1 truncate text-[15px] font-bold">{{ $project->manager?->name ?? '—' }}</p>
            </div>
            <div class="col-span-2 border-t border-black/8 px-6 py-4 sm:col-span-1 sm:border-t-0">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-black/35">Cari mərhələ</p>
                <p class="mt-1 truncate text-[15px] font-bold">
                    {{ $project->stages->firstWhere('status', \App\Enums\StageStatus::InProgress)?->name ?? ($project->stages->firstWhere('status', \App\Enums\StageStatus::Done) ? 'Davam edir' : '—') }}
                </p>
            </div>
        </div>
    </div>

    {{-- Action required --}}
    @if ($pendingApprovals > 0)
        <a href="{{ route('portal.approvals', $project) }}"
           class="mt-5 flex items-center gap-4 rounded-[16px] border border-yellow-line bg-sel-bg px-6 py-4 transition-transform hover:-translate-y-0.5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-yellow text-ink">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            </span>
            <div class="flex-1">
                <p class="text-[15px] font-bold">{{ t('portal.pending_approvals', ['count' => $pendingApprovals]) }}</p>
                <p class="text-[13px] text-black/55">Baxıb təsdiq və ya rədd etməyiniz gözlənilir.</p>
            </div>
            <span class="text-black/40">→</span>
        </a>
    @endif

    {{-- Quick access --}}
    <h2 class="mb-3 mt-8 text-[13px] font-bold uppercase tracking-[0.1em] text-black/40">Bölmələr</h2>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @php
            $cards = [
                ['brief', route('portal.brief', $project), t('portal.nav_brief'), $briefProgress.'% dolduruldu', 'M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3'],
                ['approvals', route('portal.approvals', $project), t('portal.nav_approvals'), $pendingApprovals > 0 ? $pendingApprovals.' gözləyir' : 'hamısı təmiz', 'M20 6 9 17l-5-5'],
                ['documents', route('portal.documents', $project), t('portal.nav_documents'), $documentsCount.' sənəd', 'M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2'],
                ['payments', route('portal.payments', $project), t('portal.nav_payments'), $paymentsDue > 0 ? $paymentsDue.' ödəniş' : 'aktual yoxdur', 'M3 10h18M7 15h2M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z'],
            ];
        @endphp
        @foreach ($cards as [$key, $url, $label, $sub, $icon])
            <a href="{{ $url }}"
               class="group relative flex flex-col rounded-[16px] border border-black/8 bg-white p-5 transition-all hover:-translate-y-0.5 hover:border-black/20 hover:shadow-[0_10px_30px_-16px_rgba(0,0,0,.25)]">
                @if ($key === 'approvals' && $pendingApprovals > 0)
                    <span class="absolute right-4 top-4 flex h-5 min-w-5 items-center justify-center rounded-full bg-yellow px-1.5 text-[11px] font-bold text-ink">{{ $pendingApprovals }}</span>
                @endif
                <span class="flex h-10 w-10 items-center justify-center rounded-[10px] bg-neutral-soft text-ink transition-colors group-hover:bg-sel-bg">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                </span>
                <p class="mt-4 text-[15px] font-bold">{{ $label }}</p>
                <p class="mt-0.5 text-[12px] text-black/45">{{ $sub }}</p>
            </a>
        @endforeach
    </div>

    {{-- Stages timeline --}}
    <h2 class="mb-3 mt-8 text-[13px] font-bold uppercase tracking-[0.1em] text-black/40">{{ t('portal.stages') }}</h2>
    <div class="overflow-hidden rounded-[16px] border border-black/8 bg-white">
        @forelse ($project->stages as $stage)
            <div class="flex items-center gap-4 border-b border-black/5 px-5 py-4 last:border-0">
                <span @class([
                    'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                    'bg-ok-soft text-ok' => $stage->status === \App\Enums\StageStatus::Done,
                    'bg-sel-bg text-ink' => in_array($stage->status, [\App\Enums\StageStatus::InProgress, \App\Enums\StageStatus::Review], true),
                    'bg-error-soft text-error' => $stage->status === \App\Enums\StageStatus::Overdue,
                    'bg-neutral-soft text-black/40' => $stage->status === \App\Enums\StageStatus::NotStarted,
                ])>
                    @if ($stage->status === \App\Enums\StageStatus::Done) ✓ @else {{ $loop->iteration }} @endif
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ $stage->name }}</p>
                    @if ($stage->date_plan_end)
                        <p class="text-[12px] text-black/40">{{ $stage->date_plan_end->format('d.m.Y') }}</p>
                    @endif
                </div>
                <div class="hidden w-28 sm:block">
                    <div class="h-1.5 overflow-hidden rounded-pill bg-neutral-soft">
                        <div class="h-full rounded-pill bg-yellow-line" style="width: {{ (int) $stage->readiness }}%"></div>
                    </div>
                </div>
                <span @class([
                    'rounded-pill px-3 py-1 text-[12px] font-semibold',
                    'bg-ok-soft text-ok' => $stage->status === \App\Enums\StageStatus::Done,
                    'bg-warn-soft text-warn' => in_array($stage->status, [\App\Enums\StageStatus::InProgress, \App\Enums\StageStatus::Review], true),
                    'bg-error-soft text-error' => $stage->status === \App\Enums\StageStatus::Overdue,
                    'bg-neutral-soft text-black/50' => $stage->status === \App\Enums\StageStatus::NotStarted,
                ])>{{ $stage->status->translatedLabel() }}</span>
            </div>
        @empty
            <p class="px-5 py-10 text-center text-sm text-black/40">{{ t('portal.no_stages') }}</p>
        @endforelse
    </div>
</x-portal.shell>
