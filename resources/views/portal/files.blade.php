<x-portal.shell :title="t('portal.nav_files')" :project="$project" active="files">
    @php
        // Mərhələ / kateqoriya süzgəci çip keçidlərində də saxlanılır — əks
        // halda çipə basmaq aktiv süzgəci səssizcə sıfırlayardı.
        $scope = array_filter(['stage' => $stage?->id, 'category' => $category?->value]);

        // Roomix-dəki çip sırası. `all` həmişə birincidir ki, müştəri filtrdən
        // asanlıqla geri qayıtsın.
        $chips = [
            'all' => [t('portal.files_all'), null],
            'media' => [t('portal.files_media'), $counts['media'] ?? 0],
            'files' => [t('portal.files_files'), $counts['files'] ?? 0],
            'links' => [t('portal.files_links'), $counts['links'] ?? 0],
            'docs' => [t('portal.files_docs'), $counts['docs'] ?? 0],
        ];

        $icons = [
            'image' => 'M3 3h18v18H3zM3 15l5-5 4 4 3-3 6 6',
            'visualization' => 'M3 3h18v18H3zM3 15l5-5 4 4 3-3 6 6',
            'plan' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5',
            'link' => 'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1',
            'other' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z',
        ];

        // Enum tam adı ilə yazılır: `use` ifadəsi komponent slotunun içində
        // PHP səviyyəsində qanunsuzdur.
        $stateChip = fn (?string $state) => match ($state) {
            'approved' => 'bg-ok-soft text-ok',
            'in_approval' => 'bg-warn-soft text-warn',
            'revision_required' => 'bg-error-soft text-error',
            default => 'bg-neutral-soft text-black/55',
        };
    @endphp

    <h1 class="mb-4 text-heading font-semibold">{{ t('portal.nav_files') }}</h1>

    {{-- Mərhələ və kateqoriya süzgəcləri. Seçim dəyişəndə forma özü göndərilir;
         JS yoxdursa «Tətbiq et» düyməsi var. --}}
    <form method="get" action="{{ route('portal.files', $project) }}" class="mb-3 flex flex-wrap items-end gap-3" data-files-filter>
        @if ($filter !== 'all')
            <input type="hidden" name="filter" value="{{ $filter }}">
        @endif
        <label class="flex min-w-[180px] flex-col gap-1 text-helper font-semibold text-black/60">
            {{ t('portal.files_filter_stage') }}
            <select name="stage" onchange="this.form.submit()"
                    class="h-10 rounded-ds border border-black/15 bg-card px-3 text-body font-normal text-ink">
                <option value="">{{ t('portal.files_filter_any') }}</option>
                @foreach ($stages as $option)
                    <option value="{{ $option->id }}" @selected($stage?->id === $option->id)>{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex min-w-[180px] flex-col gap-1 text-helper font-semibold text-black/60">
            {{ t('portal.files_filter_category') }}
            <select name="category" onchange="this.form.submit()"
                    class="h-10 rounded-ds border border-black/15 bg-card px-3 text-body font-normal text-ink">
                <option value="">{{ t('portal.files_filter_any') }}</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}" @selected($category?->value === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <noscript><button class="ui-btn ui-btn-primary h-10 px-4 text-[14px] font-semibold">{{ t('portal.files_filter_apply') }}</button></noscript>
    </form>

    @if ($stage || $category)
        {{-- Aktiv süzgəc görünür və bir kliklə sıfırlanır (TZ §4, qəbul 4). --}}
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-ds-lg border border-yellow-line bg-sel-bg px-4 py-2.5 text-helper" data-active-filter>
            <span class="font-semibold">{{ t('portal.files_active_filter') }}:</span>
            @if ($stage)
                <a href="{{ route('portal.files', [$project, ...array_filter(['filter' => $filter !== 'all' ? $filter : null, 'category' => $category?->value])]) }}"
                   class="inline-flex items-center gap-1 rounded-pill bg-card px-3 py-1 font-medium" title="{{ t('portal.files_filter_remove') }}">
                    {{ t('portal.files_filter_stage') }}: {{ $stage->name }} <span aria-hidden="true">×</span>
                </a>
            @endif
            @if ($category)
                <a href="{{ route('portal.files', [$project, ...array_filter(['filter' => $filter !== 'all' ? $filter : null, 'stage' => $stage?->id])]) }}"
                   class="inline-flex items-center gap-1 rounded-pill bg-card px-3 py-1 font-medium" title="{{ t('portal.files_filter_remove') }}">
                    {{ t('portal.files_filter_category') }}: {{ $categories[$category->value] ?? \App\Services\Portal\ClientMaterialCards::categoryLabel($category) }} <span aria-hidden="true">×</span>
                </a>
            @endif
            <a href="{{ route('portal.files', $project) }}" data-filter-reset class="ml-auto font-semibold underline underline-offset-2">{{ t('portal.files_filter_reset') }}</a>
        </div>
    @endif

    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($chips as $key => [$label, $count])
            @php $on = $filter === $key; @endphp
            <a href="{{ route('portal.files', [$project, ...$scope, ...($key === 'all' ? [] : ['filter' => $key])]) }}"
               @if ($on) aria-current="page" @endif
               class="flex items-center gap-1.5 rounded-pill border px-3.5 py-1.5 text-helper font-medium transition-colors
                      {{ $on ? 'border-ink bg-accent-dark text-white' : 'border-black/15 bg-card hover:border-black/35' }}">
                {{ $label }}
                @if ($count !== null && $count > 0)
                    <span class="text-[11px] font-bold {{ $on ? 'text-white/70' : 'text-black/45' }}">{{ $count }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="space-y-4">
        {{-- Hər kart bir materialdır: YALNIZ dərc edilmiş versiyanın faylları,
             müştəriyə açıq şərhlər və müştəriyə göndərilmiş razılaşdırmalar
             (hamısı serverdə süzülür). --}}
        @forelse ($files as $file)
            <article id="{{ $file['key'] }}" @if ($file['material_id']) data-material="{{ $file['material_id'] }}" @endif
                     class="scroll-mt-24 overflow-hidden rounded-ds-xl border border-black/8 bg-card">
                <a href="{{ $file['url'] }}"
                   class="group flex items-center gap-4 px-5 py-4 transition-colors hover:bg-neutral-soft/50">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-ink">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $icons[$file['icon']] ?? $icons['other'] }}"/>
                        </svg>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-body font-semibold">{{ $file['title'] }}</p>
                        <p class="mt-1 text-helper text-black/55">
                            {{ $file['label'] }}
                            @if ($file['stage']) · {{ $file['stage'] }} @endif
                            @if ($file['version']) · {{ $file['version'] }} @endif
                            @if ($file['date']) · {{ $file['date']->format('d.m.Y') }} @endif
                            @if ($file['size']) · {{ number_format($file['size'] / 1024, 0, '.', ' ') }} KB @endif
                        </p>
                    </div>

                    @if ($file['state'])
                        <span @class(['hidden shrink-0 rounded-pill px-3 py-1 text-helper font-medium sm:inline-block', $stateChip($file['state']['value'])])>
                            {{ $file['state']['label'] }}
                        </span>
                    @endif

                    <span class="shrink-0 text-black/45 transition-colors group-hover:text-ink" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 19h16"/></svg>
                    </span>
                </a>

                @if ($file['state'])
                    <p class="px-5 pb-2 sm:hidden">
                        <span @class(['rounded-pill px-3 py-1 text-helper font-medium', $stateChip($file['state']['value'])])>{{ $file['state']['label'] }}</span>
                    </p>
                @endif

                @if (count($file['files']) > 1)
                    <ul class="space-y-1 px-5 pb-3 text-helper">
                        @foreach ($file['files'] as $attachment)
                            <li><a href="{{ $attachment['url'] }}" class="underline">{{ $attachment['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif

                {{-- Müştərinin qərarı elə kartdadır (ssenari 2): «Təsdiqlə» /
                     «Düzəliş tələb et». Qərarı mövcud `approvals.decide` yazır —
                     düzəliş tələbində şərh məcburidir. --}}
                @if ($file['pending'])
                    @php $pending = $file['pending']; @endphp
                    <div class="border-t border-yellow-line bg-sel-bg px-5 py-4" data-material-approval="{{ $pending->id }}">
                        <p class="text-body font-semibold">
                            {{ t('portal.material_awaiting_decision') }}
                            @if ($pending->deliverableVersion) (v{{ $pending->deliverableVersion->version_number }}) @endif
                        </p>
                        <p class="mt-0.5 text-helper text-black/60">
                            {{ $pending->created_at->format('d.m.Y') }}
                            @if ($pending->respond_by)
                                · <span class="{{ $pending->isOverdue() ? 'font-semibold text-error' : '' }}">{{ t('portal.respond_by') }}: {{ $pending->respond_by->format('d.m.Y') }}</span>
                            @endif
                        </p>
                        @if ($pending->note)
                            <p class="mt-2 whitespace-pre-line text-body text-black/70">{{ $pending->note }}</p>
                        @endif
                        @if ($file['pending_version_url'])
                            <a href="{{ $file['pending_version_url'] }}" class="mt-2 inline-flex text-helper font-semibold underline">{{ t('portal.approval_open_file') }}</a>
                        @endif

                        @if (! $writable)
                            {{-- Arxiv: yalnız baxış. --}}
                        @elseif ($pending->hasVariants())
                            <a href="{{ route('portal.approvals', $project) }}#approval-{{ $pending->id }}" class="ui-btn ui-btn-primary mt-3 inline-flex h-10 items-center px-5 text-[14px] font-semibold" data-hover="true">
                                {{ t('portal.choose_variant') }}
                            </a>
                        @else
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <form method="post" action="{{ route('portal.approvals.decide', $pending) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <button class="ui-btn ui-btn-primary h-10 px-5 text-[14px] font-semibold" data-hover="true">{{ t('portal.material_approve') }}</button>
                                </form>
                                <button type="button"
                                        onclick="this.closest('[data-material-approval]').querySelector('[data-reject-form]').toggleAttribute('hidden')"
                                        class="ui-btn ui-btn-danger h-10 px-5 text-[14px] font-semibold" data-hover="true">
                                    {{ t('portal.material_request_revision') }}
                                </button>
                            </div>
                            <form method="post" action="{{ route('portal.approvals.decide', $pending) }}" data-reject-form hidden class="mt-3">
                                @csrf
                                <input type="hidden" name="decision" value="reject">
                                <label class="mb-1.5 block text-helper font-semibold">{{ t('portal.material_revision_reason') }}</label>
                                <textarea name="comment" rows="2" required maxlength="2000"
                                          class="w-full rounded-ds border border-black/20 bg-card px-3.5 py-2.5 text-body outline-none focus:border-ink"></textarea>
                                <button class="ui-btn ui-btn-danger mt-2 h-10 px-5 text-[14px] font-semibold" data-hover="true">{{ t('portal.material_send_revision') }}</button>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($file['material_id'])
                    {{-- Şərhlər: yalnız müştəriyə açıq olanlar (daxili şərh serverdə süzülür). --}}
                    <div class="border-t border-black/5 px-5 py-4" data-material-comments>
                        <p class="mb-2 text-helper font-semibold text-black/60">{{ t('portal.material_comments') }} ({{ $file['version'] }})</p>
                        @forelse ($file['comments'] as $comment)
                            <div class="mb-2 rounded-ds-lg {{ $comment['mine'] ? 'bg-sel-bg' : 'bg-neutral-soft/60' }} px-3.5 py-2.5 text-body">
                                <p class="text-helper text-black/55">
                                    <span class="font-semibold text-black/75">{{ $comment['author'] ?? '—' }}</span>
                                    @if ($comment['date']) · {{ $comment['date']->format('d.m.Y H:i') }} @endif
                                </p>
                                <p class="mt-0.5 whitespace-pre-line">{{ $comment['body'] }}</p>
                            </div>
                        @empty
                            <p class="mb-2 text-helper text-black/45">{{ t('portal.material_no_comments') }}</p>
                        @endforelse

                        @if ($writable)
                            <form method="post" action="{{ $file['comment_url'] }}" class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-start">
                                @csrf
                                <label for="comment-{{ $file['material_id'] }}" class="sr-only">{{ t('portal.material_comment_placeholder') }}</label>
                                <textarea id="comment-{{ $file['material_id'] }}" name="body" rows="2" required maxlength="2000"
                                          placeholder="{{ t('portal.material_comment_placeholder') }}"
                                          class="min-w-0 flex-1 rounded-ds border border-black/20 px-3.5 py-2.5 text-body outline-none focus:border-ink"></textarea>
                                <button class="ui-btn ui-btn-primary h-10 shrink-0 px-5 text-[14px] font-semibold" data-hover="true">{{ t('portal.material_comment_send') }}</button>
                            </form>
                        @endif
                    </div>
                @endif

                {{-- Razılaşdırma tarixçəsi: versiya, kim, tarix, nəticə — yalnız
                     müştəriyə göndərilmiş dövrlər. --}}
                @if (count($file['history']))
                    <details class="border-t border-black/5 px-5 py-3" data-material-history>
                        <summary class="cursor-pointer text-helper font-semibold text-black/55">
                            {{ t('portal.approval_history', ['count' => count($file['history'])]) }}
                        </summary>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($file['history'] as $entry)
                                <li class="flex flex-wrap items-baseline gap-2 text-helper text-black/60">
                                    <span class="font-semibold text-black/75">v{{ $entry['version'] }}</span>
                                    <span>{{ $entry['date']?->format('d.m.Y') }}</span>
                                    <span>· {{ $entry['status_label'] }}</span>
                                    @if ($entry['who'])<span>· {{ $entry['who'] }}</span>@endif
                                    @if ($entry['comment'])<span class="min-w-0">· {{ $entry['comment'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </article>
        @empty
            <div class="rounded-ds-xl border border-black/8 bg-card px-5 py-12 text-center text-body text-black/55">
                {{ ($stage || $category) ? t('portal.files_filter_empty') : t('portal.no_files') }}
            </div>
        @endforelse
    </div>
</x-portal.shell>
