<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archi CRM — Dizayn bürosu üçün idarəetmə sistemi</title>
    <meta name="description" content="Archi CRM — dizayn və memarlıq bürosu üçün idarəetmə sistemi. Layihələr, müştərilər, smeta və təsdiqlər bir yerdə.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <style>
        /* ── Portal demo animation (10s loop) ─────────────────────── */
        .demo-anim { animation-duration: 10s; animation-timing-function: ease; animation-iteration-count: infinite; animation-fill-mode: both; }

        @keyframes d-bar {
            0%, 4%      { width: 12%; }
            22%, 92%    { width: 68%; }
            100%        { width: 12%; }
        }
        @keyframes d-card {
            0%, 24%     { opacity: 0; transform: translateY(10px); }
            32%, 90%    { opacity: 1; transform: translateY(0); }
            97%, 100%   { opacity: 0; transform: translateY(10px); }
        }
        @keyframes d-tap {
            0%, 38%     { opacity: 0; transform: scale(.5); }
            43%         { opacity: .9; transform: scale(1); }
            47%         { opacity: .9; transform: scale(.8); }
            52%, 100%   { opacity: 0; transform: scale(1); }
        }
        @keyframes d-btn-wait {
            0%, 47%     { opacity: 1; }
            51%, 96%    { opacity: 0; }
            100%        { opacity: 1; }
        }
        @keyframes d-btn-done {
            0%, 48%     { opacity: 0; }
            52%, 95%    { opacity: 1; }
            99%, 100%   { opacity: 0; }
        }
        @keyframes d-chat {
            0%, 62%     { opacity: 0; transform: translateY(12px); }
            70%, 90%    { opacity: 1; transform: translateY(0); }
            97%, 100%   { opacity: 0; transform: translateY(12px); }
        }

        .d-bar-fill  { width: 12%; animation-name: d-bar; }
        .d-card      { opacity: 0; animation-name: d-card; }
        .d-tap       { opacity: 0; animation-name: d-tap; }
        .d-btn-wait  { animation-name: d-btn-wait; }
        .d-btn-done  { opacity: 0; animation-name: d-btn-done; }
        .d-chat      { opacity: 0; animation-name: d-chat; }

        @media (prefers-reduced-motion: reduce) {
            .demo-anim  { animation: none; }
            .d-bar-fill { width: 68%; }
            .d-card, .d-chat, .d-btn-done { opacity: 1; transform: none; }
            .d-btn-wait, .d-tap { opacity: 0; }
        }
    </style>
