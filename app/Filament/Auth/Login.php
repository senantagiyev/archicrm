<?php

namespace App\Filament\Auth;

use App\Services\Security\RecaptchaService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Staff login with a server-verified Google reCAPTCHA. The token is bound to
 * Livewire state via an explicit-render widget, then verified in authenticate()
 * before any credential check runs.
 */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        $components = [
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ];

        if (app(RecaptchaService::class)->enabled()) {
            $components[] = $this->getCaptchaFormComponent();
        }

        return $schema->components($components);
    }

    protected function getCaptchaFormComponent(): Component
    {
        return ViewComponent::make('filament.auth.recaptcha')
            ->viewData(['siteKey' => config('services.recaptcha.site_key')]);
    }

    public function authenticate(): ?LoginResponse
    {
        $recaptcha = app(RecaptchaService::class);

        if ($recaptcha->enabled()) {
            $token = $this->data['captcha_token'] ?? null;

            if (! $recaptcha->verify(is_string($token) ? $token : null, request()->ip())) {
                throw ValidationException::withMessages([
                    'data.captcha_token' => t('portal.captcha_failed'),
                ]);
            }
        }

        return parent::authenticate();
    }
}
