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

    @php
        $recaptchaSiteKey = config('services.recaptcha.site_key');
        // Hansı tab açıq qalsın: link göndəriləndən sonra və ya link formasında
        // xəta olanda «Link ilə», qalan hallarda «Şifrə ilə».
        $mode = old('mode', session('status') ? 'link' : 'password');
        $mode = in_array($mode, ['password', 'link'], true) ? $mode : 'password';
    @endphp

    <main class="flex flex-1 items-center justify-center px-6 py-16">
        <div class="w-full max-w-[440px] rounded-[18px] border border-black/8 bg-card p-8 shadow-[0_1px_2px_rgba(0,0,0,.04),0_18px_50px_-24px_rgba(0,0,0,.18)] sm:p-10">
            <span class="inline-flex items-center gap-2 text-helper font-semibold text-black/60">
                <span class="h-2 w-2 rounded-[2px] bg-yellow"></span>Müştəri portalı
            </span>
            <h1 class="mt-4 font-b2b text-heading font-semibold tracking-normal">{{ t('portal.login_title') }}</h1>

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

            {{-- İki giriş yolu — eyni hesab, müştəri hansı rahatdırsa --}}
            <div class="mt-7 grid grid-cols-2 gap-1 rounded-ds-lg bg-neutral-soft p-1" role="tablist" data-login-tabs>
                <button type="button" role="tab" data-login-tab="password" aria-selected="{{ $mode === 'password' ? 'true' : 'false' }}"
                    class="h-10 rounded-ds text-helper font-semibold transition-colors aria-selected:bg-card aria-selected:text-ink aria-selected:shadow-sm text-black/55">
                    {{ t('portal.login_password_tab') }}
                </button>
                <button type="button" role="tab" data-login-tab="link" aria-selected="{{ $mode === 'link' ? 'true' : 'false' }}"
                    class="h-10 rounded-ds text-helper font-semibold transition-colors aria-selected:bg-card aria-selected:text-ink aria-selected:shadow-sm text-black/55">
                    {{ t('portal.login_link_tab') }}
                </button>
            </div>

            {{-- Şifrə ilə --}}
            <form method="post" action="{{ route('portal.login.password') }}" class="mt-6 space-y-4" data-login-panel="password" data-recaptcha-form @if ($mode !== 'password') hidden @endif>
                @csrf
                <input type="hidden" name="mode" value="password">
                <p class="text-body leading-relaxed text-black/60">{{ t('portal.login_password_hint') }}</p>
                <div>
                    <label for="pw_email" class="mb-1.5 block text-helper font-semibold">{{ t('portal.email') }}</label>
                    <input id="pw_email" name="email" type="email" required autocomplete="username"
                        value="{{ old('mode') === 'password' ? old('email') : '' }}"
                        class="h-12 w-full rounded-ds border border-black/15 px-3.5 text-body outline-none transition-colors focus:border-ink">
                </div>
                <div>
                    <label for="pw_password" class="mb-1.5 block text-helper font-semibold">{{ t('portal.password') }}</label>
                    <input id="pw_password" name="password" type="password" required autocomplete="current-password"
                        class="h-12 w-full rounded-ds border border-black/15 px-3.5 text-body outline-none transition-colors focus:border-ink">
                </div>
                <label class="flex items-center gap-2.5 text-helper text-black/70">
                    <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded-[4px] border-black/30">
                    {{ t('portal.remember_me') }}
                </label>
                @if ($recaptchaSiteKey)
                    <input type="hidden" name="g-recaptcha-response" data-recaptcha-token>
                @endif
                <button class="ui-btn ui-btn-dark h-12 w-full text-body font-semibold" data-hover="true">
                    {{ t('portal.login_button') }}
                </button>
                <p class="text-helper leading-relaxed text-black/55">{{ t('portal.login_no_password') }}</p>
            </form>

            {{-- Link ilə (parolsuz) --}}
            <form method="post" action="{{ route('portal.login-link') }}" class="mt-6 space-y-4" data-login-panel="link" data-recaptcha-form @if ($mode !== 'link') hidden @endif>
                @csrf
                <input type="hidden" name="mode" value="link">
                <p class="text-body leading-relaxed text-black/60">{{ t('portal.login_hint') }}</p>
                <div>
                    <label for="link_email" class="mb-1.5 block text-helper font-semibold">{{ t('portal.email') }}</label>
                    <input id="link_email" name="email" type="email" required autocomplete="email"
                        value="{{ old('mode') === 'link' ? old('email') : '' }}"
                        class="h-12 w-full rounded-ds border border-black/15 px-3.5 text-body outline-none transition-colors focus:border-ink">
                </div>
                @if ($recaptchaSiteKey)
                    <input type="hidden" name="g-recaptcha-response" data-recaptcha-token>
                @endif
                <button class="ui-btn ui-btn-dark h-12 w-full text-body font-semibold" data-hover="true">
                    {{ t('portal.send_login_link') }}
                </button>
                <p class="text-helper leading-relaxed text-black/55">{{ t('portal.login_no_link') }}</p>
            </form>

            @if ($recaptchaSiteKey)
                <p class="mt-4 text-helper leading-relaxed text-black/45">Bu sayt Google reCAPTCHA ilə qorunur.</p>
            @endif
        </div>
    </main>

    <footer class="border-t border-black/8 py-6 text-center text-helper text-black/55">
        © {{ date('Y') }} Archi CRM
    </footer>

    <script>
        // Tab keçidi — JS-siz də hər iki forma işləyir (yalnız ikisi birdən görünərdi).
        (() => {
            const tabs = document.querySelectorAll('[data-login-tab]');
            const panels = document.querySelectorAll('[data-login-panel]');
            tabs.forEach((tab) => tab.addEventListener('click', () => {
                const mode = tab.dataset.loginTab;
                tabs.forEach((t) => t.setAttribute('aria-selected', t === tab ? 'true' : 'false'));
                panels.forEach((p) => { p.hidden = p.dataset.loginPanel !== mode; });
                panels.forEach((p) => { if (!p.hidden) p.querySelector('input[type=email]')?.focus(); });
            }));
        })();
    </script>

    @if ($recaptchaSiteKey)
        <script src="https://www.google.com/recaptcha/api.js?render={{ $recaptchaSiteKey }}"></script>
        <script>
            // v3: görünməzdir; göndərmə anında hər forma üçün təzə token alınır.
            (() => {
                const key = @json($recaptchaSiteKey);
                document.querySelectorAll('[data-recaptcha-form]').forEach((form) => {
                    let ready = false;
                    form.addEventListener('submit', (e) => {
                        if (ready) return;
                        e.preventDefault();
                        grecaptcha.ready(() => {
                            grecaptcha.execute(key, { action: 'portal_login' }).then((token) => {
                                form.querySelector('[data-recaptcha-token]').value = token;
                                ready = true;
                                form.submit();
                            });
                        });
                    });
                });
            })();
        </script>
    @endif
</body>
</html>
