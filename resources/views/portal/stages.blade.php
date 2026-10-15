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

    <div class="overflow-hidden rounded-ds-xl border border-black/8 bg-card">
        @forelse ($stages as $stage)
            <div id="stage-{{ $stage->id }}" data-stage="{{ $stage->id }}"
                 class="flex scroll-mt-24 flex-col gap-3 border-b border-black/5 px-5 py-4 last:border-0 sm:flex-row sm:items-center sm:gap-4">
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-helper font-semibold',
                    $chip($stage->status),
                ])>
                    @if ($stage->status === \App\Enums\StageStatus::Done) ✓ @else {{ $loop->iteration }} @endif
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-body font-semibold">{{ $stage->name }}</p>
                    {{-- Plan tarixləri: başlanğıc — bitmə. Gecikmə plan bitməsinə görədir. --}}
                    @if ($stage->date_plan_start || $stage->date_plan_end)
                        <p class="mt-0.5 text-helper text-black/55">
                            {{ t('portal.stage_plan') }}: {{ $stage->date_plan_start?->format('d.m.Y') ?? '…' }} — {{ $stage->date_plan_end?->format('d.m.Y') ?? '…' }}
                        </p>
                    @endif
                    {{-- «Materiallar — N» → «Fayllar» bu mərhələyə görə süzülmüş.
                         Yalnız müştəriyə dərc edilmiş materiallar sayılır. --}}
                    @if ($stage->published_materials_count > 0)
                        <a href="{{ route('portal.files', [$project, 'stage' => $stage->id]) }}" data-stage-materials="{{ $stage->id }}"
                           class="mt-1.5 inline-flex items-center gap-1 text-helper font-semibold text-ink underline underline-offset-2">
                            {{ t('portal.stage_materials', ['count' => $stage->published_materials_count]) }} · {{ t('portal.stage_open_materials') }} →
                        </a>
                    @else
                        <p class="mt-1.5 text-helper text-black/45" data-stage-materials="{{ $stage->id }}">{{ t('portal.stage_materials', ['count' => 0]) }}</p>
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
                        {{ $stage->status->translatedLabel() }}
                    </span>
                </span>
            </div>
        @empty
            <p class="px-5 py-12 text-center text-body text-black/55">{{ t('portal.no_stages') }}</p>
        @endforelse
    </div>
</x-portal.shell>
