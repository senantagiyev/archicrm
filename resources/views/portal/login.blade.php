<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ t('portal.login_title') }} — Archi CRM</title>
    <meta name="description" content="Archi CRM müştəri portalına giriş.">
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
            <a href="{{ route('entry') }}" class="text-[13px] font-medium text-black/45 transition-colors hover:text-ink">Geri</a>
        </div>
    </header>

    <main class="flex flex-1 items-center justify-center px-6 py-16">
        <div class="w-full max-w-sm">
            <h1 class="text-[26px] font-extrabold tracking-tight">{{ t('portal.login_title') }}</h1>
            <p class="mt-2 text-[14px] leading-relaxed text-black/55">{{ t('portal.login_hint') }}</p>

            @if (session('status'))
                <div class="mt-5 rounded-ds-md border border-ok/30 bg-ok-soft px-4 py-3 text-[13px] font-medium text-ok">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-5 rounded-ds-md border border-error/30 bg-error-soft px-4 py-3 text-[13px] font-medium text-error">
                    {{ $errors->first() }}
                </div>
            @endif

            @php $recaptchaSiteKey = config('services.recaptcha.site_key'); @endphp
            <form id="portalLoginForm" method="post" action="{{ route('portal.login-link') }}" class="mt-8 space-y-4">
                @csrf
                <div>
                    <label for="email" class="mb-1.5 block text-[13px] font-semibold">{{ t('portal.email') }}</label>
                    <input id="email" name="email" type="email" required autofocus
                        value="{{ old('email') }}"
                        class="h-12 w-full rounded-ds border border-black/15 px-3.5 text-sm outline-none transition-colors focus:border-ink">
                </div>

                @if ($recaptchaSiteKey)
                    {{-- v3: invisible; a fresh token is fetched at submit time. --}}
                    <input type="hidden" name="g-recaptcha-response" id="recaptchaToken">
                @endif

                <button class="ui-btn ui-btn-dark h-12 w-full text-sm font-semibold" data-hover="true">
                    {{ t('portal.send_login_link') }}
                </button>
            </form>

            @if ($recaptchaSiteKey)
                <p class="mt-3 text-[11px] leading-relaxed text-black/30">
                    Bu sayt Google reCAPTCHA ilə qorunur.
                </p>
            @endif

            <p class="mt-8 text-[13px] leading-relaxed text-black/40">
                {{ t('portal.login_no_link') }}
            </p>
        </div>
    </main>

    <footer class="border-t border-black/8 py-6 text-center text-[12px] text-black/35">
        © {{ date('Y') }} Archi CRM
    </footer>

    @if ($recaptchaSiteKey)
        <script src="https://www.google.com/recaptcha/api.js?render={{ $recaptchaSiteKey }}"></script>
        <script>
            (() => {
                const form = document.getElementById('portalLoginForm');
                const field = document.getElementById('recaptchaToken');
                const key = @json($recaptchaSiteKey);
                let ready = false;

                form.addEventListener('submit', (e) => {
                    if (ready) return; // second pass: token set, let it through
                    e.preventDefault();
                    grecaptcha.ready(() => {
                        grecaptcha.execute(key, { action: 'portal_login' }).then((token) => {
                            field.value = token;
                            ready = true;
                            form.submit();
                        });
                    });
                });
            })();
        </script>
    @endif
</body>
</html>
