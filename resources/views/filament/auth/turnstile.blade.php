{{-- Explicit-render Turnstile bound to Livewire state (data.captcha_token). --}}
<div
    wire:ignore
    x-data="{
        render() {
            if (! window.turnstile) return;
            window.turnstile.render($refs.widget, {
                sitekey: @js($siteKey),
                theme: 'light',
                callback: (token) => $wire.set('data.captcha_token', token, false),
                'expired-callback': () => $wire.set('data.captcha_token', '', false),
                'error-callback': () => $wire.set('data.captcha_token', '', false),
            });
        },
    }"
    x-init="
        if (window.turnstile) render();
        else document.addEventListener('turnstile-ready', () => render());
    "
>
    <div x-ref="widget"></div>
    @error('data.captcha_token')
        <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror
</div>
