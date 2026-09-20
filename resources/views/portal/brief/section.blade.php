@php
    $locale = app()->getLocale();
    $sectionTitle = $room?->label ?? $section->getTranslation('name', $locale);
    // Screen 13: a locked brief keeps only clarification-flagged questions editable.
    $briefLocked = $brief->isLocked();
    $editableIds = $editableQuestionIds ?? [];
    $comments = $comments ?? collect();
    $anyEditable = ! $briefLocked || $editableIds !== [];
    $completed = $briefLocked;
    $optLabel = fn ($option) => $option['label'][$locale] ?? $option['label']['az'] ?? $option['value'];
@endphp

@php
    // Stepper YALNIZ əsas bölmələri sayır. Otaqlar xəritədə ayrıca sətirlərdir
    // (Mətbəx, Uşaq otağı 1…); onları da nömrələsək addım sayı otaq sayından
    // asılı olaraq dəyişərdi və «N-ci addım M-dən» mənasını itirərdi. Otaq
    // açıqdırsa, onun bölməsi aktiv sayılır, otaqlar isə ikinci sətirdə çipdir.
    $steps = collect($map)->filter(fn ($e) => $e['room'] === null)->values();
    $roomEntries = collect($map)->filter(fn ($e) => $e['room'] !== null)->values();
    $currentStep = $steps->search(fn ($e) => $e['section']->id === $section->id);
    $stepNumber = $currentStep === false ? null : $currentStep + 1;
@endphp

