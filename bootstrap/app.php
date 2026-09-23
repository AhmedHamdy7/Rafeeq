<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Http\Responses\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            SetLocaleFromHeader::class,
        ]);

        // Opt-in, never global: the routes a suspended or half-registered
        // person still needs (see their own status, sign out, revoke a
        // stolen device) must stay reachable, so these are applied per
        // route group in routes/api.php rather than to everything.
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'profile.complete' => EnsureProfileIsComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionHandler::register($exceptions);
    })->create();
