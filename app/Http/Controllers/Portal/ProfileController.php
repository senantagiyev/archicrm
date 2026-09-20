<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only account screen — the left-nav «Profil» item.
 *
 * Deliberately has no password form: the portal is passwordless (magic link),
 * so a password field here would be a dead control that only invites support
 * tickets. Language is switched through the existing `locale.switch` route.
 */
class ProfileController extends Controller
{
    use ResolvesClientProjects;

    public function index(): View
    {
        $user = Auth::guard('customer')->user();

        // Same guard as every other portal screen: an archived client is out.
        $projectCount = $this->clientProjects()->count();

        return view('portal.hub.profile', [
            'user' => $user,
            'client' => $user->client,
            'projectCount' => $projectCount,
        ]);
    }
}
