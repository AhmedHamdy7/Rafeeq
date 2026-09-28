<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAdminMfaIsConfirmed;
use App\Http\Middleware\EnsureAdminSessionIsFresh;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\RequiresVerification;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Http\Responses\ApiExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
     * 🔒 Channel authorisation for the live trip, and the guard matters: `auth:sanctum`,
     * because the mobile app holds a bearer token and not a session cookie. Left on the
     * default web guard, every subscription attempt from the app would be rejected as a
     * guest — and the failure would look like a broken WebSocket rather than a wrong guard.
     *
     * Registered here rather than through `withRouting(channels: ...)`, which takes no
     * middleware: doing both would register the same channels twice.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            SetLocaleFromHeader::class,
        ]);

        /*
         * Where an unauthenticated visitor is sent — and, for everything that is not
         * an admin page, deliberately nowhere.
         *
         * Laravel's default points at a route named `login`, which this application
         * does not have: the dashboard's is `admin.login`. Without this, a signed-out
         * visitor opening any dashboard URL got a 500 from the URL generator instead of
         * the sign-in page — the very first thing such a visitor would hit.
         *
         * `null` for every other path is what keeps the API an API: returning null
         * makes `Authenticate` throw, which `ApiExceptionHandler` renders as the
         * standard 401 envelope. A redirect there would answer a missing token with
         * HTML.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin/*') ? route('admin.login') : null,
        );

        // Opt-in, never global: the routes a suspended or half-registered
        // person still needs (see their own status, sign out, revoke a
        // stolen device) must stay reachable, so these are applied per
        // route group in routes/api.php rather than to everything.
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'profile.complete' => EnsureProfileIsComplete::class,
            // `verified:government_id,selfie` — the gate half of the
            // pendingIntent pattern. Its refusal names the missing levels so
            // the app can send the person to verify and bring them back.
            'verified' => RequiresVerification::class,

            // The admin dashboard's two session guards (decision D4, Chapter 12
            // §Security). Applied per route group in routes/web.php.
            'admin.mfa' => EnsureAdminMfaIsConfirmed::class,
            'admin.fresh' => EnsureAdminSessionIsFresh::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionHandler::register($exceptions);
    })->create();
