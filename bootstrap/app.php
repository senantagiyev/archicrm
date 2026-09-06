<?php

use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            // Runs on every web request (incl. livewire/update) so the tenant scope
            // stays active across Filament/Livewire AJAX, not just initial loads.
            SetTenant::class,
        ]);

        $middleware->redirectGuestsTo(fn ($request) => $request->is('portal*')
            ? route('portal.login')
            : route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
