<x-portal.shell :title="t('portal.nav_brief')" :project="$project" active="brief">
    @php $status = $brief->statusEnum(); @endphp

    <div class="mx-auto max-w-[640px]">
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

            <p class="mt-6 text-[12px] text-black/40">
                {{ t('portal.brief_status_label') }}: <span class="font-semibold text-ink">{{ $status->label() }}</span>
                @if ($brief->current_version) · v{{ $brief->current_version }} @endif
            </p>
        </div>
    </div>
</x-portal.shell>
