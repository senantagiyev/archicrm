<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Müştəri portalı — Archi CRM</title>
    <meta name="description" content="Archi CRM müştəri portalı: layihənizin gedişi, brif, razılaşdırmalar və sənədlər bir yerdə.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="flex min-h-screen flex-col bg-gray-soft2 text-ink antialiased">

    {{-- Büro komandasının girişi burada QƏSDƏN yoxdur: bu səhifə yalnız
         sifarişçi üçündür. Panel öz gizli ünvanından açılır. --}}
    <header class="border-b border-black/8 bg-card">
        <div class="mx-auto flex h-[74px] max-w-[1120px] items-center justify-between px-6">
            <a href="{{ route('landing') }}"><x-archi-logo /></a>
            <a href="{{ route('portal.login') }}" class="ui-btn ui-btn-dark h-10 px-5 text-helper font-semibold" data-hover="true">Portala daxil ol</a>
        </div>
    </header>

    <main class="flex flex-1 items-center px-6 py-16">
        <div class="mx-auto grid w-full max-w-[1120px] items-center gap-12 lg:grid-cols-[1.1fr_.9fr]">
            <div>
                <span class="inline-flex items-center gap-2 text-helper font-semibold text-black/60">
                    <span class="h-2 w-2 rounded-[2px] bg-yellow"></span>Sifarişçi üçün
                </span>
                <h1 class="mt-4 font-b2b text-[34px] font-extrabold leading-[1.1] tracking-tight sm:text-[44px]">
                    Layihəniz haqqında hər şey<br class="hidden sm:block"> bir yerdə.
                </h1>
                <p class="mt-5 max-w-lg text-body leading-relaxed text-black/60">
                    Portalda brifi doldurursunuz, təqdim olunan variantları təsdiqləyirsiniz, mərhələləri,
                    sənədləri və ödənişləri izləyirsiniz — dizaynerlə yazışma da elə buradadır.
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <a href="{{ route('portal.login') }}" class="ui-btn ui-btn-primary h-12 px-7 text-body font-semibold" data-hover="true">
                        Portala daxil ol →
                    </a>
                    <span class="text-helper text-black/50">E-poçtla gələn link və ya menecerinizin verdiyi şifrə ilə</span>
                </div>

                <p class="mt-10 text-helper leading-relaxed text-black/45">
                    Girişiniz yoxdursa, bürodakı menecerinizə yazın — sizə portal dəvəti göndərəcək.
                </p>
            </div>

            {{-- Nə gözləyir — üç qısa kart --}}
            <div class="grid gap-3">
                @foreach ([
                    ['M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3', 'Brif', 'Dizaynerin sizin üçün hazırladığı suallar — cavablar avtomatik saxlanılır, istənilən vaxt davam edə bilərsiniz.'],
                    ['M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3', 'Razılaşdırmalar', 'Smeta, konsept və materiallar sizə göndərilir; bir kliklə təsdiqləyir və ya şərhlə geri qaytarırsınız.'],
                    ['M4 6h16M4 12h16M4 18h9', 'Mərhələlər və sənədlər', 'Layihənin hansı mərhələdə olduğunu, faylları, ödəniş qrafikini və müəllif nəzarəti fotolarını görürsünüz.'],
                ] as [$icon, $title, $text])
                    <div class="flex gap-4 rounded-ds-xl border border-black/8 bg-card p-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-ink">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-body font-semibold">{{ $title }}</h2>
                            <p class="mt-1 text-helper leading-relaxed text-black/55">{{ $text }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </main>

    <footer class="border-t border-black/8 py-6 text-center text-helper text-black/55">
        © {{ date('Y') }} Archi CRM
    </footer>

</body>
</html>
