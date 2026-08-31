<?php

namespace App\Rules;

use App\Services\Security\TurnstileService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule wrapping server-side Turnstile verification, so login forms
 * can enforce the CAPTCHA declaratively.
 */
class Turnstile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(TurnstileService::class)->verify(is_string($value) ? $value : null, request()->ip())) {
            $fail(t('portal.captcha_failed'));
        }
    }
}
