<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Services\Portal\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('portal.home');
        }

        return view('portal.login');
    }

    /** Passwordless: email a fresh signed link if the account exists. */
    public function sendLoginLink(Request $request, InvitationService $invitations)
    {
        $request->validate(['email' => ['required', 'email']]);

        $clientUser = ClientUser::where('email', $request->input('email'))->first();

        if ($clientUser) {
            $invitations->sendLoginLink($clientUser);
        }

        // Same response either way — do not leak which emails exist.
        return back()->with('status', t('portal.login_link_sent'));
    }

    public function magicLogin(Request $request, ClientUser $clientUser)
    {
        Auth::guard('customer')->login($clientUser, remember: true);

        $clientUser->forceFill(['last_login_at' => now()])->save();

        $request->session()->regenerate();
        $request->session()->put('locale', $clientUser->locale);

        return redirect()->route('portal.home');
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
