<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile server-side verification (reCAPTCHA-equivalent).
 * A CAPTCHA token is only trustworthy after the server validates it against
 * siteverify — client-side rendering alone proves nothing (TZ security ask).
 */
class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Whether the CAPTCHA is active (both keys configured). */
    public function enabled(): bool
    {
        return filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    /**
     * Verify a widget response token. Returns true when the CAPTCHA is disabled
     * (dev/tests), otherwise only when Cloudflare confirms the token.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable) {
            // Fail closed: a verification outage must not become a bypass.
            return false;
        }

        return $response->successful() && ($response->json('success') === true);
    }
}
