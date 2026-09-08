<x-portal.shell :title="t('portal.brief_clarifications_title')" :project="$project" active="brief">
    <div class="mx-auto max-w-[760px]">
        <a href="{{ route('portal.brief', $project) }}" class="text-[13px] font-semibold text-black/50 hover:text-ink">← {{ t('portal.brief_back_to_map') }}</a>
        <h1 class="mt-1 font-b2b text-[26px] font-extrabold tracking-tight">{{ t('portal.brief_clarifications_title') }}</h1>
        <p class="mt-2 text-[14px] leading-relaxed text-black/60">{{ t('portal.brief_clarifications_intro') }}</p>

        @if ($comments->isEmpty())
            <div class="mt-8 rounded-[16px] border border-black/8 bg-white p-10 text-center text-sm text-black/50">
                {{ t('portal.brief_no_clarifications') }}
            </div>
        @else
            <div class="mt-6 space-y-3">
                @foreach ($comments as $comment)
                    @php
                        $section = $comment->question?->section;
                        $url = $section
                            ? route('portal.brief.section', array_filter([$project->id, $section->id, $comment->brief_room_id]))
                            : route('portal.brief', $project);
                    @endphp
                    <div class="rounded-[16px] border border-yellow-line bg-white p-5">
                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-black/40">
                            {{ $comment->room?->label ?? $section?->getTranslation('name', app()->getLocale()) }}
                        </p>
                        <p class="mt-1 text-[15px] font-bold">{{ $comment->question?->getTranslation('label', app()->getLocale()) }}</p>
                        <div class="mt-3 rounded-ds-md bg-sel-bg px-4 py-3 text-[13px]">
                            <span class="font-bold">{{ t('portal.brief_designer_asks') }}:</span> {{ $comment->body }}
                            <span class="ml-1 text-black/45">— {{ $comment->user?->name }}, {{ $comment->created_at->format('d.m.Y') }}</span>
                        </div>
                        <a href="{{ $url }}" class="ui-btn ui-btn-outline mt-4 h-10 px-5 text-[13px] font-semibold" data-hover="true">
                            {{ t('portal.brief_answer') }} →
                        </a>
                    </div>
                @endforeach
            </div>

            <div class="mt-8 rounded-[16px] border border-black/8 bg-white p-6">
                <p class="text-[13px] text-black/55">{{ t('portal.brief_readonly_note') }}</p>
                <form method="post" action="{{ route('portal.brief.clarifications.send', $project) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="ui-btn ui-btn-primary h-12 w-full text-sm font-bold sm:w-auto sm:px-8" data-hover="true">
                        {{ t('portal.brief_send_clarifications') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-portal.shell>
