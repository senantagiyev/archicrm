<x-portal.shell :title="t('portal.nav_brief')" :project="$project" active="brief">
    @php
        $status = $brief->statusEnum();

        // Roomix-dəki dörd addımlı yol: müştəri brifi göndərdikdən sonra
        // növbəti nəyin gözlənildiyini bilsin. Addımın vəziyyəti brifin
        // statusundan çıxarılır — ayrıca sahə saxlanmır.
        $step = match (true) {
            $brief->isApproved() => 4,
            $brief->needsClarification() => 2,
            default => 2,
        };
        $steps = [
            t('portal.brief_step_sent'),
            t('portal.brief_step_spec'),
            t('portal.brief_step_approval'),
            t('portal.brief_step_signing'),
        ];
    @endphp

    <div class="mx-auto max-w-[640px]">
        <ol class="mb-6 flex items-stretch gap-1.5" aria-label="{{ t('portal.nav_brief') }}">
            @foreach ($steps as $i => $label)
                @php $n = $i + 1; $done = $n < $step; $active = $n === $step; @endphp
                <li class="flex-1">
                    <span class="block h-1 rounded-pill {{ $done || $active ? 'bg-yellow-line' : 'bg-neutral-soft' }}"></span>
                    <span class="mt-2 flex items-center gap-1.5 text-[13px] leading-tight
                                 {{ $active ? 'font-semibold text-ink' : ($done ? 'text-black/55' : 'text-black/35') }}">
                        <span class="flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-full text-[11px] font-bold
                                     {{ $done ? 'bg-ok text-white' : ($active ? 'bg-ink text-white' : 'bg-neutral-soft text-black/45') }}">
                            {{ $done ? '✓' : $n }}
                        </span>
                        <span class="min-w-0">{{ $label }}</span>
                    </span>
                </li>
            @endforeach
        </ol>

        <div class="rounded-[18px] border border-black/8 bg-white p-8 text-center sm:p-10">
            @if ($brief->needsClarification())
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-sel-bg text-2xl">✎</span>
                <h1 class="mt-5 font-b2b text-[24px] font-extrabold tracking-tight">{{ t('portal.brief_needs_clar_title') }}</h1>
                <p class="mt-2 text-[14px] leading-relaxed text-black/60">{{ t('portal.brief_needs_clar_body', ['count' => $openComments]) }}</p>
                <a href="{{ route('portal.brief.clarifications', $project) }}" class="ui-btn ui-btn-primary mt-6 h-11 px-6 text-sm font-bold" data-hover="true">
                    {{ t('portal.brief_answer_clarifications') }} →
                </a>
            @elseif ($brief->isApproved())
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-ok-soft text-2xl text-ok">✓</span>
                <h1 class="mt-5 font-b2b text-[24px] font-extrabold tracking-tight">{{ t('portal.brief_approved_title') }}</h1>
                <p class="mt-2 text-[14px] leading-relaxed text-black/60">{{ t('portal.brief_approved_body') }}</p>
            @else
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-ok-soft text-2xl text-ok">✓</span>
                <h1 class="mt-5 font-b2b text-[24px] font-extrabold tracking-tight">{{ t('portal.brief_sent_title') }}</h1>
                <p class="mt-2 text-[14px] leading-relaxed text-black/60">{{ t('portal.brief_sent_body') }}</p>
                <p class="mt-1 text-[13px] text-black/45">{{ t('portal.brief_completed_note') }}</p>
            @endif

            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('portal.projects.show', $project) }}" class="ui-btn ui-btn-dark h-11 px-6 text-sm font-bold" data-hover="true">
                    {{ t('portal.brief_back_to_project') }}
                </a>
                <a href="{{ route('portal.brief', $project) }}" class="ui-btn ui-btn-outline h-11 px-6 text-sm font-semibold" data-hover="true">
                    {{ t('portal.brief_view_answers') }}
                </a>
            </div>

            <p class="mt-6 text-[13px] text-black/45">
                {{ t('portal.brief_status_label') }}: <span class="font-semibold text-ink">{{ $status->label() }}</span>
                @if ($brief->current_version) · v{{ $brief->current_version }} @endif
            </p>
        </div>

        {{-- «Quick summary»: dizaynerə nəyin yola düşdüyünü müştəri bir baxışda görür. --}}
        @if ($summary !== [])
            <div class="mt-4 rounded-[18px] border border-black/8 bg-white p-5">
                <h2 class="mb-3 text-[13px] font-semibold text-black/55">{{ t('portal.brief_quick_summary') }}</h2>
                <dl class="grid gap-x-6 gap-y-2.5 sm:grid-cols-2">
                    @foreach ($summary as $label => $value)
                        <div class="flex items-baseline justify-between gap-3 border-b border-black/5 pb-2 last:border-0">
                            <dt class="shrink-0 text-[13px] text-black/50">{{ $label }}</dt>
                            <dd class="min-w-0 text-right text-[14px] font-semibold">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </div>
</x-portal.shell>
