@if (config('services.recaptcha.site_key') && config('services.recaptcha.secret_key'))
    <script>
        window.onloadRecaptchaCallback = () => document.dispatchEvent(new Event('recaptcha-ready'));
    </script>
    <script src="https://www.google.com/recaptcha/api.js?onload=onloadRecaptchaCallback&render=explicit" async defer></script>
@endif
