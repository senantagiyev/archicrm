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
        'notifications' => [route('portal.notifications'), t('portal.nav_notifications'), 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0'],
        'profile'   => [route('portal.profile'),        t('portal.nav_profile'),   'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 21a7.5 7.5 0 0 1 15 0'],
    ];

    // Layihə bölmələri — heyət paneli ilə EYNİ altı bölmə, eyni sıra ilə
    // (TZ «Layihə naviqasiyası» §1): İcmal · Brif · Mərhələlər · Fayllar ·
    // Komplektasiya · Müəllif nəzarəti. Brif həmişə görünür — studiya brifi
    // hələ göndərməyibsə səhifə izahlı boş vəziyyət göstərir. Çat bölmə deyil,
    // ayrıca keçiddir; ödənişlər, sənədlər, smeta və razılaşdırmalar siyahısı
    // İcmaldan açılır (həmin səhifələrdə «İcmal» vurğulanır).
    $sectionNav = app(\App\Services\Portal\ProjectSections::class);
    $tabs = $project ? $sectionNav->sections($project) : [];
    $chatLink = $project ? $sectionNav->chat($project) : null;

    // Sol panel qlobal naviqasiyadır: layihənin İÇİNDƏ olanda orada «Layihələrim»
    // işıqlanır (Roomix-də də belədir), konkret bölmə isə yuxarı tabda vurğulanır.
    $navActive = $project ? 'projects' : ($active ?? 'projects');
    $tabActive = $project ? $sectionNav->activeSection($active) : null;

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
    {{-- Tema CSS-dən ƏVVƏL təyin olunur: sonra qoysaq səhifə bir an açıq
         rənglə görünüb tündə keçərdi («flash of wrong theme»). Seçim yoxdursa
         sistem ayarına uyulur. --}}
    <script>
        (() => {
            try {
                const saved = localStorage.getItem('archi-theme');
                const dark = saved ? saved === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (dark) document.documentElement.dataset.theme = 'dark';
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-gray-soft2 text-ink">

    {{-- ── Desktop: dark left sidebar (mockup) ─────────────────────────── --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-[240px] flex-col border-r border-black/8 bg-sidebar text-sidebar-ink lg:flex">
        <div class="px-6 pb-5 pt-6">
            <a href="{{ route('portal.home') }}"><x-archi-logo variant="sidebar" /></a>
        </div>

        {{-- Roomix ölçüləri: element 10px/16px padding, radius 12px, ikon-mətn 12px, mətn 16px. --}}
        <nav class="flex-1 space-y-1 px-3">
            @foreach ($nav as $key => [$url, $label, $icon])
                <a href="{{ $url }}"
                   @if ($navActive === $key) aria-current="page" @endif
                   class="relative flex items-center gap-3 rounded-[12px] px-4 py-2.5 text-[16px] transition-colors
                          {{ $navActive === $key ? 'bg-yellow font-semibold text-on-yellow' : 'font-medium text-sidebar-ink/70 hover:bg-sidebar-ink/8 hover:text-sidebar-ink' }}">
                    <svg width="20" height="20" class="shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    <span class="truncate">{{ $label }}</span>
                </a>
            @endforeach
        </nav>

        <div class="border-t border-sidebar-ink/10 px-3 py-4">
            <form method="post" action="{{ route('locale.switch') }}" class="mb-3 flex items-center gap-1 px-4 text-[13px] font-semibold uppercase">
                @csrf
                @foreach (['az', 'ru', 'en'] as $loc)
                    <button name="locale" value="{{ $loc }}"
                        class="rounded-ds px-2 py-1 {{ app()->getLocale() === $loc ? 'bg-sidebar-ink/15 text-sidebar-ink' : 'text-sidebar-ink/45 hover:text-sidebar-ink' }}">
                        {{ strtoupper($loc) }}
                    </button>
                @endforeach
            </form>
            @auth('customer')
                <form method="post" action="{{ route('portal.logout') }}">
                    @csrf
                    <button class="flex w-full items-center gap-3 rounded-[12px] px-4 py-2.5 text-[16px] font-medium text-sidebar-ink/70 transition-colors hover:bg-sidebar-ink/8 hover:text-sidebar-ink">
                        <svg width="20" height="20" class="shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        {{ t('portal.logout') }}
                    </button>
                </form>
            @endauth
        </div>
    </aside>

    {{-- ── Mobile: dark top bar + scrollable nav ───────────────────────── --}}
    <header class="border-b border-black/8 bg-sidebar text-sidebar-ink lg:hidden">
        <div class="flex h-[60px] items-center justify-between px-4">
            <a href="{{ route('portal.home') }}"><x-archi-logo variant="sidebar" :sub="false" /></a>
            <div class="flex items-center gap-2">
                <form method="post" action="{{ route('locale.switch') }}" class="flex items-center gap-1 text-[11px] font-bold uppercase">
                    @csrf
                    @foreach (['az', 'ru', 'en'] as $loc)
                        <button name="locale" value="{{ $loc }}"
                            class="rounded-ds px-1.5 py-1 {{ app()->getLocale() === $loc ? 'bg-yellow text-on-yellow' : 'text-sidebar-ink/50' }}">{{ strtoupper($loc) }}</button>
                    @endforeach
                </form>
                @auth('customer')
                    <form method="post" action="{{ route('portal.logout') }}">
                        @csrf
                        <button class="ui-btn ui-btn-on-sidebar h-8 px-3 text-[12px] font-semibold" data-hover="true">{{ t('portal.logout') }}</button>
                    </form>
                @endauth
            </div>
        </div>
        @if ($project)
            {{-- Mobil: altı bölmə uzun üfüqi sətir əvəzinə YIĞCAM menyudadır
                 (TZ qəbul meyarı 13). Seçim dəyişəndə həmin bölmə açılır. Çat
                 yanında ayrıca düymədir — bölmə deyil, amma bir toxunuşdadır. --}}
            <div class="flex items-center gap-2 border-t border-sidebar-ink/10 px-3 py-2" data-section-nav="mobile">
                <label for="portal-section-select" class="sr-only">{{ t('portal.sections_menu') }}</label>
                <select id="portal-section-select" onchange="if (this.value) location.href = this.value"
                        class="h-9 min-w-0 flex-1 rounded-[10px] border border-sidebar-ink/20 bg-sidebar px-3 text-[14px] font-semibold text-sidebar-ink">
                    @if ($tabActive === null || $tabActive === 'chat')
                        <option value="" selected disabled>{{ $tabActive === 'chat' ? $chatLink['label'] : t('portal.sections_menu') }}</option>
                    @endif
                    @foreach ($tabs as $key => $tab)
                        <option value="{{ $tab['available'] ? $tab['url'] : '' }}" @selected($tabActive === $key) @disabled(! $tab['available'])>{{ $loop->iteration }}. {{ $tab['label'] }}</option>
                    @endforeach
                </select>
                <a href="{{ $chatLink['url'] }}" data-nav-chat
                   @if ($tabActive === 'chat') aria-current="page" @endif
                   class="relative flex h-9 shrink-0 items-center gap-1.5 rounded-[10px] px-3 text-[14px] font-semibold
                          {{ $tabActive === 'chat' ? 'bg-yellow text-on-yellow' : 'text-sidebar-ink/75' }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chatLink['icon'] }}"/></svg>
                    {{ $chatLink['label'] }}
                    <span data-chat-dot hidden class="absolute -right-0.5 -top-0.5 inline-block h-2 w-2 rounded-pill bg-danger"></span>
                </a>
            </div>
        @else
            {{-- Mobil qlobal naviqasiya (layihədən kənar səhifələr). --}}
            <nav class="flex items-center gap-1 overflow-x-auto border-t border-sidebar-ink/10 px-2 py-2">
                @foreach ($nav as $key => [$url, $label, $icon])
                    <a href="{{ $url }}"
                       class="relative whitespace-nowrap rounded-[10px] px-3 py-1.5 text-[14px] font-semibold
                              {{ $navActive === $key ? 'bg-yellow text-on-yellow' : 'text-sidebar-ink/65' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
        @endif
    </header>

    {{-- ── Content ─────────────────────────────────────────────────────── --}}
    <div class="lg:pl-[240px]">
        {{-- Layihə başlığı: geri oxu · ad · istifadəçi. Ad mərkəzdən sola keçdi —
             tab, başlıq və kartlar eyni sol oxdan başlamalıdır. --}}
        <div class="hidden border-b border-black/8 bg-card lg:block">
            <div class="{{ $container }} flex h-[64px] items-center gap-4">
                @if ($project)
                    <a href="{{ route('portal.home') }}" aria-label="{{ t('portal.my_projects') }}"
                       class="-ml-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] text-ink/60 transition-colors hover:bg-neutral-soft hover:text-ink">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    </a>
                @endif

                <p class="min-w-0 truncate text-[15px] font-bold">{{ $project?->name ?? ($title ?? t('portal.my_projects')) }}</p>

                @if ($project)
                    {{-- Roomix layihə başlığının yanında «Your turn» yazır: müştəri
                         siyahıya baxan kimi topun kimdə olduğunu bilir. Şərt sadədir —
                         onun qərarını gözləyən açıq razılaşdırma varmı. --}}
                    @php $waiting = $project->pendingClientApprovalsCount(); @endphp
                    @if ($waiting > 0)
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-yellow px-3 py-1 text-[13px] font-bold text-on-yellow">
                            {{ t('portal.project_your_turn') }}
                            <span class="text-ink/60">{{ $waiting }}</span>
                        </span>
                    @else
                        <span class="inline-flex shrink-0 items-center gap-2 text-[13px] font-semibold text-black/45">
                            <span class="h-2 w-2 rounded-full bg-yellow"></span>{{ $project->status->label() }}
                        </span>
                    @endif
                @endif

                <span class="flex-1"></span>

                {{-- Tema keçidi. Sol paneldə deyil, başlıqdadır: sol panel hər iki
                     temada tünddür, ona görə düymənin vəziyyəti orada oxunmazdı. --}}
                <button type="button" data-theme-toggle
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] text-ink/60 transition-colors hover:bg-neutral-soft hover:text-ink"
                        aria-label="{{ t('portal.theme_toggle') }}" title="{{ t('portal.theme_toggle') }}">
                    <svg data-theme-icon="light" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
                    </svg>
                    <svg data-theme-icon="dark" hidden width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>
                    </svg>
                </button>

                @auth('customer')
                    <a href="{{ route('portal.profile') }}" class="flex shrink-0 items-center gap-3 rounded-[10px] px-2 py-1 transition-colors hover:bg-neutral-soft">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-accent-dark text-[12px] font-bold text-white">
                            {{ mb_strtoupper(mb_substr(auth('customer')->user()->name, 0, 1)) }}
                        </span>
                        <span class="text-[13px] font-semibold">{{ auth('customer')->user()->name }}</span>
                    </a>
                @endauth
            </div>
        </div>

        {{-- Layihə tabları (yalnız desktop — mobil variant yuxarıdakı header-dədir). --}}
        @if ($project)
            <div class="hidden border-b border-black/8 bg-card lg:block">
                {{-- Roomix-də tablar zolağın BÜTÜN ENİNƏ bərabər paylanır (ölçüldü:
                     aralıqlar ~98px, kənarlarda ~57px), sola yığılmır. Ona görə hər
                     tab `flex-1 basis-0` ilə boşluğu bölüşür və məzmunu mərkəzdədir.
                     `min-w-fit` uzun etiketin («Razılaşdırmalar») sıxılıb kəsilməsinin
                     qarşısını alır; yer çatmasa zolaq sürüşür. Altı bölmədən sonra
                     çat ayrıca (xətlə ayrılmış) keçiddir — bölmə deyil. --}}
                <nav class="{{ $container }} flex items-stretch overflow-x-auto" aria-label="{{ $project->name }}" data-section-nav="desktop">
                    @foreach ([...$tabs, 'chat' => $chatLink] as $key => ['url' => $url, 'label' => $label, 'icon' => $icon, 'available' => $available])
                        @if ($key === 'chat')
                            <span class="my-3 w-px shrink-0 bg-black/8" aria-hidden="true"></span>
                        @endif
                        {{-- Hələ açılmayan bölmə (təqdim edilməmiş brif) strukturda
                             yerində qalır, amma keçid deyil. --}}
                        <{{ $available ? 'a' : 'span' }} @if ($available) href="{{ $url }}" @else aria-disabled="true" title="{{ t('portal.section_not_available') }}" @endif
                           @if ($key === 'chat') data-nav-chat @else data-section="{{ $key }}" @endif
                           @if ($tabActive === $key) aria-current="page" @endif
                           class="flex min-w-fit flex-1 basis-0 flex-col items-center gap-1 border-b-2 px-3 py-2.5 text-[13px] font-semibold transition-colors
                                  {{ $tabActive === $key
                                        ? 'border-yellow text-ink'
                                        : ($available ? 'border-transparent text-black/55 hover:text-ink' : 'cursor-default border-transparent text-black/30') }}">
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
                        </{{ $available ? 'a' : 'span' }}>
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

    <script>
        // Tema keçidi. Seçim `localStorage`-dadır, yəni serverə yazılmır və
        // cihaza bağlı qalır — istifadəçi telefonda tünd, masaüstündə açıq
        // işlədə bilir. Səhifə yüklənəndə vəziyyəti <head>-dəki skript qurur,
        // burada yalnız ikon sinxronlaşdırılır və klik emal olunur.
        (() => {
            const root = document.documentElement;
            const button = document.querySelector('[data-theme-toggle]');
            if (!button) return;

            const sync = () => {
                const dark = root.dataset.theme === 'dark';
                button.querySelector('[data-theme-icon="light"]').hidden = dark;
                button.querySelector('[data-theme-icon="dark"]').hidden = !dark;
                button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            };

            button.addEventListener('click', () => {
                const dark = root.dataset.theme === 'dark';
                if (dark) {
                    delete root.dataset.theme;
                } else {
                    root.dataset.theme = 'dark';
                }
                try { localStorage.setItem('archi-theme', dark ? 'light' : 'dark'); } catch (e) {}
                sync();
            });

            sync();
        })();
    </script>

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
