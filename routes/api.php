<?php

use App\Http\Controllers\Api\V1\Account\ConsentController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\VerificationController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Booking\PickupPointRequestController;
use App\Http\Controllers\Api\V1\Booking\SeatRequestController;
use App\Http\Controllers\Api\V1\Driver\CommuteController;
use App\Http\Controllers\Api\V1\Driver\DriverApplicationController;
use App\Http\Controllers\Api\V1\Driver\VehicleController;
use App\Http\Controllers\Api\V1\Group\GroupAbsenceController;
use App\Http\Controllers\Api\V1\Group\GroupAttendanceController;
use App\Http\Controllers\Api\V1\Group\GroupController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\Search\CommuteDemandController;
use App\Http\Controllers\Api\V1\Search\SavedSearchController;
use App\Http\Controllers\Api\V1\Search\SearchController;
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

    /*
     * Publishing and managing commutes (Chapter 4).
     *
     * Same gate as the rest of the driver surface, and for the same reason: an
     * unverified identity does not carry passengers. Eligibility to publish is
     * re-checked inside the Actions too, because a draft can sit for weeks while
     * a licence lapses or a vehicle is suspended.
     */
    Route::prefix('commutes')
        ->middleware(['auth:sanctum', 'account.active', 'profile.complete', 'verified:government_id'])
        ->group(function (): void {
            Route::get('/', [CommuteController::class, 'index']);
            Route::post('/', [CommuteController::class, 'store']);
            Route::get('{commute}', [CommuteController::class, 'show']);
            Route::patch('{commute}', [CommuteController::class, 'update']);
            Route::delete('{commute}', [CommuteController::class, 'destroy']);

            // PUT, not PATCH: each is replaced as a whole, because half a route
            // or half a schedule can describe a journey that makes no sense.
            Route::put('{commute}/route', [CommuteController::class, 'saveRoute']);
            Route::put('{commute}/schedule', [CommuteController::class, 'saveSchedule']);

            Route::post('{commute}/publish', [CommuteController::class, 'publish']);
            Route::post('{commute}/pause', [CommuteController::class, 'pause']);
            Route::post('{commute}/resume', [CommuteController::class, 'resume']);
        });

    /*
     * The shared place catalogue. Only needs a signed-in account: a passenger
     * searching for a meeting point has no reason to be verified first, and
     * gating it would make the map unusable before verification.
     */
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('places', [PlaceController::class, 'index']);
    });

    /*
     * Finding a commute (Chapter 5).
     *
     * A complete profile is required and verification is not: searching is how
     * someone decides whether Rafeeq is worth verifying for, and gating it would
     * make the product unevaluable. What a passenger is ELIGIBLE to see is decided
     * inside the query from their own account — a women-only commute excludes
     * non-women there, before anything is scored.
     *
     * 🔒 Every demand route is scoped to the authenticated owner. There is no
     * endpoint, at any access level, that returns one passenger's saved request to
     * anybody else — drivers included.
     */
    Route::middleware(['auth:sanctum', 'account.active', 'profile.complete'])->group(function (): void {
        Route::get('search/commutes', [SearchController::class, 'commutes']);

        Route::get('commute-demands', [CommuteDemandController::class, 'index']);
        Route::post('commute-demands', [CommuteDemandController::class, 'store']);
        Route::delete('commute-demands/{demand}', [CommuteDemandController::class, 'destroy']);

        Route::get('matches', [CommuteDemandController::class, 'matches']);

        Route::get('saved-searches', [SavedSearchController::class, 'index']);
        Route::post('saved-searches', [SavedSearchController::class, 'store']);
        Route::delete('saved-searches/{search}', [SavedSearchController::class, 'destroy']);

        /*
         * Seat requests and bookings (Chapter 6).
         *
         * A verified identity IS required here, unlike searching: looking at
         * commutes is how someone decides whether Rafeeq is worth verifying for,
         * but getting into a stranger's car is not. The driver's own
         * `min_trust_level` can require more on top.
         */
        Route::middleware('verified:government_id')->group(function (): void {
            Route::post('commutes/{commute}/seat-requests', [SeatRequestController::class, 'store']);
            Route::get('seat-requests', [SeatRequestController::class, 'index']);
            Route::delete('seat-requests/{seatRequest}', [SeatRequestController::class, 'withdraw']);

            Route::get('my-bookings', [BookingController::class, 'mine']);
            Route::get('bookings/{booking}', [BookingController::class, 'show']);
            Route::patch('bookings/{booking}/cancel', [BookingController::class, 'cancel']);

            // The driver's side of the same conversation.
            Route::get('driver/seat-requests', [SeatRequestController::class, 'inbox']);
            Route::post('driver/seat-requests/{seatRequest}/approve', [SeatRequestController::class, 'approve']);
            Route::post('driver/seat-requests/{seatRequest}/reject', [SeatRequestController::class, 'reject']);
            Route::get('driver/bookings', [BookingController::class, 'forDriver']);

            /*
             * Custom meeting points. Two ways in, because the schema has two: a
             * passenger still joining proposes against their seat request, and one
             * already riding proposes against their membership (ERD §23.1 added
             * `group_member_id` for exactly that screen).
             *
             * 🔴 The detour is measured by us from the driver's published route, never
             * accepted from the request, and checked against the driver's own
             * `max_detour_minutes` before the proposal is even recorded.
             */
            Route::post('seat-requests/{seatRequest}/pickup-request', [PickupPointRequestController::class, 'storeForSeatRequest']);
            Route::post('groups/{group}/pickup-request', [PickupPointRequestController::class, 'storeForMember']);
            Route::get('pickup-requests', [PickupPointRequestController::class, 'mine']);
            Route::post('pickup-requests/{pickupRequest}/accept-alternative', [PickupPointRequestController::class, 'acceptAlternative']);

            Route::get('driver/pickup-requests', [PickupPointRequestController::class, 'inbox']);
            Route::post('driver/pickup-requests/{pickupRequest}/approve', [PickupPointRequestController::class, 'approve']);
            Route::post('driver/pickup-requests/{pickupRequest}/suggest-alternative', [PickupPointRequestController::class, 'suggestAlternative']);
            Route::post('driver/pickup-requests/{pickupRequest}/reject', [PickupPointRequestController::class, 'reject']);

            /*
             * The group itself (Master Plan §889).
             *
             * 🔒 Every one of these is reachable only by a member of the group in
             * question, and a miss is a 404 rather than a 403. A member list is a set
             * of real first names, trust levels and the days each person reliably
             * travels — which is also when they are not at home. There is no access
             * level at which somebody outside the group can read it.
             */
            Route::get('groups', [GroupController::class, 'index']);
            Route::get('groups/{group}', [GroupController::class, 'show']);
            Route::get('groups/{group}/members', [GroupController::class, 'members']);
            Route::post('groups/{group}/leave', [GroupController::class, 'leave']);

            Route::get('groups/{group}/attendance', [GroupAttendanceController::class, 'index']);
            Route::post('groups/{group}/attendance', [GroupAttendanceController::class, 'store']);

            Route::get('groups/{group}/absences', [GroupAbsenceController::class, 'index']);
            Route::post('groups/{group}/absences', [GroupAbsenceController::class, 'store']);
            Route::delete('groups/{group}/absences/{absence}', [GroupAbsenceController::class, 'destroy']);
        });
    });
});
