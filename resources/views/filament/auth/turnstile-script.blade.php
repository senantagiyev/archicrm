@if (config('services.turnstile.site_key') && config('services.turnstile.secret_key'))
    <script>
        window.onloadTurnstileCallback = () => document.dispatchEvent(new Event('turnstile-ready'));
    </script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onloadTurnstileCallback&render=explicit" async defer></script>
@endif