<x-portal.shell :title="$sectionTitle" :project="$project" active="brief">
    {{-- Addım naviqasiyası: harada olduğun və neçəsinin qaldığı həmişə görünür. --}}
    {{-- Zolaq addım göstəricisidir, idarə paneli deyil: tamamlanmış addım
         SAKİT fərqlənir — nömrə yerində qalır, rəng bir pillə tündləşir və
         dairənin küncündə kiçik onay nişanı çıxır. Mətnin yanında ayrıca
         böyük «✓» YOXDUR (əvvəlki yaşıl qlif ucuz görünürdü). --}}
    <nav class="brief-steps -mx-5 mb-6 overflow-x-auto border-b border-black/8 px-5 lg:-mx-8 lg:px-8" aria-label="{{ t('portal.nav_brief') }}">
        <ol class="flex min-w-max items-stretch gap-1">
            @foreach ($steps as $i => $entry)
                @php
                    $isCurrent = $entry['section']->id === $section->id;
                    $isDone = $entry['status'] === 'submitted' || $entry['progress'] >= 100;
                @endphp
                <li>
                    <a href="{{ route('portal.brief.section', [$project->id, $entry['section']->id]) }}"
                       @if ($isCurrent) aria-current="step" @endif
                       class="flex items-center gap-2.5 whitespace-nowrap border-b-2 px-3 py-3 text-[14px] transition-colors
                              {{ $isCurrent
                                  ? 'border-yellow font-semibold text-ink'
                                  : ($isDone
                                      ? 'border-transparent font-medium text-ink/70 hover:text-ink'
                                      : 'border-transparent font-medium text-black/40 hover:text-ink') }}">
                        <span class="relative flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold
                                     {{ $isCurrent ? 'bg-ink text-white' : ($isDone ? 'bg-ink/12 text-ink/75' : 'bg-neutral-soft text-black/40') }}">
                            {{ $i + 1 }}
                            @if ($isDone && ! $isCurrent)
                                <span aria-hidden="true"
                                      class="absolute -bottom-px -right-px flex h-[11px] w-[11px] items-center justify-center rounded-full bg-ink/70 text-white ring-[1.5px] ring-gray-soft2">
                                    <svg width="6" height="6" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6.3 4.6 8.9 10 3.2"/></svg>
                                </span>
                            @endif
                        </span>
                        {{ $entry['section']->getTranslation('name', $locale) }}
                        @if ($isDone && ! $isCurrent)<span class="sr-only">— {{ t('portal.brief_submitted') }}</span>@endif
                    </a>
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- Native üfüqi scrollbar zolağın bütün enini tutur və dizaynı pozur.
         CSS faylına toxunmadan yalnız bu naviqasiya üçün nazik, səssiz zolaq. --}}
    <style>
        /* Chrome/Safari: `scrollbar-width` standart xassəsi qoyulsa, brauzer
           ::-webkit-scrollbar üslublarını TAMAMİLƏ nəzərə almır (thin = 11px
           qalır). Ona görə webkit-də yalnız pseudo-elementlə 4px veririk,
           `scrollbar-width` isə yalnız onu dəstəkləməyən Firefox-a düşür. */
        .brief-steps::-webkit-scrollbar { height: 4px; }
        .brief-steps::-webkit-scrollbar-track { background: transparent; }
        .brief-steps::-webkit-scrollbar-thumb { background: rgba(17, 17, 17, .16); border-radius: 100px; }
        .brief-steps::-webkit-scrollbar-thumb:hover { background: rgba(17, 17, 17, .3); }
        @supports not selector(::-webkit-scrollbar) {
            .brief-steps { scrollbar-width: thin; scrollbar-color: rgba(17, 17, 17, .18) transparent; }
        }
    </style>

    <div class="min-w-0">
            <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <a href="{{ route('portal.brief', $project) }}" class="text-[13px] font-semibold text-black/50 hover:text-ink">← {{ t('portal.brief_back_to_map') }}</a>
                    @if ($stepNumber)
                        <p class="mt-2 text-[13px] font-semibold text-black/45">
                            {{ $stepNumber }} / {{ $steps->count() }}
                        </p>
                    @endif
                    <h1 class="mt-1 text-2xl font-bold">{{ $sectionTitle }}</h1>
                    @if ($intro = $section->getTranslation('intro', $locale))
                        <p class="mt-1.5 max-w-2xl text-[14px] leading-relaxed text-black/55">{{ $intro }}</p>
                    @endif
                </div>
                <span id="saveState" class="text-[13px] font-medium text-black/40"></span>
            </div>

            {{-- Otaq çipləri: otaq bölməsindəykən hansı otaqda olduğun görünsün. --}}
            @if ($roomEntries->isNotEmpty() && ($room !== null || $section->isRoomSection()))
                <div class="mb-5 flex flex-wrap gap-2">
                    @foreach ($roomEntries as $entry)
                        @php $isCurrentRoom = $entry['room']->id === $room?->id; @endphp
                        <a href="{{ route('portal.brief.section', [$project->id, $entry['section']->id, $entry['room']->id]) }}"
                           class="rounded-pill border px-3.5 py-1.5 text-[13px] font-semibold transition-colors
                                  {{ $isCurrentRoom ? 'border-ink bg-ink text-white' : 'border-black/15 bg-white text-black/60 hover:border-black/35' }}">
                            {{ $entry['room']->label }}
                            <span class="{{ $isCurrentRoom ? 'text-yellow' : 'text-black/35' }}">{{ $entry['progress'] }}%</span>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-ds-md border border-danger/30 bg-danger-soft px-4 py-3 text-sm font-medium text-danger">
                    {{ $errors->first() }}
                </div>
            @endif
            @if (session('status'))
                <div class="mb-5 rounded-ds-md border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
                    {{ session('status') }}
                </div>
            @endif

            <form id="briefForm" method="post"
                action="{{ route('portal.brief.submit', [$project->id, $section->id]) }}"
                data-autosave-url="{{ route('portal.brief.autosave', [$project->id, $section->id]) }}"
                data-upload-url="{{ route('portal.brief.upload', [$project->id, $section->id]) }}"
                data-room-id="{{ $room?->id }}"
                class="space-y-5">
                @csrf
                <input type="hidden" name="room_id" value="{{ $room?->id }}">

                @foreach ($section->questions as $question)
                    @php
                        $answer = $answers->get($question->id);
                        $delegated = $answer?->delegated_to_designer ?? false;
                        $value = $answer?->value;
                        $visible = $question->shouldShow($values);
                        $comment = $comments->get($question->id);
                        $completed = $briefLocked && ! in_array($question->id, $editableIds, true);

                        // Alt başlıq qrup DƏYİŞƏNDƏ bir dəfə çıxır. Qruplar bankda
                        // ardıcıl verilib, ona görə sadə müqayisə kifayətdir və
                        // əlavə çeşidləmə sualların sırasını pozmur.
                        $groupChanged = filled($question->group) && $question->group !== ($lastGroup ?? null);
                        $lastGroup = $question->group ?: ($lastGroup ?? null);
                    @endphp

                    @if ($groupChanged)
                        <h2 class="{{ $loop->first ? '' : 'mt-8' }} border-l-2 border-yellow pl-3 text-[13px] font-semibold text-black/55">
                            {{ $question->group }}
                        </h2>
                    @endif

                    <div class="rounded-ds-md border {{ $comment ? 'border-yellow-line ring-2 ring-yellow/40' : 'border-black/10' }} bg-white p-5" data-question="{{ $question->id }}"
                        data-key="{{ $question->key }}" data-type="{{ $question->type }}"
                        @unless ($visible) hidden @endunless
                        @if (! empty($question->skip_logic['question'])) data-skip="{{ json_encode($question->skip_logic) }}" @endif>
                        @if ($comment)
                            <div class="mb-3 rounded-ds-md bg-sel-bg px-3 py-2 text-[13px]">
                                <span class="font-bold">{{ t('portal.brief_designer_asks') }}:</span>
                                {{ $comment->body }}
                                <span class="ml-1 text-black/45">— {{ $comment->user?->name }}</span>
                            </div>
                        @endif
                        {{-- Dar ekranda «dizaynerin ixtiyarına» qeydi sualın altına düşür:
                             `shrink-0` ilə yan-yana saxlasaq, 375px-də etiket kartın
                             sağ kənarından kənara çıxırdı. --}}
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                            <label class="text-[15px] font-semibold leading-snug">
                                {{ $question->getTranslation('label', $locale) }}
                                @if ($question->is_required)<span class="text-danger">*</span>@endif
                            </label>
                            @if ($question->allows_designer_choice && ! $completed)
                                <label class="flex cursor-pointer items-center gap-2 text-[13px] font-medium text-black/50 sm:shrink-0">
                                    <input type="checkbox" data-delegate {{ $delegated ? 'checked' : '' }} class="accent-ink">
                                    {{ t('portal.brief_delegate') }}
                                </label>
                            @endif
                        </div>

                        @if ($question->getTranslation('help', $locale))
                            <p class="mb-3 rounded-ds bg-sel-bg px-3 py-2 text-[13px] leading-relaxed text-black/60">{{ $question->getTranslation('help', $locale) }}</p>
                        @endif

                        <div data-input-zone class="{{ $delegated ? 'pointer-events-none opacity-40' : '' }}">
                            @switch($question->type)
                                @case('textarea')
                                    <textarea data-field rows="3" {{ $completed ? 'disabled' : '' }}
                                        class="w-full rounded-ds border border-black/20 px-3.5 py-2.5 text-sm outline-none focus:border-ink">{{ is_array($value) ? implode("\n", $value) : $value }}</textarea>
                                    @break
                                {{-- select / multiselect: qısa siyahılar «pill», uzunları isə
                                     tam enli hüceyrə sətirləri kimi verilir. 15-20 variantı
                                     yan-yana pill kimi yığmaq onları oxunmaz edirdi; sətir
                                     formatı həm də B patterni üçün ilham ikonuna yer açır. --}}
                                @case('select')
                                @case('multiselect')
                                    @php
                                        $isMulti = $question->type === 'multiselect';
                                        $selected = $isMulti ? (array) $value : [$value];
                                        $options = $question->options ?? [];
                                        $asRows = count($options) > 6 || $question->supports_inspiration;
                                    @endphp

                                    {{-- C patterni: işığın temperaturu rəqəmlə (2700K…5000K) çətin
                                         təsəvvür olunur, ona görə variantların üstündə isti→soyuq
                                         qradiyent verilir. Şəkil faylı lazım deyil, saf CSS-dir. --}}
                                    @if ($question->key === 'light_temperature')
                                        <div class="mb-3 overflow-hidden rounded-ds" aria-hidden="true">
                                            <div class="h-9 w-full" style="background:linear-gradient(90deg,#f6c67a 0%,#ffe0b8 28%,#fff6e8 50%,#eef3ff 75%,#cfe0ff 100%)"></div>
                                            <div class="flex justify-between px-1 pt-1 text-[13px] font-medium text-black/45">
                                                <span>{{ t('portal.brief_warm') }}</span>
                                                <span>{{ t('portal.brief_cold') }}</span>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="{{ $asRows ? 'grid gap-2 sm:grid-cols-2' : 'flex flex-wrap gap-2' }}"
                                         @if ($isMulti) data-multi-choice @else data-single-choice @endif>
                                        @foreach ($options as $option)
                                            @php
                                                $isOn = in_array($option['value'], $selected, true);
                                                // Yollar burada URL-ə çevrilir ki, modal JS-i
                                                // storage konfiqurasiyasından xəbərsiz qalsın.
                                                $optImages = collect((array) ($option['images'] ?? []))
                                                    ->filter()
                                                    ->map(fn ($p) => storage_url($p))
                                                    ->values()
                                                    ->all();
                                            @endphp
                                            <div class="{{ $asRows ? 'flex items-stretch' : '' }}">
                                                <button type="button" data-choice value="{{ $option['value'] }}" {{ $completed ? 'disabled' : '' }}
                                                    class="{{ $asRows
                                                        ? 'flex flex-1 items-center gap-2.5 rounded-ds border px-3.5 py-2.5 text-left text-[14px] font-medium transition-colors'
                                                        : 'rounded-pill border px-4 py-2 text-[14px] font-medium transition-colors' }}
                                                        {{ $isOn ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">
                                                    @if ($asRows)
                                                        <span class="flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-[4px] border text-[11px] leading-none
                                                                     {{ $isOn ? 'border-yellow bg-yellow text-ink' : 'border-black/25' }}">@if ($isOn)✓@endif</span>
                                                    @endif
                                                    {{-- C patterni: metal kimi variantlarda rəngi sözlə izah etmək
                                                         çətindir — variantın öz çipi göstərilir. Rəngi olmayan
                                                         variant («Dizaynerin ixtiyarına») çipsiz qalır. --}}
                                                    @php $optColors = array_values(array_filter((array) ($option['colors'] ?? []))); @endphp
                                                    @if ($optColors !== [])
                                                        <span class="flex h-[18px] w-[18px] shrink-0 overflow-hidden rounded-full border border-black/15" aria-hidden="true">
                                                            @foreach ($optColors as $hex)
                                                                <span class="h-full flex-1" style="background-color: {{ $hex }}"></span>
                                                            @endforeach
                                                        </span>
                                                    @endif
                                                    <span class="min-w-0">{{ $optLabel($option) }}</span>
                                                </button>

                                                {{-- B patterni: şəkil seçimə təsir etmir, yalnız nümunə göstərir.
                                                     Şəkil yüklənməyibsə ikon ÜMUMİYYƏTLƏ render olunmur —
                                                     boş modal açan düymə göstərmirik. --}}
                                                @if ($optImages !== [])
                                                    <button type="button"
                                                        data-inspire='@json($optImages)'
                                                        data-inspire-title="{{ $optLabel($option) }}"
                                                        aria-label="{{ $optLabel($option) }} — {{ t('portal.brief_inspiration') }}"
                                                        class="ml-1.5 flex w-10 shrink-0 items-center justify-center rounded-ds border border-black/15 bg-white text-black/45 transition-colors hover:border-black/35 hover:text-ink">
                                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                                    </button>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    @break

                                {{-- A patterni: seçimin ÖZÜ şəkildir (üslub kartları). --}}
                                @case('image_select')
                                @case('image_multiselect')
                                    @php
                                        $isMultiImage = $question->type === 'image_multiselect';
                                        $selectedImages = $isMultiImage ? (array) $value : [$value];
                                    @endphp
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3"
                                         @if ($isMultiImage) data-multi-choice @else data-single-choice @endif>
                                        @foreach ($question->options ?? [] as $option)
                                            @php
                                                $isOn = in_array($option['value'], $selectedImages, true);
                                                $img = $option['image_url'] ?? null;
                                            @endphp
                                            <button type="button" data-choice data-image-card value="{{ $option['value'] }}"
                                                {{ $completed ? 'disabled' : '' }} @if ($isOn) data-selected @endif
                                                class="group relative overflow-hidden rounded-ds-md border-2 text-left transition-all
                                                       {{ $isOn ? 'border-yellow shadow-[0_0_0_3px_rgba(253,254,0,.35)]' : 'border-black/10 hover:border-black/30' }}">
                                                <span class="block aspect-[4/3] w-full bg-neutral-soft bg-cover bg-center"
                                                    @if ($img) style="background-image:url('{{ storage_url($img) }}')" @endif>
                                                    @unless ($img)
                                                        {{-- Şəkil hələ yüklənməyib: kart öz quruluşunu saxlayır. --}}
                                                        <span class="flex h-full w-full items-center justify-center text-black/20">
                                                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                                        </span>
                                                    @endunless
                                                </span>
                                                <span data-card-check
                                                    class="absolute right-2 top-2 flex h-6 w-6 items-center justify-center rounded-full bg-yellow text-[12px] font-bold text-ink {{ $isOn ? '' : 'hidden' }}">✓</span>
                                                <span class="block bg-white px-3 py-2.5 text-[14px] font-semibold">{{ $optLabel($option) }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                    @break

                                {{-- Roomix «Сочетания цветов»: hər kart bəyənilir VƏ YA
                                     bəyənilmir — üçüncü vəziyyət «cavabsız»dır. Kartın özü
                                     rəng zolağıdır (palitra hex dəyərlərindən qurulur), foto
                                     lazım deyil; şəkil yüklənibsə onun yerinə keçir. --}}
                                @case('image_rating')
                                    @php $ratings = is_array($value) ? $value : []; @endphp
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" data-rating>
                                        @foreach ($question->options ?? [] as $option)
                                            @php
                                                $verdict = $ratings[$option['value']] ?? null;
                                                $img = $option['image_url'] ?? null;
                                                $colors = array_values(array_filter((array) ($option['colors'] ?? [])));
                                            @endphp
                                            <div data-rating-row="{{ $option['value'] }}"
                                                class="overflow-hidden rounded-ds-md border-2 transition-colors
                                                       {{ $verdict === 'like' ? 'border-ok' : ($verdict === 'dislike' ? 'border-danger' : 'border-black/10') }}">
                                                <span class="flex aspect-[4/3] w-full overflow-hidden bg-neutral-soft"
                                                    @if ($img) style="background-image:url('{{ storage_url($img) }}');background-size:cover;background-position:center" @endif>
                                                    @unless ($img)
                                                        @foreach ($colors as $hex)
                                                            <span class="h-full flex-1" style="background-color: {{ $hex }}"></span>
                                                        @endforeach
                                                    @endunless
                                                </span>
                                                <span class="flex items-center justify-between gap-1 bg-white px-2.5 py-2">
                                                    <span class="truncate text-[13px] font-semibold">{{ $optLabel($option) }}</span>
                                                    <span class="flex shrink-0 gap-1">
                                                        <button type="button" data-rating-btn value="dislike" {{ $completed ? 'disabled' : '' }}
                                                            aria-label="{{ $optLabel($option) }} — {{ t('portal.brief_dislike') }}"
                                                            class="flex h-7 w-7 items-center justify-center rounded-full border text-[13px] leading-none transition-colors
                                                                   {{ $verdict === 'dislike' ? 'border-danger bg-danger text-white' : 'border-black/20 bg-white hover:border-black/45' }}">✕</button>
                                                        <button type="button" data-rating-btn value="like" {{ $completed ? 'disabled' : '' }}
                                                            aria-label="{{ $optLabel($option) }} — {{ t('portal.brief_like') }}"
                                                            class="flex h-7 w-7 items-center justify-center rounded-full border text-[13px] leading-none transition-colors
                                                                   {{ $verdict === 'like' ? 'border-ok bg-ok text-white' : 'border-black/20 bg-white hover:border-black/45' }}">♥</button>
                                                    </span>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                    @break

                                {{-- Roomix «Мебельные высоты»: erqonomikanın standartı var, amma
                                     rahatlıq fərdidir. Hər sətir «standart üzrə» və «öz ölçüm»
                                     arasında keçir; ikincisi rəqəm sahəsini açır. --}}
                                @case('std_or_custom')
                                    @php
                                        $items = $question->options['items'] ?? [];
                                        $pickedRows = is_array($value) ? $value : [];
                                    @endphp
                                    <div class="space-y-2.5" data-stdcustom>
                                        @foreach ($items as $item)
                                            @php
                                                $row = (array) ($pickedRows[$item['value']] ?? []);
                                                $mode = in_array($row['mode'] ?? null, ['std', 'custom'], true) ? $row['mode'] : null;
                                                $itemImages = collect((array) ($item['images'] ?? []))
                                                    ->filter()->map(fn ($p) => storage_url($p))->values()->all();
                                            @endphp
                                            <div data-stdcustom-row="{{ $item['value'] }}"
                                                 class="flex flex-col gap-2.5 rounded-ds border border-black/15 p-3 sm:flex-row sm:items-center sm:gap-3">
                                                @if ($itemImages !== [])
                                                    <button type="button"
                                                        data-inspire='@json($itemImages)'
                                                        data-inspire-title="{{ $optLabel($item) }}"
                                                        aria-label="{{ $optLabel($item) }} — {{ t('portal.brief_inspiration') }}"
                                                        class="h-12 w-16 shrink-0 overflow-hidden rounded-ds border border-black/15 bg-neutral-soft bg-cover bg-center"
                                                        style="background-image:url('{{ $itemImages[0] }}')"></button>
                                                @endif
                                                <span class="min-w-0 flex-1 text-[14px] font-medium">{{ $optLabel($item) }}</span>
                                                <span class="flex shrink-0 flex-wrap items-center gap-2">
                                                    <button type="button" data-stdcustom-mode value="std" {{ $completed ? 'disabled' : '' }}
                                                        class="rounded-pill border px-3.5 py-1.5 text-[13px] font-semibold transition-colors
                                                               {{ $mode === 'std' ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/45' }}">
                                                        {{ t('portal.brief_std') }} · {{ $item['standard'] }} {{ $item['unit'] ?? 'mm' }}
                                                    </button>
                                                    <button type="button" data-stdcustom-mode value="custom" {{ $completed ? 'disabled' : '' }}
                                                        class="rounded-pill border px-3.5 py-1.5 text-[13px] font-semibold transition-colors
                                                               {{ $mode === 'custom' ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/45' }}">
                                                        {{ t('portal.brief_custom') }}
                                                    </button>
                                                    <input type="number" data-stdcustom-value inputmode="numeric"
                                                        value="{{ $mode === 'custom' ? ($row['value'] ?? '') : '' }}"
                                                        placeholder="{{ $item['standard'] }}" {{ $completed ? 'disabled' : '' }}
                                                        class="h-9 w-24 rounded-ds border border-black/20 px-2.5 text-sm outline-none focus:border-ink {{ $mode === 'custom' ? '' : 'hidden' }}">
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                    @break

                                {{-- Roomix-də ailə tərkibi və hobbi siyahısı sərbəst mətn deyil,
                                     sətir-sətir əlavə olunan cədvəldir. Yeni sətir `<template>`
                                     klonlanaraq yaradılır. --}}
                                @case('repeater')
                                    @php
                                        $fields = $question->options['fields'] ?? [];
                                        $repRows = array_values(array_filter((array) $value, 'is_array'));
                                        $cell = function (array $f, string $val = '') use ($optLabel, $completed) {
                                            $base = 'h-10 w-full rounded-ds border border-black/20 px-3 text-sm outline-none focus:border-ink';
                                            $off = $completed ? ' disabled' : '';
                                            if (($f['type'] ?? 'text') === 'select') {
                                                $html = '<select data-rep-cell="'.e($f['key']).'" class="'.$base.'"'.$off.'><option value="">—</option>';
                                                foreach ((array) ($f['options'] ?? []) as $o) {
                                                    $html .= '<option value="'.e($o['value']).'"'.($val === $o['value'] ? ' selected' : '').'>'.e($optLabel($o)).'</option>';
                                                }

                                                return $html.'</select>';
                                            }

                                            return '<input data-rep-cell="'.e($f['key']).'" type="'.e($f['type'] ?? 'text').'" value="'.e($val).'" '
                                                .'placeholder="'.e($optLabel($f)).'" class="'.$base.'"'.$off.'>';
                                        };
                                    @endphp
                                    <div data-repeater>
                                        <div data-rep-rows class="space-y-2">
                                            @foreach ($repRows as $row)
                                                <div data-rep-row class="flex flex-col gap-2 rounded-ds border border-black/10 bg-neutral-soft/40 p-2.5 sm:flex-row sm:items-center">
                                                    @foreach ($fields as $f)
                                                        <span class="min-w-0 flex-1">{!! $cell($f, (string) ($row[$f['key']] ?? '')) !!}</span>
                                                    @endforeach
                                                    @unless ($completed)
                                                        <button type="button" data-rep-remove aria-label="{{ t('portal.brief_row_remove') }}"
                                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-ds border border-black/15 bg-white text-black/45 transition-colors hover:border-danger hover:text-danger">✕</button>
                                                    @endunless
                                                </div>
                                            @endforeach
                                        </div>

                                        @unless ($completed)
                                            <template data-rep-template>
                                                <div data-rep-row class="flex flex-col gap-2 rounded-ds border border-black/10 bg-neutral-soft/40 p-2.5 sm:flex-row sm:items-center">
                                                    @foreach ($fields as $f)
                                                        <span class="min-w-0 flex-1">{!! $cell($f) !!}</span>
                                                    @endforeach
                                                    <button type="button" data-rep-remove aria-label="{{ t('portal.brief_row_remove') }}"
                                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-ds border border-black/15 bg-white text-black/45 transition-colors hover:border-danger hover:text-danger">✕</button>
                                                </div>
                                            </template>
                                            <button type="button" data-rep-add
                                                class="mt-2.5 inline-flex items-center gap-1.5 rounded-ds border border-dashed border-black/25 px-4 py-2 text-[13px] font-semibold text-black/60 transition-colors hover:border-ink hover:text-ink">
                                                + {{ $question->options['add_label'] ?? t('portal.brief_row_add') }}
                                            </button>
                                        @endunless
                                    </div>
                                    @break

                                @case('boolean')
                                    <div class="flex gap-2" data-single-choice>
                                        <button type="button" data-choice value="1" {{ $completed ? 'disabled' : '' }}
                                            class="rounded-pill border px-5 py-2 text-[14px] font-medium {{ $value === '1' || $value === true ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">{{ t('portal.yes') }}</button>
                                        <button type="button" data-choice value="0" {{ $completed ? 'disabled' : '' }}
                                            class="rounded-pill border px-5 py-2 text-[14px] font-medium {{ $value === '0' || $value === false ? 'border-ink bg-ink text-white' : 'border-black/20 bg-white hover:border-black/40' }}">{{ t('portal.no') }}</button>
                                    </div>
                                    @break
                                @case('number')
                                    <input data-field type="number" value="{{ $value }}" {{ $completed ? 'disabled' : '' }}
                                        class="h-11 w-40 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                    @break
                                @case('date')
                                    <input data-field type="date" value="{{ $value }}" {{ $completed ? 'disabled' : '' }}
                                        class="h-11 w-52 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                    @break

                                {{-- Spec Part 9.3: büdcə diapazonu + valyuta --}}
                                @case('budget_range')
                                    <div class="flex flex-wrap items-end gap-3" data-budget>
                                        <div>
                                            <span class="mb-1 block text-[13px] font-medium text-black/55">{{ t('portal.brief_budget_from') }}</span>
                                            <input data-budget-min type="number" min="0" value="{{ $value['min'] ?? '' }}" {{ $completed ? 'disabled' : '' }}
                                                class="h-11 w-36 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                        </div>
                                        <div>
                                            <span class="mb-1 block text-[13px] font-medium text-black/55">{{ t('portal.brief_budget_to') }}</span>
                                            <input data-budget-max type="number" min="0" value="{{ $value['max'] ?? '' }}" {{ $completed ? 'disabled' : '' }}
                                                class="h-11 w-36 rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                                        </div>
                                        <div>
                                            <span class="mb-1 block text-[13px] font-medium text-black/55">{{ t('portal.brief_currency') }}</span>
                                            <select data-budget-currency {{ $completed ? 'disabled' : '' }}
                                                class="h-11 rounded-ds border border-black/20 px-3 text-sm outline-none focus:border-ink">
                                                @foreach (['AZN', 'USD', 'EUR'] as $cur)
                                                    <option value="{{ $cur }}" @selected(($value['currency'] ?? 'AZN') === $cur)>{{ $cur }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    @break

                                {{-- Spec Part 8.4 / 8.5: 14 təkrarlanan bloku əvəz edən matrislər --}}
                                @case('matrix')
                                    @php
                                        $rows = $question->options['rows'] ?? [];
                                        $columns = $question->options['columns'] ?? [];
                                        $matrix = is_array($value) ? $value : [];
                                    @endphp
                                    {{-- Screen 09: desktop-da cədvəl, mobildə sətir-kart siyahısı.
                                         Tək DOM, `sm:contents` ilə reflow — kliklənən düymələr eyni
                                         qalır, ona görə seçimin oxunması iki görünüşdə də eynidir. --}}
                                    @php $grid = 'grid-template-columns: minmax(0,1.3fr) repeat('.count($columns).', minmax(0,1fr));'; @endphp
                                    <div data-matrix class="space-y-2 sm:space-y-0">
                                        <div class="hidden sm:grid sm:items-end sm:gap-2 sm:border-b sm:border-black/10 sm:pb-2" style="{{ $grid }}">
                                            <span></span>
                                            @foreach ($columns as $col)
                                                <span class="text-center text-[13px] font-medium leading-tight text-black/55">{{ $optLabel($col) }}</span>
                                            @endforeach
                                        </div>

                                        @foreach ($rows as $rowOpt)
                                            <div data-matrix-row="{{ $rowOpt['value'] }}"
                                                class="rounded-ds border border-black/15 p-3 sm:grid sm:items-center sm:gap-2 sm:rounded-none sm:border-0 sm:border-b sm:border-black/5 sm:p-0 sm:py-2.5"
                                                style="{{ $grid }}">
                                                <span class="text-[14px] font-medium">{{ $optLabel($rowOpt) }}</span>
                                                <div class="mt-2.5 flex flex-wrap gap-2 sm:contents">
                                                    @foreach ($columns as $col)
                                                        @php $picked = ($matrix[$rowOpt['value']] ?? null) === $col['value']; @endphp
                                                        <button type="button" data-matrix-cell value="{{ $col['value'] }}" {{ $completed ? 'disabled' : '' }}
                                                            aria-label="{{ $optLabel($rowOpt) }} — {{ $optLabel($col) }}"
                                                            class="rounded-pill border px-3.5 py-1.5 text-[13px] font-semibold transition-colors
                                                                sm:mx-auto sm:h-5 sm:w-5 sm:rounded-full sm:border-2 sm:p-0
                                                                {{ $picked ? 'border-ink bg-ink text-white' : 'border-black/25 bg-white hover:border-black/50' }}">
                                                            <span class="sm:hidden">{{ $optLabel($col) }}</span>
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    @break

                                {{-- Spec Ə6: rəngin mətnlə deyil, vizual seçimi --}}
                                @case('color_swatch')
                                    @php
                                        $swatches = $question->options['swatches'] ?? [];
                                        $baseMax = (int) ($question->options['base_max'] ?? 4);
                                        $accentMax = (int) ($question->options['accent_max'] ?? 2);
                                        $base = (array) ($value['base'] ?? []);
                                        $accent = (array) ($value['accent'] ?? []);
                                    @endphp
                                    <div data-swatch data-base-max="{{ $baseMax }}" data-accent-max="{{ $accentMax }}">
                                        <div class="mb-3 flex gap-2">
                                            <button type="button" data-swatch-mode="base"
                                                class="rounded-pill border border-ink bg-ink px-4 py-1.5 text-[13px] font-semibold text-white">{{ t('portal.brief_swatch_base') }} ({{ $baseMax }})</button>
                                            <button type="button" data-swatch-mode="accent"
                                                class="rounded-pill border border-black/20 bg-white px-4 py-1.5 text-[13px] font-semibold">{{ t('portal.brief_swatch_accent') }} ({{ $accentMax }})</button>
                                        </div>
                                        <div class="grid grid-cols-8 gap-2">
                                            @foreach ($swatches as $hex)
                                                <button type="button" data-swatch-cell value="{{ $hex }}" {{ $completed ? 'disabled' : '' }}
                                                    data-role="{{ in_array($hex, $accent, true) ? 'accent' : (in_array($hex, $base, true) ? 'base' : '') }}"
                                                    title="{{ $hex }}"
                                                    class="relative aspect-square rounded-ds border-2 {{ in_array($hex, $base, true) || in_array($hex, $accent, true) ? 'border-ink' : 'border-black/10' }}"
                                                    style="background-color: {{ $hex }}">
                                                    <span data-swatch-tag class="absolute inset-x-0 bottom-0 bg-ink/80 text-[10px] font-bold text-white">{{ in_array($hex, $accent, true) ? 'A' : (in_array($hex, $base, true) ? 'F' : '') }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    @break

                                {{-- Spec Ə11 / Part 8.3: dinamik otaq tərkibi --}}
                                @case('room_inventory')
                                    @php
                                        $inventory = is_array($value) ? $value : [];
                                        // Part 10 №6: «yalnız ayrı otaqlar» formatında otaq dəsti
                                        // əhatəyə daxil olanlarla məhdudlaşır.
                                        $roomsOnly = ($values['cooperation_scope'] ?? null) === 'rooms_only';
                                    @endphp
                                    @if ($roomsOnly)
                                        <p class="mb-3 rounded-ds border border-yellow-line bg-sel-bg px-3.5 py-2.5 text-[13px] font-semibold">
                                            {{ t('portal.brief_rooms_scope_only') }}
                                        </p>
                                    @endif
                                    <div class="grid gap-2 sm:grid-cols-2" data-inventory>
                                        @foreach ($question->options ?? [] as $option)
                                            @php $count = (int) ($inventory[$option['value']] ?? 0); @endphp
                                            <div data-inventory-row="{{ $option['value'] }}"
                                                class="flex items-center justify-between gap-3 rounded-ds border px-3.5 py-2.5 {{ $count > 0 ? 'border-ink bg-sel-bg' : 'border-black/15 bg-white' }}">
                                                <span class="text-[14px] font-medium">{{ $optLabel($option) }}</span>
                                                <span class="flex shrink-0 items-center gap-2">
                                                    <button type="button" data-inv-step="-1" {{ $completed ? 'disabled' : '' }}
                                                        class="h-7 w-7 rounded-full border border-black/20 text-sm font-bold leading-none hover:border-ink">−</button>
                                                    <span data-inv-count class="w-5 text-center text-[14px] font-semibold">{{ $count }}</span>
                                                    <button type="button" data-inv-step="1" {{ $completed ? 'disabled' : '' }}
                                                        class="h-7 w-7 rounded-full border border-black/20 text-sm font-bold leading-none hover:border-ink">+</button>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                    @break

                                {{-- Spec Ə8 / Part 10 №20: göndərməni bloklayan razılıq --}}
                                @case('consent')
                                    <label class="flex cursor-pointer items-start gap-3 text-[14px] font-medium leading-relaxed">
                                        <input type="checkbox" data-consent {{ $value === '1' ? 'checked' : '' }} {{ $completed ? 'disabled' : '' }} class="mt-0.5 h-4 w-4 accent-ink">
                                        <span>{{ t('portal.brief_consent_label') }}</span>
                                    </label>
                                    @break

                                {{-- Spec Ə2: obmer / BTİ planı --}}
                                @case('file')
                                    <div data-file>
                                        @if (! empty($value['path']))
                                            <p class="mb-2 text-[13px] font-semibold text-ok">✓ {{ $value['name'] ?? $value['path'] }}</p>
                                        @endif
                                        @unless ($completed)
                                            <input type="file" data-file-input accept=".pdf,.jpg,.jpeg,.png"
                                                class="block w-full text-[13px] file:mr-3 file:rounded-ds file:border-0 file:bg-ink file:px-4 file:py-2 file:text-[13px] file:font-semibold file:text-white">
                                        @endunless
                                    </div>
                                    @break

                                @default
                                    <input data-field type="text" value="{{ $value }}" {{ $completed ? 'disabled' : '' }}
                                        class="h-11 w-full rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
                            @endswitch
                        </div>
                    </div>
                @endforeach

                @unless ($completed)
                    <div class="flex items-center justify-between gap-4">
                        <a href="{{ route('portal.brief', $project) }}" class="ui-btn ui-btn-outline h-11 px-5 text-sm font-semibold" data-hover="true">
                            {{ t('portal.brief_save_exit') }}
                        </a>
                        <button type="submit" class="ui-btn ui-btn-primary h-11 px-6 text-sm font-bold" data-hover="true">
                            {{ t('portal.brief_submit_section') }}
                        </button>
                    </div>
                @endunless
            </form>
    </div>

    {{-- B patterni üçün nümunə qalereyası. Bir modal bütün variantlara xidmət
         edir: məzmun klikləndikdə `data-inspire` massivindən qurulur. --}}
    <div id="inspireModal" hidden
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
         role="dialog" aria-modal="true" aria-labelledby="inspireTitle">
        <div class="max-h-[85vh] w-full max-w-3xl overflow-y-auto rounded-[18px] bg-white p-6">
            <div class="mb-4 flex items-start justify-between gap-4">
                <div>
                    <h2 id="inspireTitle" class="text-[17px] font-bold"></h2>
                    <p class="mt-0.5 text-[13px] text-black/50">{{ t('portal.brief_inspiration_hint') }}</p>
                </div>
                <button type="button" data-inspire-close aria-label="{{ t('portal.close') }}"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-ds text-black/45 transition-colors hover:bg-neutral-soft hover:text-ink">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div id="inspireGrid" class="grid grid-cols-2 gap-3 sm:grid-cols-3"></div>
        </div>
    </div>

    <script>
        // Qalereya seçimdən ASILI DEYİL, ona görə brifin kilidli olub-olmamasından
        // asılı olmayaraq həmişə işləyir (baxış rejimində də nümunələr açılmalıdır).
        (() => {
            const modal = document.getElementById('inspireModal');
            if (!modal) return;
            const grid = document.getElementById('inspireGrid');
            const title = document.getElementById('inspireTitle');

            const close = () => { modal.hidden = true; document.body.classList.remove('overflow-hidden'); };

            document.querySelectorAll('[data-inspire]').forEach(btn => {
                btn.addEventListener('click', () => {
                    let images = [];
                    try { images = JSON.parse(btn.getAttribute('data-inspire')) || []; } catch (e) { images = []; }

                    title.textContent = btn.getAttribute('data-inspire-title') || '';
                    grid.replaceChildren(...images.map(src => {
                        const wrap = document.createElement('span');
                        wrap.className = 'block aspect-[4/3] w-full overflow-hidden rounded-ds-md bg-neutral-soft bg-cover bg-center';
                        wrap.style.backgroundImage = `url('${src}')`;
                        return wrap;
                    }));

                    modal.hidden = false;
                    document.body.classList.add('overflow-hidden');
                });
            });

            modal.querySelector('[data-inspire-close]')?.addEventListener('click', close);
            modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal.hidden) close(); });
        })();
    </script>

    @unless (! $anyEditable)
    <script>
        (() => {
            const form = document.getElementById('briefForm');
            const url = form.dataset.autosaveUrl;
            const uploadUrl = form.dataset.uploadUrl;
            const roomId = form.dataset.roomId || null;
            const csrf = form.querySelector('input[name="_token"]').value;
            const saveState = document.getElementById('saveState');
            const timers = {};

            // Answers from the whole brief — conditional logic crosses sections.
            const seededValues = @json($values);

            const send = (questionId, value, delegated) => {
                fetch(url, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ question_id: questionId, value, delegated, room_id: roomId }),
                })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) { saveState.textContent = @json(t('portal.brief_saved')) + ' ' + d.saved_at; saveState.classList.remove('text-danger'); }
                    else { saveState.textContent = d.error || @json(t('portal.brief_save_error')); saveState.classList.add('text-danger'); }
                })
                .catch(() => { saveState.textContent = @json(t('portal.brief_save_error')); saveState.classList.add('text-danger'); });
            };

            // ── Value extraction, one branch per question type ──
            const collect = (block) => {
                switch (block.dataset.type) {
                    case 'matrix': {
                        const out = {};
                        block.querySelectorAll('[data-matrix-row]').forEach(row => {
                            const picked = row.querySelector('[data-matrix-cell].bg-ink');
                            if (picked) out[row.dataset.matrixRow] = picked.getAttribute('value');
                        });
                        return out;
                    }
                    case 'color_swatch': {
                        const pick = role => [...block.querySelectorAll(`[data-swatch-cell][data-role="${role}"]`)].map(b => b.getAttribute('value'));
                        return { base: pick('base'), accent: pick('accent') };
                    }
                    case 'room_inventory': {
                        const out = {};
                        block.querySelectorAll('[data-inventory-row]').forEach(row => {
                            const n = parseInt(row.querySelector('[data-inv-count]').textContent, 10) || 0;
                            if (n > 0) out[row.dataset.inventoryRow] = n;
                        });
                        return out;
                    }
                    case 'budget_range':
                        return {
                            min: block.querySelector('[data-budget-min]').value || null,
                            max: block.querySelector('[data-budget-max]').value || null,
                            currency: block.querySelector('[data-budget-currency]').value,
                        };
                    case 'consent':
                        return block.querySelector('[data-consent]').checked ? '1' : '0';
                    case 'file':
                        return null; // uploaded separately
                    case 'image_rating': {
                        // Rəyi verilməyən kart cavaba ÜMUMİYYƏTLƏ düşmür — «bəyənmədim»
                        // ilə «hələ baxmamışam» fərqli məlumatdır.
                        const out = {};
                        block.querySelectorAll('[data-rating-row]').forEach(row => {
                            const on = row.querySelector('[data-rating-btn].text-white');
                            if (on) out[row.dataset.ratingRow] = on.getAttribute('value');
                        });
                        return out;
                    }
                    case 'std_or_custom': {
                        const out = {};
                        block.querySelectorAll('[data-stdcustom-row]').forEach(row => {
                            const on = row.querySelector('[data-stdcustom-mode].bg-ink');
                            if (!on) return;
                            const mode = on.getAttribute('value');
                            const own = row.querySelector('[data-stdcustom-value]').value.trim();
                            // «Öz ölçüm» seçilib, amma rəqəm hələ yazılmayıbsa sətir
                            // yarımçıqdır — yarımçıq sətri cavab kimi saymırıq.
                            if (mode === 'custom' && own === '') return;
                            out[row.dataset.stdcustomRow] = mode === 'custom' ? { mode, value: own } : { mode };
                        });
                        return out;
                    }
                    case 'repeater': {
                        const rows = [];
                        block.querySelectorAll('[data-rep-row]').forEach(row => {
                            const obj = {};
                            let filled = false;
                            row.querySelectorAll('[data-rep-cell]').forEach(c => {
                                const v = (c.value || '').trim();
                                obj[c.dataset.repCell] = v;
                                if (v !== '') filled = true;
                            });
                            if (filled) rows.push(obj);
                        });
                        return rows;
                    }
                }

                // Seçim nişanı iki cürdür: adi variantlarda `bg-ink` klassı,
                // şəkil kartlarında isə `data-selected` atributu (kartın fonu
                // şəkildir, ona görə onu `bg-ink` ilə işarələmək olmur).
                const PICKED = '[data-choice].bg-ink, [data-choice][data-selected]';

                const multi = block.querySelector('[data-multi-choice]');
                if (multi) {
                    return [...multi.querySelectorAll(PICKED)].map(b => b.getAttribute('value'));
                }
                const single = block.querySelector('[data-single-choice]');
                if (single) {
                    return single.querySelector(PICKED)?.getAttribute('value') ?? null;
                }
                return block.querySelector('[data-field]')?.value ?? null;
            };

            // ── Skip logic — mirrors BriefQuestion::shouldShow() ──
            const currentValueByKey = () => {
                const map = Object.assign({}, seededValues);
                document.querySelectorAll('[data-question]').forEach(b => { map[b.dataset.key] = collect(b); });
                return map;
            };
            const matches = (rule, values) => {
                const actual = values[rule.question];
                const expected = rule.value;
                switch (rule.operator) {
                    case 'not_equals': return Array.isArray(actual) ? !actual.includes(expected) : actual !== expected;
                    case 'in': return [].concat(expected).includes(actual);
                    case 'gte': return actual !== null && actual !== '' && parseFloat(actual) >= parseFloat(expected);
                    case 'lte': return actual !== null && actual !== '' && parseFloat(actual) <= parseFloat(expected);
                    case 'filled': return Array.isArray(actual) ? actual.length > 0 : (actual !== null && actual !== undefined && actual !== '');
                    case 'has_room': return actual && typeof actual === 'object' && (parseInt(actual[expected], 10) || 0) > 0;
                    case 'matrix_row_filled': return !!(actual && typeof actual === 'object' && actual[expected]);
                }
                return Array.isArray(actual) ? actual.includes(expected) : actual === expected;
            };
            const applySkip = () => {
                const values = currentValueByKey();
                document.querySelectorAll('[data-skip]').forEach(b => {
                    let rule; try { rule = JSON.parse(b.dataset.skip); } catch (e) { return; }
                    b.hidden = !matches(rule, values);
                });
            };

            // Every write is debounced per question: composite widgets (matrix,
            // swatches, room counters) fire several clicks in a row and parallel
            // PATCHes for one answer would land out of order.
            const save = (block, delegate, delay = 300) => {
                applySkip();
                const id = block.dataset.question;
                clearTimeout(timers[id]);
                timers[id] = setTimeout(() => send(id, collect(block), delegate?.checked ?? false), delay);
            };

            document.querySelectorAll('[data-question]').forEach(block => {
                const id = block.dataset.question;
                const delegate = block.querySelector('[data-delegate]');
                const zone = block.querySelector('[data-input-zone]');

                // Debounced free-text / numeric / date fields.
                block.querySelectorAll('[data-field], [data-budget-min], [data-budget-max]').forEach(field => {
                    field.addEventListener('input', () => save(block, delegate, 800));
                });
                block.querySelector('[data-budget-currency]')?.addEventListener('change', () => save(block, delegate));
                block.querySelector('[data-consent]')?.addEventListener('change', () => save(block, delegate));

                // Şəkil kartı ilə adi variantın «seçilmiş» görünüşü fərqlidir:
                // kartın fonu şəkildir, ona görə tünd fon yerinə sarı çərçivə +
                // künc nişanı işlədilir. Nişanın özü `data-selected` atributudur
                // (collect() də onu oxuyur), klasslar sadəcə görüntüdür.
                const isCard = (b) => b.hasAttribute('data-image-card');
                const cardOn = (b, on) => {
                    b.toggleAttribute('data-selected', on);
                    b.classList.toggle('border-yellow', on);
                    b.classList.toggle('shadow-[0_0_0_3px_rgba(253,254,0,.35)]', on);
                    b.classList.toggle('border-black/10', !on);
                    b.querySelector('[data-card-check]')?.classList.toggle('hidden', !on);
                };
                const rowOn = (b, on) => {
                    b.classList.toggle('border-ink', on); b.classList.toggle('bg-ink', on); b.classList.toggle('text-white', on);
                    b.classList.toggle('border-black/20', !on); b.classList.toggle('bg-white', !on);
                    const box = b.querySelector('span:first-child');
                    if (box && box.classList.contains('rounded-[4px]')) {
                        box.classList.toggle('border-yellow', on);
                        box.classList.toggle('bg-yellow', on);
                        box.classList.toggle('text-ink', on);
                        box.classList.toggle('border-black/25', !on);
                        box.textContent = on ? '✓' : '';
                    }
                };
                const setOn = (b, on) => isCard(b) ? cardOn(b, on) : rowOn(b, on);
                const isOn = (b) => isCard(b) ? b.hasAttribute('data-selected') : b.classList.contains('bg-ink');

                block.querySelectorAll('[data-choice]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const group = btn.closest('[data-multi-choice], [data-single-choice]');

                        if (group.hasAttribute('data-single-choice')) {
                            group.querySelectorAll('[data-choice]').forEach(b => setOn(b, false));
                            setOn(btn, true);
                        } else {
                            const wasOn = isOn(btn);
                            setOn(btn, !wasOn);

                            // Part 10 №16 exclusive_override: «Dizaynerin ixtiyarına» is exclusive
                            // with every concrete pick in the same block, in both directions.
                            if (!wasOn) {
                                if (btn.value === 'designer') {
                                    group.querySelectorAll('[data-choice]').forEach(b => { if (b !== btn) setOn(b, false); });
                                } else {
                                    group.querySelectorAll('[data-choice][value="designer"]').forEach(b => setOn(b, false));
                                }
                            }
                        }

                        save(block, delegate);
                    });
                });

                // Rəng kombinasiyaları: eyni düyməyə təkrar basmaq rəyi geri alır.
                block.querySelectorAll('[data-rating-btn]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const row = btn.closest('[data-rating-row]');
                        const was = btn.classList.contains('text-white');
                        row.querySelectorAll('[data-rating-btn]').forEach(b => {
                            b.classList.remove('border-ok', 'bg-ok', 'border-danger', 'bg-danger', 'text-white');
                            b.classList.add('border-black/20', 'bg-white');
                        });
                        if (!was) {
                            const like = btn.getAttribute('value') === 'like';
                            btn.classList.remove('border-black/20', 'bg-white');
                            btn.classList.add(like ? 'border-ok' : 'border-danger', like ? 'bg-ok' : 'bg-danger', 'text-white');
                        }
                        const verdict = was ? null : btn.getAttribute('value');
                        row.classList.remove('border-ok', 'border-danger', 'border-black/10');
                        row.classList.add(verdict === 'like' ? 'border-ok' : (verdict === 'dislike' ? 'border-danger' : 'border-black/10'));
                        save(block, delegate);
                    });
                });

                // Standart / öz ölçüm: «öz ölçüm» rəqəm sahəsini açır və fokuslayır.
                block.querySelectorAll('[data-stdcustom-mode]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const row = btn.closest('[data-stdcustom-row]');
                        const input = row.querySelector('[data-stdcustom-value]');
                        const was = btn.classList.contains('bg-ink');
                        row.querySelectorAll('[data-stdcustom-mode]').forEach(b => {
                            b.classList.remove('border-ink', 'bg-ink', 'text-white');
                            b.classList.add('border-black/20', 'bg-white');
                        });
                        if (!was) {
                            btn.classList.remove('border-black/20', 'bg-white');
                            btn.classList.add('border-ink', 'bg-ink', 'text-white');
                        }
                        const custom = !was && btn.getAttribute('value') === 'custom';
                        input.classList.toggle('hidden', !custom);
                        if (!custom) input.value = '';
                        if (custom) input.focus();
                        save(block, delegate);
                    });
                });
                block.querySelectorAll('[data-stdcustom-value]').forEach(i => {
                    i.addEventListener('input', () => save(block, delegate, 800));
                });

                // Təkrarlanan sətirlər: şablon klonlanır, silinən sətir dərhal yazılır.
                const repeater = block.querySelector('[data-repeater]');
                if (repeater) {
                    const rows = repeater.querySelector('[data-rep-rows]');
                    const tpl = repeater.querySelector('[data-rep-template]');
                    const bindRow = (row) => {
                        row.querySelectorAll('[data-rep-cell]').forEach(c => {
                            c.addEventListener('input', () => save(block, delegate, 800));
                            c.addEventListener('change', () => save(block, delegate));
                        });
                        row.querySelector('[data-rep-remove]')?.addEventListener('click', () => {
                            row.remove();
                            save(block, delegate, 0);
                        });
                    };
                    rows.querySelectorAll('[data-rep-row]').forEach(bindRow);
                    repeater.querySelector('[data-rep-add]')?.addEventListener('click', () => {
                        const row = tpl.content.firstElementChild.cloneNode(true);
                        rows.appendChild(row);
                        bindRow(row);
                        row.querySelector('[data-rep-cell]')?.focus();
                    });
                }

                // Matrix: one exclusive pick per row.
                block.querySelectorAll('[data-matrix-cell]').forEach(cell => {
                    cell.addEventListener('click', () => {
                        const row = cell.closest('[data-matrix-row]');
                        row.querySelectorAll('[data-matrix-cell]').forEach(c => {
                            c.classList.remove('border-ink', 'bg-ink', 'text-white');
                            c.classList.add('border-black/25', 'bg-white');
                        });
                        cell.classList.remove('border-black/25', 'bg-white');
                        cell.classList.add('border-ink', 'bg-ink', 'text-white');
                        save(block, delegate);
                    });
                });

                // Swatch picker: current mode decides whether a tap sets base or accent.
                const swatch = block.querySelector('[data-swatch]');
                if (swatch) {
                    let mode = 'base';
                    swatch.querySelectorAll('[data-swatch-mode]').forEach(btn => {
                        btn.addEventListener('click', () => {
                            mode = btn.dataset.swatchMode;
                            swatch.querySelectorAll('[data-swatch-mode]').forEach(b => {
                                const on = b === btn;
                                b.classList.toggle('border-ink', on);
                                b.classList.toggle('bg-ink', on);
                                b.classList.toggle('text-white', on);
                                b.classList.toggle('border-black/20', !on);
                                b.classList.toggle('bg-white', !on);
                            });
                        });
                    });
                    const limit = r => parseInt(r === 'base' ? swatch.dataset.baseMax : swatch.dataset.accentMax, 10);
                    swatch.querySelectorAll('[data-swatch-cell]').forEach(cell => {
                        cell.addEventListener('click', () => {
                            const tag = cell.querySelector('[data-swatch-tag]');
                            if (cell.dataset.role === mode) {
                                cell.dataset.role = '';
                            } else {
                                const used = swatch.querySelectorAll(`[data-swatch-cell][data-role="${mode}"]`).length;
                                if (used >= limit(mode)) return;
                                cell.dataset.role = mode;
                            }
                            tag.textContent = cell.dataset.role === 'accent' ? 'A' : (cell.dataset.role === 'base' ? 'F' : '');
                            cell.classList.toggle('border-ink', !!cell.dataset.role);
                            cell.classList.toggle('border-black/10', !cell.dataset.role);
                            save(block, delegate);
                        });
                    });
                }

                // Room inventory counters — drive which room accordions exist.
                block.querySelectorAll('[data-inv-step]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const row = btn.closest('[data-inventory-row]');
                        const el = row.querySelector('[data-inv-count]');
                        const next = Math.max(0, Math.min(9, (parseInt(el.textContent, 10) || 0) + parseInt(btn.dataset.invStep, 10)));
                        el.textContent = next;
                        row.classList.toggle('border-ink', next > 0);
                        row.classList.toggle('bg-sel-bg', next > 0);
                        row.classList.toggle('border-black/15', next === 0);
                        row.classList.toggle('bg-white', next === 0);
                        save(block, delegate);
                    });
                });

                // Files bypass autosave: multipart POST, then reload to show the link.
                block.querySelector('[data-file-input]')?.addEventListener('change', (e) => {
                    const file = e.target.files[0];
                    if (!file) return;
                    const data = new FormData();
                    data.append('_token', csrf);
                    data.append('question_id', id);
                    data.append('file', file);
                    if (roomId) data.append('room_id', roomId);
                    saveState.textContent = '…';
                    fetch(uploadUrl, { method: 'POST', body: data, headers: { 'X-CSRF-TOKEN': csrf } })
                        .then(r => r.ok ? window.location.reload() : Promise.reject())
                        .catch(() => { saveState.textContent = @json(t('portal.brief_save_error')); });
                });

                delegate?.addEventListener('change', () => {
                    zone.classList.toggle('pointer-events-none', delegate.checked);
                    zone.classList.toggle('opacity-40', delegate.checked);
                    send(id, delegate.checked ? null : collect(block), delegate.checked);
                });
            });

            applySkip(); // initial evaluation on load
        })();
    </script>
    @endunless
</x-portal.shell>
