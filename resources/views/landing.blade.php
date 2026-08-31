<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archi CRM — Dizayn bürosu üçün rahat idarəetmə</title>
    <meta name="description" content="Memarlıq və dizayn büroları üçün CRM: brif, razılaşdırmalar, smeta, tapşırıqlar və müştəri portalı — hamısı bir yerdə.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
    <style>
        .reveal { opacity: 0; transform: translateY(24px); transition: opacity .6s ease, transform .6s ease; }
        .reveal.on { opacity: 1; transform: none; }
        .marquee { animation: marquee 26s linear infinite; }
        @keyframes marquee { from { transform: translateX(0); } to { transform: translateX(-50%); } }
        @media (prefers-reduced-motion: reduce) { .marquee { animation: none; } }
    </style>
</head>
<body class="bg-white">

    {{-- ══ Header ══ --}}
    <header class="sticky top-0 z-40 border-b border-white/10 bg-ink">
        <div class="mx-auto flex h-16 max-w-[1240px] items-center justify-between px-5">
            <a href="/" class="flex items-center gap-2.5">
                <span class="inline-block h-3.5 w-3.5 bg-yellow"></span>
                <span class="text-lg font-extrabold tracking-wide text-white">ARCHI CRM</span>
            </a>
            <nav class="hidden items-center gap-7 text-[13px] font-semibold text-white/60 md:flex">
                <a href="#imkanlar" class="transition-colors hover:text-white">İmkanlar</a>
                <a href="#axin" class="transition-colors hover:text-white">Necə işləyir</a>
                <a href="#portal" class="transition-colors hover:text-white">Müştəri portalı</a>
            </nav>
            <a href="{{ route('entry') }}" class="ui-btn ui-btn-primary h-10 px-5 text-[13px] font-bold" data-hover="true">
                Daxil ol
            </a>
        </div>
    </header>

    {{-- ══ Hero ══ --}}
    <section class="bg-ink text-white">
        <div class="mx-auto max-w-[1240px] px-5 pb-20 pt-16 lg:pt-24">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                <div>
                    <p class="mb-5 inline-flex items-center gap-2 rounded-pill border border-yellow/40 px-4 py-1.5 text-[12px] font-bold uppercase tracking-widest text-yellow">
                        Memarlıq və dizayn büroları üçün
                    </p>
                    <h1 class="text-4xl font-black leading-[1.08] sm:text-5xl lg:text-6xl">
                        Layihələriniz<br>
                        <span class="bg-yellow px-2 text-ink">nəzarətinizdə.</span><br>
                        Xaos deyil.
                    </h1>
                    <p class="mt-6 max-w-lg text-lg leading-relaxed text-white/70">
                        Lidlərdən təhvilə qədər bütün iş bir yerdə: müştəri brifi, mərhələlər, tapşırıqlar,
                        smeta, razılaşdırmalar və çat. Messencer qruplarında itən heç nə — hər qərar imzalı, hər manat hesabda.
                    </p>
                    <div class="mt-9 flex flex-wrap items-center gap-4">
                        <a href="{{ route('entry') }}" class="ui-btn ui-btn-primary h-13 px-8 py-4 text-[15px] font-extrabold" data-hover="true">
                            İşə başla →
                        </a>
                        <a href="#imkanlar" class="ui-btn ui-btn-on-ink h-13 px-7 py-4 text-[15px] font-bold" data-hover="true">
                            İmkanlara bax
                        </a>
                    </div>
                    <div class="mt-12 grid max-w-md grid-cols-3 gap-6 border-t border-white/10 pt-8">
                        <div><p class="text-3xl font-black text-yellow">1</p><p class="mt-1 text-[12px] font-medium text-white/50">sistem — bütün büro</p></div>
                        <div><p class="text-3xl font-black text-yellow">6</p><p class="mt-1 text-[12px] font-medium text-white/50">rol, dəqiq hüquqlar</p></div>
                        <div><p class="text-3xl font-black text-yellow">3</p><p class="mt-1 text-[12px] font-medium text-white/50">dil: AZ · RU · EN</p></div>
                    </div>
                </div>

                {{-- Hero mock: approval card --}}
                <div class="reveal relative hidden lg:block">
                    <div class="absolute -left-6 -top-6 h-full w-full rounded-ds-md border-2 border-yellow/30"></div>
                    <div class="relative rounded-ds-md bg-white p-6 text-ink shadow-2xl">
                        <div class="mb-4 flex items-center justify-between border-b border-black/10 pb-4">
                            <div>
                                <p class="text-[11px] font-bold uppercase tracking-wider text-black/40">Layihə #142</p>
                                <p class="text-lg font-extrabold">Villa — Badamdar</p>
                            </div>
                            <span class="rounded-pill bg-warn-soft px-3 py-1 text-[12px] font-bold text-warn">Razılaşmada</span>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between rounded-ds bg-gray-soft px-4 py-3">
                                <div>
                                    <p class="text-sm font-bold">Komplektasiya: Yemək masası</p>
                                    <p class="text-[12px] text-black/50">Qonaq otağı · Embawood</p>
                                </div>
                                <p class="text-lg font-extrabold">850 ₼</p>
                            </div>
                            <div class="flex gap-2">
                                <span class="ui-btn ui-btn-primary h-10 flex-1 text-[13px] font-extrabold">Razılaş</span>
                                <span class="ui-btn ui-btn-outline h-10 flex-1 text-[13px] font-bold">Şərhlə rədd et</span>
                            </div>
                            <div class="flex items-center gap-2 rounded-ds bg-ok-soft px-4 py-2.5 text-[12px] font-semibold text-ok">
                                ✓ Razılaşıldı · 14 may, 14:32 · A. Məmmədova — jurnala yazıldı
                            </div>
                            <div class="border-t border-black/10 pt-3">
                                <div class="mb-1.5 flex items-center justify-between text-[12px] font-semibold text-black/60">
                                    <span>Layihənin hazırlığı</span><span class="font-extrabold text-ink">68%</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-pill bg-neutral-soft">
                                    <div class="h-full w-[68%] bg-yellow-line"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Marquee strip --}}
        <div class="overflow-hidden border-t border-white/10 bg-yellow py-3">
            <div class="marquee flex w-max gap-10 whitespace-nowrap text-[13px] font-extrabold uppercase tracking-widest text-ink">
                @for ($i = 0; $i < 2; $i++)
                    <span>Brif</span><span>·</span><span>Mərhələlər</span><span>·</span><span>Tapşırıqlar</span><span>·</span>
                    <span>Smeta</span><span>·</span><span>Komplektasiya</span><span>·</span><span>Razılaşdırmalar</span><span>·</span>
                    <span>Ödənişlər</span><span>·</span><span>Çat</span><span>·</span><span>Müştəri portalı</span><span>·</span>
                @endfor
            </div>
        </div>
    </section>

    {{-- ══ Problem → Həll ══ --}}
    <section class="mx-auto max-w-[1240px] px-5 py-20">
        <div class="reveal mb-12 max-w-2xl">
            <p class="mb-3 flex items-center gap-3 text-[12px] font-extrabold uppercase tracking-widest text-black/40">
                <span class="inline-block h-0.5 w-8 bg-yellow-line"></span> Tanış gəlir?
            </p>
            <h2 class="text-3xl font-black leading-tight sm:text-4xl">Hər dizayner bunu yaşayıb</h2>
        </div>
        <div class="grid gap-5 md:grid-cols-3">
            @foreach ([
                ['«Mən bunu təsdiq etməmişdim»', 'Müştəri 3 ay əvvəl WhatsApp-da razılaşdığı kollajı dandı. Yazışma 400 mesajın altında itib.', 'Archi CRM-də hər təsdiq tarix, ad və şərhlə jurnala yazılır — mübahisə ediləcək heç nə qalmır.'],
                ['«FINAL_v3_yeni_SON.jpg»', 'Hansı fayl sonuncudur? Usta köhnə çertyojla işləyir, divar səhv yerdə hörülür.', 'Fayllar və sənədlər layihənin içində, tipləşdirilmiş — müqavilə, aktlar, çertyojlar bir yerdə.'],
                ['«Brifi həftə sonu dolduraram»', 'Müştəridən tələbləri saatlarla telefonla çıxarırsınız, yenə də yarısı yadından çıxır.', 'Strukturlu brif linki: müştəri öz vaxtında doldurur, cavablar avtomatik saxlanılır, PDF hazır gəlir.'],
            ] as [$title, $pain, $fix])
                <div class="reveal rounded-ds-md border border-black/10 p-6">
                    <h3 class="text-lg font-extrabold">{{ $title }}</h3>
                    <p class="mt-3 text-sm leading-relaxed text-black/55">{{ $pain }}</p>
                    <p class="mt-4 border-l-2 border-yellow-line pl-3 text-sm font-semibold leading-relaxed">{{ $fix }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ══ İmkanlar ══ --}}
    <section id="imkanlar" class="bg-gray-soft2 py-20">
        <div class="mx-auto max-w-[1240px] px-5">
            <div class="reveal mb-12 max-w-2xl">
                <p class="mb-3 flex items-center gap-3 text-[12px] font-extrabold uppercase tracking-widest text-black/40">
                    <span class="inline-block h-0.5 w-8 bg-yellow-line"></span> İmkanlar
                </p>
                <h2 class="text-3xl font-black leading-tight sm:text-4xl">Rahat idarə — bir pəncərədən</h2>
                <p class="mt-4 text-black/55">Messencerlərin bacarmadığını edir: büronun bütün iş dövrünü strukturlaşdırır.</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['01', 'Dərin müştəri brifi', '15 bölmə, otaq-otaq anket, «dizaynerə həvalə et» seçimi, avtomatik saxlanma və hazır PDF. Saatlarla müsahibə — tarixdə qaldı.'],
                    ['02', 'Hüquqi izli razılaşdırmalar', 'Smeta sətri və ya mebel pozisiyası müştəriyə göndərilir: «Razılaş» / «Şərhlə rədd et». Hər qərar jurnalda — kim, nə vaxt, nə dedi.'],
                    ['03', 'Maliyyə nəzarəti', 'Smeta + komplektasiya + ödəniş qrafiki. Borc avtomatik hesablanır: razılaşdırılmış məbləğ − ödənilən. Gecikən ödənişə xəbərdarlıq.'],
                    ['04', 'Mərhələlər və tapşırıqlar', 'Hazır şablonlar («Dizayn layihəsi», «Kompleks layihə»), icraçı + son tarix məcburi, gecikəndə status özü qırmızıya keçir.'],
                    ['05', 'Layihə çatı + səsli bildiriş', 'Hər layihənin öz yazışması. Yeni mesaj — səs + sayğac. Komanda və müştəri eyni kanalda, amma daxili mətbəx müştəriyə görünmür.'],
                    ['06', 'Rəhbər dashboardu', 'Bütün layihələr bir baxışda: nə yanır, kim yüklüdür, nə qədər borc var, neçə yeni lid gəlib. Qərar üçün gəzməyə ehtiyac yoxdur.'],
                ] as [$no, $title, $text])
                    <div class="reveal group rounded-ds-md border border-black/10 bg-white p-6 transition-all hover:-translate-y-1 hover:border-ink">
                        <p class="text-[13px] font-black text-black/25 transition-colors group-hover:text-yellow-line">{{ $no }}</p>
                        <h3 class="mt-3 text-[17px] font-extrabold">{{ $title }}</h3>
                        <p class="mt-2.5 text-sm leading-relaxed text-black/55">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ══ Axın ══ --}}
    <section id="axin" class="mx-auto max-w-[1240px] px-5 py-20">
        <div class="reveal mb-12 max-w-2xl">
            <p class="mb-3 flex items-center gap-3 text-[12px] font-extrabold uppercase tracking-widest text-black/40">
                <span class="inline-block h-0.5 w-8 bg-yellow-line"></span> Necə işləyir
            </p>
            <h2 class="text-3xl font-black leading-tight sm:text-4xl">Liddən təhvilə — 5 addım</h2>
        </div>
        <div class="grid gap-0 md:grid-cols-5">
            @foreach ([
                ['Lid gəlir', 'Instagram, zəng, tövsiyə — mənbə və tarixçə ilə qeydə alınır.'],
                ['Layihə açılır', 'Bir klik: lid müştəriyə çevrilir, mərhələ şablonu tətbiq olunur.'],
                ['Müştəri brifi doldurur', 'Parolsuz linklə girir, öz vaxtında cavablayır.'],
                ['Komanda işləyir', 'Tapşırıqlar, təsdiqlər, ödənişlər — hamısı izlənir.'],
                ['Təhvil', 'Hazırlıq 100%, borc 0, bütün qərarlar arxivdə.'],
            ] as $i => [$title, $text])
                <div class="reveal relative border-black/10 p-6 max-md:border-b md:border-r md:last:border-r-0">
                    <span class="inline-flex h-10 w-10 items-center justify-center bg-ink text-[15px] font-black text-yellow">{{ $i + 1 }}</span>
                    <h3 class="mt-4 text-[15px] font-extrabold">{{ $title }}</h3>
                    <p class="mt-2 text-[13px] leading-relaxed text-black/55">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ══ Müştəri portalı ══ --}}
    <section id="portal" class="bg-ink py-20 text-white">
        <div class="mx-auto grid max-w-[1240px] items-center gap-12 px-5 lg:grid-cols-2">
            <div class="reveal">
                <p class="mb-3 flex items-center gap-3 text-[12px] font-extrabold uppercase tracking-widest text-white/40">
                    <span class="inline-block h-0.5 w-8 bg-yellow"></span> Müştəri portalı
                </p>
                <h2 class="text-3xl font-black leading-tight sm:text-4xl">
                    Müştəriniz üçün — <span class="text-yellow">sıfır zəhmət</span>
                </h2>
                <ul class="mt-7 space-y-4">
                    @foreach ([
                        'Qeydiyyat yoxdur — e-poçtdakı linklə bir kliklə daxil olur',
                        'Layihənin gedişatını canlı görür: mərhələlər, hazırlıq faizi, müddətlər',
                        'Təsdiqləri telefonundan verir — «hara imza atım?» zəngləri bitir',
                        'Sənədlər, ödəniş qrafiki və qalıq borc həmişə göz önündə',
                        'Üç dildə interfeys: Azərbaycan, rus, ingilis',
                    ] as $item)
                        <li class="flex items-start gap-3 text-[15px] leading-relaxed text-white/80">
                            <span class="mt-1.5 inline-block h-2 w-2 shrink-0 bg-yellow"></span>{{ $item }}
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="reveal rounded-ds-md border border-white/15 bg-white/5 p-7">
                <p class="text-[12px] font-extrabold uppercase tracking-widest text-white/40">Sizin komanda üçün isə</p>
                <div class="mt-5 space-y-4">
                    @foreach ([
                        ['Sahibkar', 'hər şeyi görür: pullar, müddətlər, yüklənmə'],
                        ['Layihə meneceri', 'öz layihələrini liddən təhvilə aparır'],
                        ['Dizayner', 'brif, mərhələlər, fayllarla işləyir'],
                        ['Komplektləşdirici', 'satınalmalar və təsdiqlərlə'],
                        ['Mühasib', 'smeta və ödənişlər — brifə girişi yoxdur'],
                    ] as [$role, $desc])
                        <div class="flex items-baseline justify-between gap-4 border-b border-white/10 pb-3.5 last:border-0">
                            <span class="shrink-0 text-[14px] font-extrabold text-yellow">{{ $role }}</span>
                            <span class="text-right text-[13px] text-white/60">{{ $desc }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-5 text-[12px] leading-relaxed text-white/40">
                    Hüquqlar interfeysdə gizlətməklə deyil, sistem səviyyəsində tətbiq olunur — hər rol yalnız öz zonasını görür.
                </p>
            </div>
        </div>
    </section>

    {{-- ══ CTA ══ --}}
    <section class="mx-auto max-w-[1240px] px-5 py-24 text-center">
        <div class="reveal">
            <h2 class="mx-auto max-w-2xl text-3xl font-black leading-tight sm:text-5xl">
                Büronuzu <span class="bg-yellow px-2">bir sistemə</span> köçürün
            </h2>
            <p class="mx-auto mt-5 max-w-xl text-black/55">
                İlk layihəni 2 dəqiqəyə açın: müştəri, şablon, brif linki — və komanda artıq işləyir.
            </p>
            <a href="{{ route('entry') }}" class="ui-btn ui-btn-dark mx-auto mt-9 inline-flex h-14 px-10 text-base font-extrabold" data-hover="true">
                Daxil ol →
            </a>
        </div>
    </section>

    {{-- ══ Footer ══ --}}
    <footer class="border-t border-black/10">
        <div class="mx-auto flex max-w-[1240px] flex-wrap items-center justify-between gap-4 px-5 py-8">
            <div class="flex items-center gap-2.5">
                <span class="inline-block h-3 w-3 bg-yellow"></span>
                <span class="text-sm font-extrabold tracking-wide">ARCHI CRM</span>
            </div>
            <p class="text-[12px] text-black/40">© {{ date('Y') }} Archi. Memarlıq və dizayn büroları üçün idarəetmə sistemi.</p>
        </div>
    </footer>

    <script>
        const io = new IntersectionObserver((entries) => {
            entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('on'); io.unobserve(e.target); } });
        }, { threshold: 0.12 });
        document.querySelectorAll('.reveal').forEach(el => io.observe(el));
    </script>
</body>
</html>