</head>
<body class="bg-white text-ink antialiased">

    {{-- Header --}}
    <header class="sticky top-0 z-40 border-b border-black/8 bg-white/90 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-[1120px] items-center justify-between px-6">
            <a href="{{ route('landing') }}" class="flex items-center gap-3" aria-label="Archi CRM">
                <svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true">
                    <rect width="28" height="28" rx="7" fill="#fdfe00"/>
                    <path d="M8.4 20.6 14 7.6l5.6 13" fill="none" stroke="#111111" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M10.9 15.7h6.2" stroke="#111111" stroke-width="2.4" stroke-linecap="round"/>
                </svg>
                <span class="text-[17px] tracking-tight"><span class="font-extrabold">Archi</span> <span class="font-normal text-black/40">CRM</span></span>
            </a>
            <a href="{{ route('entry') }}" class="ui-btn ui-btn-dark h-10 px-5 text-[13px] font-semibold" data-hover="true">Daxil ol</a>
        </div>
    </header>

    {{-- Hero --}}
    <section class="mx-auto max-w-[1120px] px-6 pt-20 pb-24 lg:pt-28">
        <div class="grid items-center gap-16 lg:grid-cols-[1fr_360px] lg:gap-24">
            <div class="max-w-xl">
                <h1 class="text-[40px] font-extrabold leading-[1.1] tracking-tight sm:text-[52px]">
                    Dizayn bürosu üçün idarəetmə sistemi<span class="text-black/30">.</span>
                </h1>
                <p class="mt-6 text-lg leading-relaxed text-black/60">
                    Layihələr, müştərilər, smeta və təsdiqlər — hamısı bir yerdə.
                </p>
                <div class="mt-10 flex items-center gap-5">
                    <a href="{{ route('entry') }}" class="ui-btn ui-btn-dark h-12 px-8 text-[15px] font-semibold" data-hover="true">Daxil ol</a>
                    <span class="text-[13px] text-black/40">3 dil · AZ / RU / EN</span>
                </div>
            </div>

            {{-- Portal demo: phone frame --}}
            <div class="mx-auto w-full max-w-[300px]">
                <div class="rounded-[28px] border border-black/12 bg-white p-2.5 shadow-[0_24px_60px_-24px_rgba(0,0,0,0.18)]">
                    <div class="relative overflow-hidden rounded-[20px] border border-black/6 bg-gray-soft2 px-4 pb-5 pt-4">

                        {{-- screen header --}}
                        <div class="mb-4 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <svg width="16" height="16" viewBox="0 0 28 28" aria-hidden="true">
                                    <rect width="28" height="28" rx="7" fill="#fdfe00"/>
                                    <path d="M8.4 20.6 14 7.6l5.6 13" fill="none" stroke="#111111" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M10.9 15.7h6.2" stroke="#111111" stroke-width="2.6" stroke-linecap="round"/>
                                </svg>
                                <span class="text-[11px] font-semibold">Villa layihəsi</span>
                            </div>
                            <span class="text-[10px] text-black/35">Portal</span>
                        </div>

                        {{-- 1. progress --}}
                        <div class="rounded-ds bg-white p-3.5">
                            <div class="flex items-center justify-between text-[10px] font-medium text-black/50">
                                <span>Layihənin gedişatı</span>
                                <span class="font-semibold text-ink">68%</span>
                            </div>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-pill bg-neutral-soft">
                                <div class="demo-anim d-bar-fill h-full rounded-pill bg-yellow-line"></div>
                            </div>
                        </div>

                        {{-- 2. approval card --}}
                        <div class="demo-anim d-card mt-2.5 rounded-ds bg-white p-3.5">
                            <p class="text-[11px] font-semibold">Yemək masası — 850 ₼</p>
                            <p class="mt-0.5 text-[10px] text-black/45">Təsdiqinizi gözləyir</p>
                            <div class="relative mt-2.5 h-8">
                                <span class="demo-anim d-btn-wait absolute inset-0 inline-flex items-center justify-center rounded-ds bg-yellow text-[11px] font-bold">Razılaş</span>
                                <span class="demo-anim d-btn-done absolute inset-0 inline-flex items-center justify-center gap-1 rounded-ds bg-ok-soft text-[11px] font-bold text-ok">Razılaşdı ✓</span>
                                {{-- tap indicator --}}
                                <span class="demo-anim d-tap absolute left-1/2 top-1/2 h-7 w-7 -translate-x-1/2 -translate-y-1/2 rounded-pill border-2 border-ink/60 bg-white/40"></span>
                            </div>
                        </div>

                        {{-- 3. chat bubble --}}
                        <div class="demo-anim d-chat mt-2.5 flex items-start gap-2">
                            <span class="mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-pill bg-ink text-[8px] font-bold text-white">A</span>
                            <div class="rounded-ds rounded-tl-[4px] bg-white p-2.5 text-[10px] leading-relaxed text-black/70">
                                Təşəkkürlər. Sifarişi bu gün veririk.
                            </div>
                        </div>

                    </div>
                </div>
                <p class="mt-4 text-center text-[12px] text-black/40">Müştəri portalı — belə görünür</p>
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section class="border-t border-black/8">
        <div class="mx-auto max-w-[1120px] px-6 py-20">
            <h2 class="max-w-md text-2xl font-extrabold tracking-tight">Büronun gündəlik işi üçün</h2>
            <div class="mt-12 grid gap-x-12 gap-y-12 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['M4 20V8l8-5 8 5v12M9 20v-6h6v6', 'Layihələr və mərhələlər', 'Hər layihənin mərhələləri, tapşırıqları və son tarixləri var. Kim nə edir — aydın görünür.'],
                    ['M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3', 'Müştəri brifi', 'Müştəriyə link göndərirsiniz. Sualları öz vaxtında doldurur, cavablar sistemdə saxlanılır.'],
                    ['M12 3v18M17 7.5c0-1.5-2.2-2.5-5-2.5s-5 1-5 2.5S9.2 10 12 10s5 1 5 2.5-2.2 2.5-5 2.5-5-1-5-2.5', 'Smeta və ödənişlər', 'Smeta, ödənişlər və qalıq borc bir səhifədədir. Hesablama avtomatik aparılır.'],
                    ['M20 6 9 17l-5-5', 'Müştəri təsdiqi', 'Qiyməti müştəriyə göndərirsiniz. O, «Razılaş» düyməsini basır — qərar tarixi ilə saxlanılır.'],
                    ['M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5M9 12h.01M13 12h.01M17 12h.01', 'Layihə çatı', 'Hər layihənin öz yazışması var. Komanda və müştəri eyni yerdə yazışır.'],
                    ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M3 12h18M12 3c2.5 2.4 3.8 5.6 3.8 9S14.5 18.6 12 21c-2.5-2.4-3.8-5.6-3.8-9S9.5 5.4 12 3', 'Üç dil', 'İnterfeys Azərbaycan, rus və ingilis dillərindədir. Hər istifadəçi öz dilini seçir.'],
                ] as [$path, $title, $text])
                    <div>
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $path }}"/>
                        </svg>
                        <h3 class="mt-4 text-[15px] font-bold">{{ $title }}</h3>
                        <p class="mt-2 max-w-[300px] text-[14px] leading-relaxed text-black/55">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Customer portal band --}}
    <section class="border-t border-black/8 bg-gray-soft2">
        <div class="mx-auto max-w-[1120px] px-6 py-16">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-extrabold tracking-tight">Müştəriniz üçün ayrıca portal<span class="text-black/30">.</span></h2>
                <p class="mt-3 text-[15px] text-black/55">Müştəri sistemi öyrənmir — sadəcə linkə daxil olur.</p>
                <ul class="mt-8 space-y-3.5">
                    @foreach ([
                        'Qeydiyyat yoxdur — e-poçtdakı linklə girir.',
                        'Layihənin gedişatını, sənədləri və ödənişləri görür.',
                        'Təsdiqlərini telefonundan verir.',
                    ] as $item)
                        <li class="flex items-start gap-3 text-[15px] leading-relaxed">
                            <span class="mt-[9px] inline-block h-1.5 w-1.5 shrink-0 rounded-pill bg-yellow-line"></span>{{ $item }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section class="border-t border-black/8">
        <div class="mx-auto flex max-w-[1120px] flex-wrap items-center justify-between gap-6 px-6 py-16">
            <p class="text-xl font-extrabold tracking-tight">Büronuzun bütün işi bir yerdə.</p>
            <a href="{{ route('entry') }}" class="ui-btn ui-btn-dark h-12 px-8 text-[15px] font-semibold" data-hover="true">Daxil ol</a>
        </div>
    </section>

    {{-- Footer --}}
    <footer class="border-t border-black/8">
        <div class="mx-auto flex max-w-[1120px] flex-wrap items-center justify-between gap-4 px-6 py-8">
            <div class="flex items-center gap-2.5">
                <svg width="20" height="20" viewBox="0 0 28 28" aria-hidden="true">
                    <rect width="28" height="28" rx="7" fill="#fdfe00"/>
                    <path d="M8.4 20.6 14 7.6l5.6 13" fill="none" stroke="#111111" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M10.9 15.7h6.2" stroke="#111111" stroke-width="2.4" stroke-linecap="round"/>
                </svg>
                <span class="text-[13px]"><span class="font-extrabold">Archi</span> <span class="text-black/40">CRM</span></span>
            </div>
            <p class="text-[12px] text-black/40">© {{ date('Y') }} Archi CRM · Dizayn bürosu üçün idarəetmə sistemi</p>
        </div>
    </footer>

</body>
</html>
