@if (config('services.recaptcha.site_key') && config('services.recaptcha.secret_key'))
    <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
    <script>
        // Signal the login widget once grecaptcha is ready to execute.
        (() => {
            const poll = setInterval(() => {
                if (window.grecaptcha && window.grecaptcha.execute) {
                    clearInterval(poll);
                    document.dispatchEvent(new Event('recaptcha-ready'));
                }
            }, 200);
        })();
    </script>
@endif
