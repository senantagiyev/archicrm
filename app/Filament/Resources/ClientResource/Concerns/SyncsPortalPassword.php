<?php

namespace App\Filament\Resources\ClientResource\Concerns;

use App\Exceptions\PortalInvitationException;
use App\Services\Portal\InvitationService;
use Filament\Notifications\Notification;

/**
 * Müştəri formasındakı «Portal şifrəsi» sahəsini portal hesabına çevirir.
 *
 * Sahə `Client`-in sütunu deyil (`dehydrated(false)`), ona görə yaratma/saxlama
 * hook-undan `$this->data` ilə oxunur. Boşdursa heç nə olmur — admin şifrəni
 * hər saxlamada yenidən yazmaq məcburiyyətində qalmasın.
 */
trait SyncsPortalPassword
{
    protected function syncPortalPassword(): void
    {
        $password = (string) ($this->data['portal_password'] ?? '');

        if ($password === '') {
            return;
        }

        $client = $this->getRecord();

        try {
            app(InvitationService::class)->setPassword($client, (string) $client->email, (string) $client->name, $password);

            Notification::make()
                ->success()
                ->title('Portal şifrəsi təyin edildi')
                ->body('Müştəri «'.$client->email.'» ünvanı və bu şifrə ilə portala girə bilər.')
                ->send();
        } catch (PortalInvitationException $e) {
            // Müştəri özü saxlanılıb; yalnız portal hesabı açılmadı — səbəbi
            // xəbərdarlıq kimi göstəririk, saxlamanı geri almırıq.
            Notification::make()
                ->warning()
                ->title('Portal şifrəsi təyin edilmədi')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }
}
