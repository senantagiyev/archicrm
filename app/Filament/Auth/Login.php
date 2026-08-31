<?php

namespace App\Filament\Auth;

use App\Services\Security\TurnstileService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\View as ViewComponent;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Staff login with a server-verified Cloudflare Turnstile CAPTCHA. The token is
 * bound to Livewire state via an explicit-render widget, then verified in
 * authenticate() before any credential check runs.
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

        if (app(TurnstileService::class)->enabled()) {
            $components[] = $this->getCaptchaFormComponent();
        }

        return $schema->components($components);
    }

    protected function getCaptchaFormComponent(): Component
    {
        return ViewComponent::make('filament.auth.turnstile')
            ->viewData(['siteKey' => config('services.turnstile.site_key')]);
    }

    public function authenticate(): ?LoginResponse
    {
        $turnstile = app(TurnstileService::class);

        if ($turnstile->enabled()) {
            $token = $this->data['captcha_token'] ?? null;

            if (! $turnstile->verify(is_string($token) ? $token : null, request()->ip())) {
                throw ValidationException::withMessages([
                    'data.captcha_token' => t('portal.captcha_failed'),
                ]);
            }
        }

        return parent::authenticate();
    }
}
