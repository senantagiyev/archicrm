<x-portal.shell :title="t('portal.nav_stages')" :project="$project" active="stages">
    @php
        // Roomix mərhələni rəngli status çipi ilə göstərir; Archi palitrasında
        // eyni məntiq: bitmiş — yaşıl, işdə — sarı vurğu, gecikmiş — qırmızı.
        // Enum tam adı ilə yazılır: `use` ifadəsi komponent slotunun içində
        // PHP səviyyəsində qanunsuzdur.
        $chip = fn ($s) => match ($s) {
            \App\Enums\StageStatus::Done => 'bg-ok-soft text-ok',
            \App\Enums\StageStatus::Overdue => 'bg-error-soft text-error',
            \App\Enums\StageStatus::InProgress, \App\Enums\StageStatus::Review => 'bg-sel-bg text-ink',
            default => 'bg-neutral-soft text-black/55',
        };
    @endphp

    <div class="mb-5">
        <h1 class="text-heading font-semibold">{{ t('portal.nav_stages') }}</h1>
        <p class="mt-1 text-helper text-black/55">{{ t('portal.stages_intro') }}</p>
    </div>

    <div class="overflow-hidden rounded-ds-xl border border-black/8 bg-white">
        @forelse ($stages as $stage)
            <div class="flex flex-col gap-3 border-b border-black/5 px-5 py-4 last:border-0 sm:flex-row sm:items-center sm:gap-4">
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-helper font-semibold',
                    $chip($stage->status),
                ])>
                    @if ($stage->status === \App\Enums\StageStatus::Done) ✓ @else {{ $loop->iteration }} @endif
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-body font-semibold">{{ $stage->name }}</p>
                    @if ($stage->date_plan_end)
                        <p class="mt-0.5 text-helper text-black/55">{{ $stage->date_plan_end->format('d.m.Y') }}</p>
                    @endif
                </div>

                <span class="flex flex-wrap items-center gap-3 sm:shrink-0">
                    @if ($stage->responsible)
                        <span class="flex items-center gap-2 text-helper text-black/60">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-neutral-soft text-[11px] font-bold uppercase">
                                {{ mb_substr($stage->responsible->name, 0, 2) }}
                            </span>
                            {{ $stage->responsible->name }}
                        </span>
                    @endif
                    <span @class(['rounded-pill px-3 py-1 text-helper font-medium', $chip($stage->status)])>
                        {{ $stage->status->label() }}
                    </span>
                </span>
            </div>
        @empty
            <p class="px-5 py-12 text-center text-body text-black/55">{{ t('portal.no_stages') }}</p>
        @endforelse
    </div>
</x-portal.shell>
