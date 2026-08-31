<?php

namespace App\Rules;

use App\Services\Security\RecaptchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rule wrapping server-side reCAPTCHA verification, so login forms
 * can enforce the CAPTCHA declaratively.
 */
class Recaptcha implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(RecaptchaService::class)->verify(is_string($value) ? $value : null, request()->ip())) {
            $fail(t('portal.captcha_failed'));
        }
    }
}
