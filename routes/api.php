<?php

use App\Http\Controllers\Api\V1\Account\ConsentController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /v1
|--------------------------------------------------------------------------
|
| Versioned per RAFEEQ_MASTER_PLAN.md §15.4: /v1 must never receive a
| breaking change.
|
| Three access tiers, and which one a route sits in is a security decision:
|
|   public      — no credential at all (OTP request/verify, session refresh)
|   signed-in   — a valid access token, but the account may be suspended and
|                 the profile may be half-finished. Only the routes someone
|                 in that state genuinely needs live here.
|   active      — a usable account: not suspended, profile complete. Every
|                 feature route from Phase 3 onward belongs in this group.
|
*/

Route::prefix('v1')->group(function (): void {

    Route::prefix('auth')->group(function (): void {
        Route::post('otp/request', [OtpController::class, 'request']);
        Route::post('otp/verify', [OtpController::class, 'verify']);

        // Unauthenticated: called exactly when the access token has expired.
        // The refresh token in the body is the credential.
        Route::post('session/refresh', [SessionController::class, 'refresh']);

        Route::middleware('auth:sanctum')->group(function (): void {
            // Reachable while suspended on purpose: this is how the app
            // learns it is suspended, and how the person signs out
            // (scenario H — never trap someone with no way out).
            Route::get('me', [SessionController::class, 'me']);
            Route::post('logout', [SessionController::class, 'logout']);
        });
    });

    Route::prefix('account')->middleware('auth:sanctum')->group(function (): void {
        // Device management stays reachable while suspended: revoking a
        // stolen phone (scenario G) must not depend on account standing.
        Route::get('devices', [DeviceController::class, 'index']);
        Route::delete('devices/{device}/session', [DeviceController::class, 'destroySession']);
        Route::patch('devices/current/security', [DeviceController::class, 'updateSecurity']);

        Route::get('consents', [ConsentController::class, 'index']);
        Route::post('consents', [ConsentController::class, 'store']);

        // Completing the profile is blocked while suspended — it is a
        // profile-sensitive action, which scenario H lists explicitly.
        Route::middleware('account.active')->group(function (): void {
            Route::put('profile/basic', [ProfileController::class, 'storeBasic']);
        });
    });
});
