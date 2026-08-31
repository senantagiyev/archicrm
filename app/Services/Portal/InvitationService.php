<?php

namespace App\Services\Portal;

use App\Models\Client;
use App\Models\ClientUser;
use App\Notifications\PortalInvitation;
use App\Notifications\PortalLoginLink;
use Illuminate\Support\Facades\URL;

class InvitationService
{
    /**
     * Create (or reuse) a portal account for the client's contact and email
     * a 7-day magic invitation link. No self-registration exists.
     */
    public function invite(Client $client, string $name, string $email): ClientUser
    {
        $clientUser = ClientUser::withTrashed()->firstOrNew(['email' => $email]);

        if ($clientUser->trashed()) {
            $clientUser->restore();
        }

        $clientUser->fill([
            'client_id' => $client->id,
            'name' => $name,
            'invited_at' => now(),
        ])->save();

        $clientUser->notify(new PortalInvitation($this->signedLoginUrl($clientUser, days: 7)));

        return $clientUser;
    }

    /** Passwordless login: email a fresh 30-minute link. */
    public function sendLoginLink(ClientUser $clientUser): void
    {
        $clientUser->notify(new PortalLoginLink($this->signedLoginUrl($clientUser, minutes: 30)));
    }

    private function signedLoginUrl(ClientUser $clientUser, int $days = 0, int $minutes = 0): string
    {
        return URL::temporarySignedRoute(
            'portal.magic-login',
            $days ? now()->addDays($days) : now()->addMinutes($minutes),
            ['clientUser' => $clientUser->id],
        );
    }
}
