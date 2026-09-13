<?php

use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetTenant must run BEFORE SubstituteBindings: route-model binding resolves
        // records straight from the URL, and without the tenant scope in place it
        // happily hands a controller another studio's record. `append` alone would
        // place it after bindings, so the group is rebuilt with bindings last.
        $middleware->web(
            remove: [SubstituteBindings::class],
            append: [
                SetLocale::class,
                SetTenant::class,
                SubstituteBindings::class,
            ],
        );

        $middleware->redirectGuestsTo(fn ($request) => $request->is('portal*')
            ? route('portal.login')
            : route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // With APP_DEBUG=false a production failure left no trace at all: no
        // custom error page, no log line, nothing to diagnose from. Record who
        // hit what before the stock page is rendered.
        $exceptions->report(function (Throwable $e): void {
            $request = request();

            Log::error($e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_id' => auth('web')->id(),
                'client_user_id' => auth('customer')->id(),
                'tenant_id' => app(TenantContext::class)->id(),
            ]);
        });
    })->create();
