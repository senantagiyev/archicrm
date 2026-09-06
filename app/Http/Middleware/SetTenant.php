<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Puts the authenticated user's tenant into TenantContext so the BelongsToTenant
 * global scope isolates the request. Runs after authentication (staff web or
 * portal customer guard). No tenant on the user → context stays unset (single-
 * tenant / legacy behaviour).
 */
class SetTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('web') ?? $request->user('customer');

        if ($user && $user->tenant_id) {
            app(TenantContext::class)->set($user->tenant_id);
        }

        return $next($request);
    }
}
