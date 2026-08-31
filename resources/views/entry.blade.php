<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daxil ol — Archi CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="flex min-h-screen flex-col bg-ink text-white">

    <header class="border-b border-white/10">
        <div class="mx-auto flex h-16 max-w-[1240px] items-center justify-between px-5">
            <a href="{{ route('landing') }}" class="flex items-center gap-2.5">
                <span class="inline-block h-3.5 w-3.5 bg-yellow"></span>
                <span class="text-lg font-extrabold tracking-wide">ARCHI CRM</span>
            </a>
            <a href="{{ route('landing') }}" class="text-[13px] font-semibold text-white/50 transition-colors hover:text-white">← Ana səhifə</a>
        </div>
    </header>

    <main class="flex flex-1 items-center justify-center px-5 py-16">
        <div class="w-full max-w-3xl">
            <div class="mb-10 text-center">
                <h1 class="text-3xl font-black sm:text-4xl">Kim kimi daxil olursunuz?</h1>
                <p class="mt-3 text-white/50">Hesab tipinizi seçin — hər tərəfin öz girişi var.</p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                {{-- Büro komandası --}}
                <a href="{{ route('filament.app.auth.login') }}"
                    class="group rounded-ds-md border border-white/15 bg-white/5 p-8 transition-all hover:-translate-y-1 hover:border-yellow">
                    <span class="inline-flex h-12 w-12 items-center justify-center bg-yellow text-ink">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/>
                        </svg>
                    </span>
                    <h2 class="mt-5 text-xl font-extrabold">Büro komandası</h2>
                    <p class="mt-2 text-sm leading-relaxed text-white/55">
                        Rəhbər, menecer, dizayner, komplektləşdirici, mühasib — layihələrin idarə edildiyi panel.
                    </p>
                    <span class="mt-6 inline-flex items-center gap-2 text-[14px] font-extrabold text-yellow">
                        Panelə daxil ol <span class="transition-transform group-hover:translate-x-1">→</span>
                    </span>
                </a>

                {{-- Sifarişçi --}}
                <a href="{{ route('portal.login') }}"
                    class="group rounded-ds-md border border-white/15 bg-white/5 p-8 transition-all hover:-translate-y-1 hover:border-yellow">
                    <span class="inline-flex h-12 w-12 items-center justify-center bg-white text-ink">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/>
                        </svg>
                    </span>
                    <h2 class="mt-5 text-xl font-extrabold">Sifarişçi</h2>
                    <p class="mt-2 text-sm leading-relaxed text-white/55">
                        Layihənizin gedişatı, brif, təsdiqlər, sənədlər və ödənişlər — şəxsi portalınızda.
                    </p>
                    <span class="mt-6 inline-flex items-center gap-2 text-[14px] font-extrabold text-yellow">
                        Portala daxil ol <span class="transition-transform group-hover:translate-x-1">→</span>
                    </span>
                </a>
            </div>

            <p class="mt-8 text-center text-[13px] text-white/35">
                Sifarişçisinizsə və linkiniz yoxdursa — bürodakı menecerinizdən dəvət istəyin.
            </p>
        </div>
    </main>

    <footer class="border-t border-white/10 py-6 text-center text-[12px] text-white/30">
        © {{ date('Y') }} Archi CRM
    </footer>
</body>
</html>
