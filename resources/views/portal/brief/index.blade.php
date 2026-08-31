<x-portal.shell :title="t('portal.nav_brief')" :project="$project" active="brief">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ t('portal.nav_brief') }}</h1>
            <p class="mt-1 text-sm text-black/50">{{ t('portal.brief_intro') }}</p>
        </div>
        <div class="min-w-[220px] rounded-ds-md border border-black/10 bg-white px-5 py-3">
            <div class="mb-1.5 flex items-center justify-between text-[13px]">
                <span class="font-semibold text-black/60">{{ t('portal.brief_total_progress') }}</span>
                <span class="font-bold">{{ $brief->progress }}%</span>
            </div>
            <div class="h-1.5 overflow-hidden rounded-pill bg-neutral-soft">
                <div class="h-full bg-yellow-line" style="width: {{ $brief->progress }}%"></div>
            </div>
        </div>
    </div>

    @if ($brief->isCompleted())
        <div class="mb-6 rounded-ds-md border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
            {{ t('portal.brief_completed_note') }}
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($map as $entry)
            <a href="{{ route('portal.brief.section', array_filter([$project->id, $entry['section']->id, $entry['room']?->id])) }}"
                class="group rounded-ds-md border border-black/10 bg-white p-5 transition-colors hover:border-black/30">
                <div class="mb-3 flex items-start justify-between gap-2">
                    <h2 class="text-[15px] font-bold group-hover:underline">
                        {{ $entry['room']?->label ?? $entry['section']->getTranslation('name', app()->getLocale()) }}
                    </h2>
                    @if ($entry['status'] === 'submitted')
                        <span class="rounded-pill bg-ok-soft px-2.5 py-0.5 text-[11px] font-semibold text-ok">{{ t('portal.brief_submitted') }}</span>
                    @elseif ($entry['status'] === 'in_progress')
                        <span class="rounded-pill bg-warn-soft px-2.5 py-0.5 text-[11px] font-semibold text-warn">{{ t('portal.brief_in_progress') }}</span>
                    @endif
                </div>
                <div class="mb-1.5 flex items-center justify-between text-[12px] text-black/50">
                    <span>{{ $entry['answered_count'] }}/{{ $entry['question_count'] }}</span>
                    <span class="font-bold text-ink">{{ $entry['progress'] }}%</span>
                </div>
                <div class="h-1 overflow-hidden rounded-pill bg-neutral-soft">
                    <div class="h-full {{ $entry['status'] === 'submitted' ? 'bg-ok' : 'bg-yellow-line' }}" style="width: {{ $entry['progress'] }}%"></div>
                </div>
            </a>
        @endforeach
    </div>

    @unless ($brief->isCompleted())
        <div class="mt-8 rounded-ds-md border border-black/10 bg-white p-5">
            <h3 class="mb-3 text-sm font-bold">{{ t('portal.brief_add_room') }}</h3>
            <form method="post" action="{{ route('portal.brief.rooms.add', $project) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="mb-1.5 block text-[12px] font-semibold text-black/60">{{ t('portal.brief_room_type') }}</label>
                    <select name="room_type" required class="h-10 rounded-ds border border-black/20 px-3 text-sm">
                        @foreach ($roomSections as $rs)
                            <option value="{{ $rs->room_type }}">{{ $rs->getTranslation('name', app()->getLocale()) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-[12px] font-semibold text-black/60">{{ t('portal.brief_room_label') }}</label>
                    <input name="label" placeholder="{{ t('portal.brief_room_label_ph') }}"
                        class="h-10 rounded-ds border border-black/20 px-3 text-sm">
                </div>
                <button class="ui-btn ui-btn-dark h-10 px-4 text-[13px] font-semibold" data-hover="true">
                    {{ t('portal.brief_add') }}
                </button>
            </form>
        </div>
    @endunless
</x-portal.shell>
