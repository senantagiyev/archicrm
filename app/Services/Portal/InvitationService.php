<?php

namespace App\Services\Portal;

use App\Exceptions\PortalInvitationException;
use App\Models\Client;
use App\Models\ClientUser;
use App\Notifications\PortalInvitation;
use App\Notifications\PortalLoginLink;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class InvitationService
{
    /**
     * Create (or reuse) a portal account for the client's contact and email
     * a 7-day magic invitation link. No self-registration exists.
     */
    public function invite(Client $client, string $name, string $email): ClientUser
    {
        // Scoped to THIS client: looking an account up by email alone re-pointed
        // an existing portal user at the new client, silently cutting off whoever
        // was using it — one contact person can work with two clients at once.
        $clientUser = ClientUser::withTrashed()
            ->where('client_id', $client->id)
            ->where('email', $email)
            ->first();

        if (! $clientUser) {
            $this->guardAgainstForeignAccount($email);

            $clientUser = new ClientUser(['client_id' => $client->id, 'email' => $email]);
        }

        // Revoking portal access soft-deletes the account. Re-inviting the same
        // address must be a deliberate restore, not a silent side effect.
        if ($clientUser->trashed()) {
            throw new PortalInvitationException(
                'Bu e-poçt üçün portal girişi ləğv edilib. Yenidən dəvət göndərmədən əvvəl hesabı bərpa edin.'
            );
        }

        $clientUser->fill([
            'client_id' => $client->id,
            'name' => $name,
            'invited_at' => now(),
        ])->save();

        $this->sendLink($clientUser, fn (string $url) => new PortalInvitation($url), days: 7);

        return $clientUser;
    }

    /**
     * `client_users.email` is globally unique, so an address already used by
     * another client (or another studio) would fail with a raw 1062. Refuse it
     * with something a person can act on instead.
     *
     * ÖLÜ hesab ünvanı girov saxlamamalıdır. Əvvəl yoxlama sadəcə
     * `withTrashed()->exists()` idi: portal hesabı silinən kimi onun e-poçtu
     * BİR DAHA istifadə edilə bilmirdi — müştəri də silinsə belə. Panel isə
     * silinmiş müştərinin portal hesabını bərpa etmək üçün heç bir yol
     * vermir, yəni istifadəçi çıxılmaz vəziyyətə düşürdü: «başqa ünvan
     * istifadə edin» yazılır, amma öz e-poçtunu geri qaytarmaq mümkün olmur.
     *
     * Qayda indi ünvanın DİRİ olub-olmamasına baxır:
     *  • hesab silinməyibsə — ünvan həqiqətən işlənir, rədd;
     *  • hesab silinib, amma müştərisi yerindədirsə — bərpa yolu var, rədd;
     *  • hesab da, müştərisi də silinibsə — əlaqə tamamilə bitib, ünvan azad
     *    edilir.
     *
     * Azad etmək üçün ölü sətir SİLİNMİR: `approvals.client_user_id` ona
     * istinad edir (kimin təsdiqlədiyi audit məlumatıdır), ona görə yalnız
     * e-poçt sahəsi unikal indeksdən çıxarılır. `.invalid` RFC 2606-ya görə
     * heç vaxt real domen olmayacaq, yəni o ünvana səhvən məktub getməz.
     */
    private function guardAgainstForeignAccount(string $email): void
    {
        $holders = ClientUser::withTrashed()
            ->withoutGlobalScopes()
            ->where('email', $email)
            ->get();

        if ($holders->isEmpty()) {
            return;
        }

        foreach ($holders as $holder) {
            if (! $holder->trashed()) {
                throw new PortalInvitationException(
                    'Bu e-poçt artıq başqa bir müştərinin portal hesabına bağlıdır. Başqa ünvan istifadə edin.'
                );
            }

            $client = Client::withTrashed()->withoutGlobalScopes()->find($holder->client_id);

            if ($client && ! $client->trashed()) {
                throw new PortalInvitationException(
                    'Bu e-poçt «'.$client->name.'» müştərisinin ləğv edilmiş portal hesabına bağlıdır. '
                    .'Ya həmin hesabı bərpa edin, ya da başqa ünvan istifadə edin.'
                );
            }
        }

        // Bura yalnız bütün daşıyıcılar ölü olduqda gəlinir.
        foreach ($holders as $holder) {
            $holder->forceFill(['email' => 'azad-edilib+'.$holder->getKey().'@portal.invalid'])->saveQuietly();
        }
    }

    /** Passwordless login: email a fresh 30-minute link. */
    public function sendLoginLink(ClientUser $clientUser): void
    {
        $this->sendLink($clientUser, fn (string $url) => new PortalLoginLink($url), minutes: 30);
    }

    /**
     * Issuing a link rotates the one-time token, which invalidates the previous
     * link. If the mail then fails (SMTP timeout, Gmail quota) the customer would
     * be left with neither the old link nor the new one and no way to recover, so
     * the previous token is put back before the failure propagates.
     */
    private function sendLink(ClientUser $clientUser, callable $notification, int $days = 0, int $minutes = 0): void
    {
        $previousToken = $clientUser->getRawOriginal('magic_token');

        $plain = Str::random(48);
        $clientUser->forceFill(['magic_token' => hash('sha256', $plain)])->save();

        $url = URL::temporarySignedRoute(
            'portal.magic-login',
            $days ? now()->addDays($days) : now()->addMinutes($minutes),
            ['clientUser' => $clientUser->id, 't' => $plain],
        );

        try {
            $clientUser->notify($notification($url));
        } catch (Throwable $e) {
            $clientUser->forceFill(['magic_token' => $previousToken])->save();

            throw $e;
        }
    }
}
