<?php

use App\Http\Controllers\Api\V1\Account\ConsentController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\VerificationController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Driver\DriverApplicationController;
use App\Http\Controllers\Api\V1\Driver\VehicleController;
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

        // The Verification Centre stays reachable while suspended: scenario H
        // allows an appeal, and seeing what was checked is part of that.
        Route::get('verifications', [VerificationController::class, 'index']);

        // Reached by a short-lived signed URL. `signed` bounds the lifetime,
        // `auth:sanctum` and the ownership check inside bound who it works for
        // — a signature alone only proves we issued the link.
        Route::get('verifications/documents/{document}', [VerificationController::class, 'showDocument'])
            ->middleware('signed')
            ->name('verification.documents.show');

        // Submitting new evidence is an account action, so it needs a usable
        // account: profile complete (the reviewer compares the name on the
        // document against it) and not suspended.
        Route::middleware(['account.active', 'profile.complete'])->group(function (): void {
            Route::post('verifications/organization', [VerificationController::class, 'verifyOrganization']);
            Route::post('verifications/{type}/documents', [VerificationController::class, 'storeDocument']);
            Route::post('verifications/{type}/submit', [VerificationController::class, 'submit']);
        });

        // Completing the profile is blocked while suspended — it is a
        // profile-sensitive action, which scenario H lists explicitly.
        Route::middleware('account.active')->group(function (): void {
            Route::put('profile/basic', [ProfileController::class, 'storeBasic']);
        });
    });

    /*
     * Becoming and being a driver (Chapter 3).
     *
     * `verified:government_id` is the first real consumer of the gate built in
     * Phase 3: Rafeeq does not let an unverified identity apply to carry
     * passengers. Its refusal names the missing level, so the app can save the
     * intent, send the person to verify, and bring them back here.
     */
    Route::prefix('driver')
        ->middleware(['auth:sanctum', 'account.active', 'profile.complete'])
        ->group(function (): void {
            // Outside the verification gate on purpose: this endpoint exists to
            // TELL someone what they are missing, so gating it would leave them
            // with a refusal and no explanation.
            Route::get('eligibility', [DriverApplicationController::class, 'eligibility']);

            Route::middleware('verified:government_id')->group(function (): void {
                Route::get('application', [DriverApplicationController::class, 'show']);
                Route::post('application', [DriverApplicationController::class, 'store']);
                Route::put('application/licence', [DriverApplicationController::class, 'updateLicence']);
                Route::post('application/submit', [DriverApplicationController::class, 'submit']);
                Route::delete('application', [DriverApplicationController::class, 'withdraw']);

                Route::get('vehicles', [VehicleController::class, 'index']);
                Route::post('vehicles', [VehicleController::class, 'store']);
                Route::patch('vehicles/{vehicle}', [VehicleController::class, 'update']);
                Route::post('vehicles/{vehicle}/activate', [VehicleController::class, 'activate']);
                Route::post('vehicles/{vehicle}/documents', [VehicleController::class, 'storeDocument']);
            });
        });
});
