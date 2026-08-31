{{-- Google reCAPTCHA v3 (invisible) bound to Livewire state (data.captcha_token).
     A token expires in ~2 min, so it is refreshed on load and every 90s. --}}
<div
    wire:ignore
    x-data="{
        refresh() {
            if (! window.grecaptcha || ! window.grecaptcha.execute) return;
            window.grecaptcha.ready(() => {
                window.grecaptcha.execute(@js($siteKey), { action: 'staff_login' })
                    .then((token) => $wire.set('data.captcha_token', token, false));
            });
        },
    }"
    x-init="
        const start = () => { refresh(); setInterval(() => refresh(), 90000); };
        if (window.grecaptcha && window.grecaptcha.execute) start();
        else document.addEventListener('recaptcha-ready', start);
    "
>
    @error('data.captcha_token')
        <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror
    <p class="text-[11px] text-gray-400 dark:text-gray-500">Bu sayt Google reCAPTCHA ilə qorunur.</p>
</div>
