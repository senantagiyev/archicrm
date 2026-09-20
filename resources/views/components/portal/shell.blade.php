@props(['title' => null, 'project' => null, 'active' => null])

@php
    // Roomix modeli: SOL panel QLOBAL naviqasiyadır (layihədən asılı deyil),
    // layihə bölmələri isə layihə başlığının altındakı YUXARI TAB-lara düşür.
    // Əvvəl hər şey sol paneldə idi — o zaman istifadəçi hansı layihənin
    // içində olduğunu yalnız kiçik mətn etiketindən bilirdi.
    $nav = [
        'projects'  => [route('portal.home'),           t('portal.my_projects'),   'M4 20V8l8-5 8 5v12M9 20v-6h6v6'],
        'approvals' => [route('portal.approvals.all'),  t('portal.nav_approvals'), 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3'],
        'documents' => [route('portal.documents.all'),  t('portal.nav_documents'), 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z'],
        'profile'   => [route('portal.profile'),        t('portal.nav_profile'),   'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 21a7.5 7.5 0 0 1 15 0'],
    ];

    $tabs = $project ? [
        'overview'  => [route('portal.projects.show', $project), t('portal.nav_overview'),  'M3 11l9-8 9 8M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5'],
        'brief'     => [route('portal.brief', $project),         t('portal.nav_brief'),     'M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3'],
        'chat'      => [route('portal.chat', $project),          t('portal.nav_chat'),      'M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5'],
        'approvals' => [route('portal.approvals', $project),     t('portal.nav_approvals'), 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3'],
        'documents' => [route('portal.documents', $project),     t('portal.nav_documents'), 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z'],
        'payments'  => [route('portal.payments', $project),      t('portal.nav_payments'),  'M3 10h18M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1ZM7 15h2'],
    ] : [];

    // Sol panel qlobal naviqasiyadır: layihənin İÇİNDƏ olanda orada «Layihələrim»
    // işıqlanır (Roomix-də də belədir), konkret bölmə isə yuxarı tabda vurğulanır.
    $navActive = $project ? 'projects' : ($active ?? 'projects');
    $tabActive = $active;

    // Tab bar, səhifə başlığı və <main> EYNİ konteynerdən keçir — əks halda geniş
    // ekranda tablar sol kənardan, məzmun isə mərkəzdən başlayırdı.
    $container = 'mx-auto w-full max-w-[1180px] px-5 lg:px-8';
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' — ' : '' }}ARCHI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-gray-soft2 text-ink">

    {{-- ── Desktop: dark left sidebar (mockup) ─────────────────────────── --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-[240px] flex-col bg-ink text-white lg:flex">
        <div class="px-6 pb-5 pt-6">
            <a href="{{ route('portal.home') }}"><x-archi-logo variant="light" /></a>
        </div>

        {{-- Roomix ölçüləri: element 10px/16px padding, radius 12px, ikon-mətn 12px, mətn 16px. --}}
        <nav class="flex-1 space-y-1 px-3">
            @foreach ($nav as $key => [$url, $label, $icon])
                <a href="{{ $url }}"
                   @if ($navActive === $key) aria-current="page" @endif
                   class="relative flex items-center gap-3 rounded-[12px] px-4 py-2.5 text-[16px] transition-colors
                          {{ $navActive === $key ? 'bg-yellow font-semibold text-ink' : 'font-medium text-white/70 hover:bg-white/8 hover:text-white' }}">
                    <svg width="20" height="20" class="shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    <span class="truncate">{{ $label }}</span>
                </a>
            @endforeach
        </nav>

        <div class="border-t border-white/10 px-3 py-4">
            <form method="post" action="{{ route('locale.switch') }}" class="mb-3 flex items-center gap-1 px-4 text-[13px] font-semibold uppercase">
                @csrf
                @foreach (['az', 'ru', 'en'] as $loc)
                    <button name="locale" value="{{ $loc }}"
                        class="rounded-ds px-2 py-1 {{ app()->getLocale() === $loc ? 'bg-white/15 text-white' : 'text-white/45 hover:text-white' }}">
                        {{ strtoupper($loc) }}
                    </button>
                @endforeach
            </form>
            @auth('customer')
                <form method="post" action="{{ route('portal.logout') }}">
                    @csrf
                    <button class="flex w-full items-center gap-3 rounded-[12px] px-4 py-2.5 text-[16px] font-medium text-white/70 transition-colors hover:bg-white/8 hover:text-white">
                        <svg width="20" height="20" class="shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        {{ t('portal.logout') }}
                    </button>
                </form>
            @endauth
        </div>
    </aside>

    {{-- ── Mobile: dark top bar + scrollable nav ───────────────────────── --}}
    <header class="bg-ink text-white lg:hidden">
        <div class="flex h-[60px] items-center justify-between px-4">
            <a href="{{ route('portal.home') }}"><x-archi-logo variant="light" :sub="false" /></a>
            <div class="flex items-center gap-2">
                <form method="post" action="{{ route('locale.switch') }}" class="flex items-center gap-1 text-[11px] font-bold uppercase">
                    @csrf
                    @foreach (['az', 'ru', 'en'] as $loc)
                        <button name="locale" value="{{ $loc }}"
                            class="rounded-ds px-1.5 py-1 {{ app()->getLocale() === $loc ? 'bg-yellow text-ink' : 'text-white/50' }}">{{ strtoupper($loc) }}</button>
                    @endforeach
                </form>
                @auth('customer')
                    <form method="post" action="{{ route('portal.logout') }}">
                        @csrf
                        <button class="ui-btn ui-btn-on-ink h-8 px-3 text-[12px] font-semibold" data-hover="true">{{ t('portal.logout') }}</button>
                    </form>
                @endauth
            </div>
        </div>
        {{-- Mobil: layihə daxilindəykən tablar, əks halda qlobal naviqasiya. --}}
        <nav class="flex items-center gap-1 overflow-x-auto border-t border-white/10 px-2 py-2">
            @foreach (($project ? $tabs : $nav) as $key => [$url, $label, $icon])
                <a href="{{ $url }}" @if ($key === 'chat') data-nav-chat @endif
                   class="relative whitespace-nowrap rounded-[10px] px-3 py-1.5 text-[14px] font-semibold
                          {{ ($project ? $tabActive : $navActive) === $key ? 'bg-yellow text-ink' : 'text-white/65' }}">
                    {{ $label }}
                    @if ($key === 'chat')
                        <span data-chat-dot hidden class="absolute -right-0.5 -top-0.5 inline-block h-2 w-2 rounded-pill bg-danger"></span>
                    @endif
                </a>
            @endforeach
        </nav>
    </header>

    {{-- ── Content ─────────────────────────────────────────────────────── --}}
    <div class="lg:pl-[240px]">
        {{-- Layihə başlığı: geri oxu · ad · istifadəçi. Ad mərkəzdən sola keçdi —
             tab, başlıq və kartlar eyni sol oxdan başlamalıdır. --}}
        <div class="hidden border-b border-black/8 bg-white lg:block">
            <div class="{{ $container }} flex h-[64px] items-center gap-4">
                @if ($project)
                    <a href="{{ route('portal.home') }}" aria-label="{{ t('portal.my_projects') }}"
                       class="-ml-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] text-ink/60 transition-colors hover:bg-neutral-soft hover:text-ink">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    </a>
                @endif

                <p class="min-w-0 truncate text-[15px] font-bold">{{ $project?->name ?? ($title ?? t('portal.my_projects')) }}</p>

                @if ($project)
                    <span class="inline-flex shrink-0 items-center gap-2 text-[13px] font-semibold text-black/45">
                        <span class="h-2 w-2 rounded-full bg-yellow"></span>{{ $project->status->label() }}
                    </span>
                @endif

                <span class="flex-1"></span>

                @auth('customer')
                    <a href="{{ route('portal.profile') }}" class="flex shrink-0 items-center gap-3 rounded-[10px] px-2 py-1 transition-colors hover:bg-neutral-soft">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-ink text-[12px] font-bold text-white">
                            {{ mb_strtoupper(mb_substr(auth('customer')->user()->name, 0, 1)) }}
                        </span>
                        <span class="text-[13px] font-semibold">{{ auth('customer')->user()->name }}</span>
                    </a>
                @endauth
            </div>
        </div>

        {{-- Layihə tabları (yalnız desktop — mobil variant yuxarıdakı header-dədir). --}}
        @if ($project)
            <div class="hidden border-b border-black/8 bg-white lg:block">
                {{-- Roomix-də tablar zolağın BÜTÜN ENİNƏ bərabər paylanır (ölçüldü:
                     aralıqlar ~98px, kənarlarda ~57px), sola yığılmır. Ona görə hər
                     tab `flex-1 basis-0` ilə boşluğu bölüşür və məzmunu mərkəzdədir.
                     `min-w-fit` uzun etiketin («Razılaşdırmalar») sıxılıb kəsilməsinin
                     qarşısını alır; yer çatmasa zolaq sürüşür. --}}
                <nav class="{{ $container }} flex items-stretch overflow-x-auto" aria-label="{{ $project->name }}">
                    @foreach ($tabs as $key => [$url, $label, $icon])
                        <a href="{{ $url }}" @if ($key === 'chat') data-nav-chat @endif
                           @if ($tabActive === $key) aria-current="page" @endif
                           class="flex min-w-fit flex-1 basis-0 flex-col items-center gap-1 border-b-2 px-3 py-2.5 text-[13px] font-semibold transition-colors
                                  {{ $tabActive === $key
                                        ? 'border-yellow text-ink'
                                        : 'border-transparent text-black/55 hover:text-ink' }}">
                            {{-- Oxunmamış nöqtə ikona bağlıdır: tab enləri bərabər
                                 olmadığı üçün onu tabın sağ kənarına bağlasaq,
                                 etiketdən qopub havada qalırdı. --}}
                            <span class="relative">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                                @if ($key === 'chat')
                                    <span data-chat-dot hidden class="absolute -right-1 -top-0.5 inline-block h-2 w-2 rounded-pill bg-danger"></span>
                                @endif
                            </span>
                            <span class="whitespace-nowrap">{{ $label }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
        @endif

        <main class="{{ $container }} py-7">
            @if (session('status'))
                <div class="mb-5 rounded-[12px] border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-[12px] border border-error/30 bg-error-soft px-4 py-3 text-sm font-medium text-error">
                    {{ $errors->first() }}
                </div>
            @endif

            {{ $slot }}
        </main>

        <footer class="{{ $container }} pb-8 pt-2 text-[13px] text-black/40">
            © {{ date('Y') }} ARCHI
        </footer>
    </div>

    @auth('customer')
    <script>
        // Global chat sound + unread dot for the customer, on every portal page.
        (() => {
            const url = @json(route('portal.chat.unread'));
            let last = null;

            const beep = () => {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const play = (freq, start) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.connect(gain); gain.connect(ctx.destination);
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.06, ctx.currentTime + start);
                        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + 0.3);
                        osc.start(ctx.currentTime + start); osc.stop(ctx.currentTime + start + 0.35);
                    };
                    play(880, 0); play(660, 0.18);
                } catch (e) {}
            };

            const check = () => {
                fetch(url, { headers: { Accept: 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        document.querySelectorAll('[data-chat-dot]').forEach(el => el.hidden = !(d.count > 0));
                        if (last !== null && d.count > last) beep();
                        last = d.count;
                    })
                    .catch(() => {});
            };

            check();
            setInterval(check, 15000);
        })();
    </script>
    @endauth
</body>
</html>
