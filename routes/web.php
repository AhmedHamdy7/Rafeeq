<?php

use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\LogoutController;
use App\Http\Controllers\LiveShareController;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\DriverApplications;
use App\Livewire\Admin\LiveTrips;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\Members;
use App\Livewire\Admin\SafetyCases;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\VerificationQueue;
use Illuminate\Support\Facades\Route;

// A view with no logic behind it, so `Route::view` says that in one line.
Route::view('/', 'welcome');

/*
|--------------------------------------------------------------------------
| Live trip share (Chapter 10)
|--------------------------------------------------------------------------
|
| 🔴 The only route in the application that serves real data with NO authentication. The token IS
| the credential, so the controller is written as if the URL were public — because for practical
| purposes it is: it gets pasted into WhatsApp, forwarded and screenshotted.
|
| 🔒 Short path on purpose (`/s/`), because the link is read aloud and retyped. And outside `/v1`
| on purpose: this is a web page for a person, not an endpoint for the app, and it must never
| appear in the API contract the mobile team builds against.
|
| Rate-limited by IP: the token is 32 random bytes, so guessing is not a practical attack, but an
| unthrottled public route is a free amplifier for anybody who wants one.
|
*/
Route::get('/s/{token}', [LiveShareController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('live-share.show');

/*
|--------------------------------------------------------------------------
| Admin dashboard — decision D4
|--------------------------------------------------------------------------
|
| Livewire on the `web` session, deliberately NOT a JSON API: D4 says "لغة واحدة،
| مفيش API layer للأدمن". `ScreenApiMapTest` enforces the other half of that — no
| admin route may appear under /v1.
|
| 🔒 Three middleware on every page behind sign-in, and each one closes a different
| door:
|
|   auth:admin     — is there an admin session at all
|   admin.mfa      — did THIS session pass the second factor (not just: is the
|                    account enrolled) — covers any future path that logs an admin in
|                    without going through AuthenticateAdminAction
|   admin.fresh    — has the screen been idle past the limit (Chapter 12 §Security)
|
| Permissions are NOT applied here. They are per-page and re-checked inside each
| Livewire action, because a Livewire action is a POST the browser can make directly
| and route middleware does not see it.
|
*/
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest:admin')->group(function (): void {
        Route::get('login', Login::class)->name('login');
    });

    Route::middleware(['auth:admin', 'admin.mfa', 'admin.fresh'])->group(function (): void {
        Route::get('/', HomeController::class)->name('home');
        Route::get('dashboard', Dashboard::class)->name('dashboard');
        Route::get('safety', SafetyCases::class)->name('safety');
        Route::get('trips', LiveTrips::class)->name('trips');
        Route::get('verifications', VerificationQueue::class)->name('verifications');
        Route::get('drivers', DriverApplications::class)->name('drivers');
        Route::get('members', Members::class)->name('members');
        Route::get('audit', AuditLog::class)->name('audit');
        Route::get('settings', Settings::class)->name('settings');

        /*
         * POST, and therefore CSRF-protected. A GET sign-out can be triggered by any
         * page that can make the browser load a URL, which is a nuisance here and a
         * real problem on the actions next door.
         *
         */
        Route::post('logout', LogoutController::class)->name('logout');
    });
});
