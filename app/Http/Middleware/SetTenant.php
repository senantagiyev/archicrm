<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the authenticated user's tenant into TenantContext so the BelongsToTenant
 * global scope isolates the request.
 *
 * Fails closed: an authenticated account with no tenant would otherwise leave the
 * scope inert for the whole request — reads AND writes — making that session a
 * silent cross-tenant super-user. The only legitimate tenant-less account is the
 * platform admin, who manages the studio registry itself.
 */
class SetTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web') ?? $request->user('customer');

        if (! $user) {
            return $next($request);
        }

        if ($user->tenant_id) {
            // A switched-off studio has to be switched off on BOTH front doors.
            // `Tenant::active` was checked only in User::canAccessPanel(), so
            // staff were locked out while the studio's clients kept full portal
            // access — magic-link login, chat, brief, documents, payments.
            $tenant = Tenant::query()->whereKey($user->tenant_id)->first();

            abort_if($tenant !== null && ! $tenant->active, 403, 'Bu studiya müvəqqəti olaraq deaktiv edilib.');

            app(TenantContext::class)->set($user->tenant_id);

            return $next($request);
        }

        // A tenant-less account is only safe while there is nothing to isolate.
        // Every install already has one studio (the tenancy migration creates a
        // default), so the threshold is TWO: from the second studio on, such an
        // account would be a silent cross-studio super-user and is refused.
        // Only reached for a tenant-less account, so the extra query is rare.
        if (Tenant::query()->count() < 2) {
            return $next($request);
        }

        abort_unless($user instanceof User && $user->is_platform_admin, 403, 'Hesab heç bir studiyaya bağlı deyil.');

        return $next($request);
    }
}
