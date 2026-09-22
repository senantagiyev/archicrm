<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ t('portal.login_title') }} — Archi CRM</title>
    <meta name="description" content="Archi CRM müştəri portalına giriş.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="flex min-h-screen flex-col bg-gray-soft2 text-ink antialiased">

    <header class="border-b border-black/8 bg-card">
        <div class="mx-auto flex h-[74px] max-w-[1120px] items-center justify-between px-6">
            <a href="{{ route('landing') }}"><x-archi-logo /></a>
            <a href="{{ route('entry') }}" class="text-helper font-medium text-black/60 transition-colors hover:text-ink">Geri</a>
        </div>
    </header>

    <main class="flex flex-1 items-center justify-center px-6 py-16">
        <div class="w-full max-w-[420px] rounded-[18px] border border-black/8 bg-card p-8 shadow-[0_1px_2px_rgba(0,0,0,.04),0_18px_50px_-24px_rgba(0,0,0,.18)] sm:p-10">
            <span class="inline-flex items-center gap-2 text-helper font-semibold text-black/60">
                <span class="h-2 w-2 rounded-[2px] bg-yellow"></span>Müştəri portalı
            </span>
            <h1 class="mt-4 font-b2b text-heading font-semibold tracking-normal">{{ t('portal.login_title') }}</h1>
            <p class="mt-2 text-body leading-relaxed text-black/60">{{ t('portal.login_hint') }}</p>

            @if (session('status'))
                <div class="mt-5 rounded-ds-lg border border-ok/30 bg-ok-soft px-4 py-3 text-helper font-medium text-ok">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-5 rounded-ds-lg border border-error/30 bg-error-soft px-4 py-3 text-helper font-medium text-error">
                    {{ $errors->first() }}
                </div>
            @endif

            @php $recaptchaSiteKey = config('services.recaptcha.site_key'); @endphp
            <form id="portalLoginForm" method="post" action="{{ route('portal.login-link') }}" class="mt-8 space-y-4">
                @csrf
                <div>
                    <label for="email" class="mb-1.5 block text-helper font-semibold">{{ t('portal.email') }}</label>
                    <input id="email" name="email" type="email" required autofocus
                        value="{{ old('email') }}"
                        class="h-12 w-full rounded-ds border border-black/15 px-3.5 text-body outline-none transition-colors focus:border-ink">
                </div>

                @if ($recaptchaSiteKey)
                    {{-- v3: invisible; a fresh token is fetched at submit time. --}}
                    <input type="hidden" name="g-recaptcha-response" id="recaptchaToken">
                @endif

                <button class="ui-btn ui-btn-dark h-12 w-full text-body font-semibold" data-hover="true">
                    {{ t('portal.send_login_link') }}
                </button>
            </form>

            @if ($recaptchaSiteKey)
                <p class="mt-3 text-helper leading-relaxed text-black/55">
                    Bu sayt Google reCAPTCHA ilə qorunur.
                </p>
            @endif

            <p class="mt-8 text-helper leading-relaxed text-black/60">
                {{ t('portal.login_no_link') }}
            </p>
        </div>
    </main>

    <footer class="border-t border-black/8 py-6 text-center text-helper text-black/55">
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
