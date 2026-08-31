<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Services\Portal\InvitationService;
use App\Services\Security\RecaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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
    public function sendLoginLink(Request $request, InvitationService $invitations, RecaptchaService $recaptcha)
    {
        $request->validate(['email' => ['required', 'email']]);

        // Verified unconditionally — a missing token must fail, not skip the check.
        if (! $recaptcha->verify($request->input('g-recaptcha-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'g-recaptcha-response' => t('portal.captcha_failed'),
            ]);
        }

        $clientUser = ClientUser::where('email', $request->input('email'))->first();

        if ($clientUser) {
            $invitations->sendLoginLink($clientUser);
        }

        // Same response either way — do not leak which emails exist.
        return back()->with('status', t('portal.login_link_sent'));
    }

    public function magicLogin(Request $request, ClientUser $clientUser)
    {
        // Single-use: the signed link carries a token that must match the stored
        // hash; it is cleared on use so the link cannot be replayed (audit MEDIUM-2).
        $token = (string) $request->query('t');

        abort_unless(
            filled($clientUser->magic_token) && hash_equals($clientUser->magic_token, hash('sha256', $token)),
            403,
        );

        Auth::guard('customer')->login($clientUser, remember: true);

        $clientUser->forceFill([
            'magic_token' => null,
            'last_login_at' => now(),
        ])->save();

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
