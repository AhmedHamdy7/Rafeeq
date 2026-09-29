<?php

use App\Http\Controllers\Api\V1\Account\ConsentController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\StatsController;
use App\Http\Controllers\Api\V1\Account\VerificationController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Booking\PickupPointRequestController;
use App\Http\Controllers\Api\V1\Booking\PickupPreviewController;
use App\Http\Controllers\Api\V1\Booking\SeatRequestController;
use App\Http\Controllers\Api\V1\Driver\CommuteController;
use App\Http\Controllers\Api\V1\Driver\DriverApplicationController;
use App\Http\Controllers\Api\V1\Driver\VehicleController;
use App\Http\Controllers\Api\V1\Group\GroupAbsenceController;
use App\Http\Controllers\Api\V1\Group\GroupAttendanceController;
use App\Http\Controllers\Api\V1\Group\GroupController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\Safety\SafetyController;
use App\Http\Controllers\Api\V1\Search\CommuteDemandController;
use App\Http\Controllers\Api\V1\Search\SavedSearchController;
use App\Http\Controllers\Api\V1\Search\SearchController;
use App\Http\Controllers\Api\V1\Trip\AttendanceController;
use App\Http\Controllers\Api\V1\Trip\TripController;
use App\Http\Controllers\Api\V1\Trip\TripLocationController;
use App\Http\Controllers\Api\V1\Trip\WaitTimerController;
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

        /*
         * The caller's own numbers, for the profile screen (ERD §23.1 gap #2 — the
         * passenger had nowhere for "12 trips · ⭐ 4.9 · 96% on-time" to come from).
         *
         * 🔒 Own stats only, and there is no route that returns anybody else's as a
         * block: what one member may know about another is the narrower summary embedded
         * in a match card or a member list, where there is a reason to see it.
         */
        Route::get('stats', [StatsController::class, 'show']);

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

            /*
             * "The fair suggested price" (ERD §23.3) — what the journey costs to drive,
             * split by the assumed occupancy. Advice beside the slider on screen 24, not
             * a rule: validation still accepts anything between the bounds.
             */
            Route::get('{commute}/price-suggestion', [CommuteController::class, 'priceSuggestion']);

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

        /*
         * The two screens the app opens on.
         *
         * Signed in and nothing more, deliberately — these are the screens that TELL a
         * person what is still missing (the verification banner on one, "publish a
         * route" on the other). Gating them behind a complete profile or a verified
         * identity would hide the instructions behind the requirement they explain.
         *
         * `driver/home` is outside the driver group below for the same reason: it is
         * reachable by anyone, because the role switch lives on it and a passenger who
         * taps "drive" needs an answer rather than a 403.
         */
        Route::get('home', [HomeController::class, 'passenger']);
        Route::get('driver/home', [HomeController::class, 'driver']);

        /*
         * The Safety Centre (Chapter 10).
         *
         * 🔴 In the SIGNED-IN tier on purpose, alongside "see that I am suspended" and "revoke a
         * stolen phone" — not behind `account.active`, not behind a verification gate. Somebody in
         * trouble at a roadside will not finish uploading a national ID first, and a 403 at that
         * moment is the worst answer this platform could give. The only thing that may fail on the
         * SOS path is the database.
         *
         * 🔒 Every route is scoped to the caller. Nothing here reads another person's contacts,
         * reports, or blocks — and the blocked list is one-directional: who I blocked, never who
         * blocked me, because the second would tell somebody they have been blocked.
         */
        Route::post('sos', [SafetyController::class, 'triggerSos']);
        Route::post('sos/{sos}/cancel', [SafetyController::class, 'cancelSos']);

        Route::get('safety/emergency-contacts', [SafetyController::class, 'contacts']);
        Route::post('safety/emergency-contacts', [SafetyController::class, 'addContact']);
        Route::patch('safety/emergency-contacts/{contact}', [SafetyController::class, 'updateContact']);
        Route::delete('safety/emergency-contacts/{contact}', [SafetyController::class, 'removeContact']);

        Route::get('safety/blocked-users', [SafetyController::class, 'blocked']);
        Route::post('safety/blocked-users', [SafetyController::class, 'block']);
        Route::delete('safety/blocked-users/{user}', [SafetyController::class, 'unblock']);

        /*
         * Reports. Rate-limited per Chapter 10 §Security, generously: the failure to avoid is
         * refusing a real report, not admitting a spurious one that a human reads and closes.
         */
        Route::get('incidents', [SafetyController::class, 'incidents']);
        Route::get('incidents/{incident}', [SafetyController::class, 'showIncident']);
        Route::post('incidents', [SafetyController::class, 'reportIncident'])
            ->middleware('throttle:safety-reports');
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
            Route::post('driver/seat-requests/{seatRequest}/waitlist', [SeatRequestController::class, 'waitlist']);
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
            /*
             * What a meeting point would cost, before anything is created. Screen 13 shows
             * the detour beside the pin while the passenger is still composing the
             * request — the figure is what tells them whether to propose that point at
             * all.
             */
            Route::post('commutes/{commute}/pickup-preview', [PickupPreviewController::class, 'show']);

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

            /*
             * The run itself, while it is happening (Chapter 8).
             *
             * 🔒 Reading and acting are scoped differently, and that split is the
             * chapter's security design: the DRIVER drives the run, so starting,
             * advancing and completing are hers alone — they are statements about what
             * the car is doing. A PASSENGER on the run may read it, because the point of
             * a live trip is that the person waiting can see where it got to. Everybody
             * else gets a 404, a cancelled booking included.
             */
            Route::get('trips/{trip}', [TripController::class, 'show']);
            Route::post('trips/{trip}/start', [TripController::class, 'start']);
            Route::post('trips/{trip}/status', [TripController::class, 'advance']);
            Route::post('trips/{trip}/complete', [TripController::class, 'complete']);

            /*
             * Who actually travelled (decision D18).
             *
             * 🔒 The two halves are deliberately asymmetric and neither side can do the
             * other's. The DRIVER records, because she is the only one who knows who got
             * in. The PASSENGER contests, and that is not a courtesy — it is what makes
             * one party deciding the other's bill acceptable at all (§15.6), which is why
             * the dispute route ships alongside the confirmation and not a phase later.
             */
            Route::get('trips/{trip}/attendance', [AttendanceController::class, 'index']);
            Route::post('trips/{trip}/check-in', [AttendanceController::class, 'checkIn']);
            Route::post('trips/{trip}/no-show', [AttendanceController::class, 'noShow']);

            Route::post('bookings/{booking}/dispute', [AttendanceController::class, 'dispute']);

            /*
             * Waiting at a gate for somebody who is not there yet (screen 41).
             *
             * 🔴 Nothing here ENDS a wait. "She's here" is a check-in and "Mark no-show &
             * depart" is a no-show — both above, and both close the timer as part of the
             * same call. A separate stop route would let the two records disagree, and the
             * disagreement always lands the same way: a timer left running on a passenger
             * who was marked present reads, months later, as somebody abandoned at a gate.
             */
            Route::get('trips/{trip}/wait-timers', [WaitTimerController::class, 'index']);
            Route::post('trips/{trip}/wait-timers', [WaitTimerController::class, 'store']);
            Route::post('trips/{trip}/wait-timers/{timer}/extend', [WaitTimerController::class, 'extend']);

            /*
             * Where the car is (Chapter 8, Live Location).
             *
             * 🔒 The driver REPORTS and a passenger on the run READS the current position.
             * Nobody reads the trail: `trip_locations` is a minute-by-minute record of where
             * a real person was, kept solely because a no-show dispute has no other evidence,
             * and no endpoint at any access level returns it.
             *
             * 🔴 A polling endpoint beside the WebSocket on purpose. The broadcast is the
             * fast path and this is the fallback — a phone on a bad connection at a bus stop
             * is exactly what a live map is for, and exactly where a socket fails to open.
             */
            Route::post('trips/{trip}/location', [TripLocationController::class, 'store']);
            Route::get('trips/{trip}/location', [TripLocationController::class, 'show']);
        });
    });
});
