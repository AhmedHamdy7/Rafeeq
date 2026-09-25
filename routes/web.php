<?php

use App\Livewire\Admin\DriverApplications;
use App\Livewire\Admin\Login;
use App\Livewire\Admin\VerificationQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
        Route::get('verifications', VerificationQueue::class)->name('verifications');
        Route::get('drivers', DriverApplications::class)->name('drivers');

        /*
         * POST, and therefore CSRF-protected. A GET sign-out can be triggered by any
         * page that can make the browser load a URL, which is a nuisance here and a
         * real problem on the actions next door.
         */
        Route::post('logout', function (): RedirectResponse {
            Auth::guard('admin')->logout();

            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('admin.login');
        })->name('logout');
    });
});
