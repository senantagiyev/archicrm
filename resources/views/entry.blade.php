<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daxil ol — Archi CRM</title>
    <meta name="description" content="Archi CRM-ə giriş: büro komandası üçün panel, sifarişçi üçün portal.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="flex min-h-screen flex-col bg-white text-ink antialiased">

    <header class="border-b border-black/8">
        <div class="mx-auto flex h-16 max-w-[1120px] items-center justify-between px-6">
            <a href="{{ route('landing') }}" class="flex items-center gap-3" aria-label="Archi CRM">
                <svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true">
                    <rect width="28" height="28" rx="7" fill="#fdfe00"/>
                    <path d="M8.4 20.6 14 7.6l5.6 13" fill="none" stroke="#111111" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M10.9 15.7h6.2" stroke="#111111" stroke-width="2.4" stroke-linecap="round"/>
                </svg>
                <span class="text-[17px] tracking-tight"><span class="font-extrabold">Archi</span> <span class="font-normal text-black/40">CRM</span></span>
            </a>
            <a href="{{ route('landing') }}" class="text-[13px] font-medium text-black/45 transition-colors hover:text-ink">Ana səhifə</a>
        </div>
    </header>

    <main class="flex flex-1 items-center justify-center px-6 py-20">
        <div class="w-full max-w-2xl">
            <h1 class="text-center text-[28px] font-extrabold tracking-tight sm:text-[32px]">Necə daxil olursunuz?</h1>

            <div class="mt-12 grid gap-4 sm:grid-cols-2">
                {{-- Büro komandası --}}
                <a href="{{ route('filament.app.auth.login') }}"
                   class="group flex flex-col rounded-ds-md border border-black/12 p-8 transition-colors hover:border-ink">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <h2 class="mt-5 text-lg font-bold">Büro komandası</h2>
                    <p class="mt-1.5 text-[14px] leading-relaxed text-black/55">Layihələrin idarə edildiyi panel.</p>
                    <span class="mt-6 inline-flex items-center gap-1.5 text-[14px] font-semibold">
                        Daxil ol <span class="transition-transform group-hover:translate-x-1">→</span>
                    </span>
                </a>

                {{-- Sifarişçi --}}
                <a href="{{ route('portal.login') }}"
                   class="group flex flex-col rounded-ds-md border border-black/12 p-8 transition-colors hover:border-ink">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m3 11 9-8 9 8M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>
                    </svg>
                    <h2 class="mt-5 text-lg font-bold">Sifarişçi</h2>
                    <p class="mt-1.5 text-[14px] leading-relaxed text-black/55">Layihənizin gedişatı və təsdiqlər.</p>
                    <span class="mt-6 inline-flex items-center gap-1.5 text-[14px] font-semibold">
                        Portala keç <span class="transition-transform group-hover:translate-x-1">→</span>
                    </span>
                </a>
            </div>

            <p class="mt-10 text-center text-[13px] text-black/40">
                Sifarişçisinizsə və linkiniz yoxdursa, bürodakı menecerinizə yazın.
            </p>
        </div>
    </main>

    <footer class="border-t border-black/8 py-6 text-center text-[12px] text-black/35">
        © {{ date('Y') }} Archi CRM
    </footer>

</body>
</html>
