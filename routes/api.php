<?php

use App\Http\Controllers\Api\V1\Account\AvatarController;
use App\Http\Controllers\Api\V1\Account\ComfortPreferenceController;
use App\Http\Controllers\Api\V1\Account\ConsentController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\OnboardingController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\PublicProfileController;
use App\Http\Controllers\Api\V1\Account\StatsController;
use App\Http\Controllers\Api\V1\Account\VerificationController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Booking\PickupPointRequestController;
use App\Http\Controllers\Api\V1\Booking\PickupPreviewController;
use App\Http\Controllers\Api\V1\Booking\SeatOfferController;
use App\Http\Controllers\Api\V1\Booking\SeatRequestController;
use App\Http\Controllers\Api\V1\Booking\WaitlistController;
use App\Http\Controllers\Api\V1\Driver\CommuteController;
use App\Http\Controllers\Api\V1\Driver\DriverApplicationController;
use App\Http\Controllers\Api\V1\Driver\VehicleController;
use App\Http\Controllers\Api\V1\Group\GroupAbsenceController;
use App\Http\Controllers\Api\V1\Group\GroupAttendanceController;
use App\Http\Controllers\Api\V1\Group\GroupController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\Notification\ChatController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Payment\MoneyController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\Rating\RatingController;
use App\Http\Controllers\Api\V1\Safety\SafetyController;
use App\Http\Controllers\Api\V1\Search\CommuteDemandController;
use App\Http\Controllers\Api\V1\Search\SavedSearchController;
use App\Http\Controllers\Api\V1\Search\SearchController;
use App\Http\Controllers\Api\V1\Trip\AttendanceController;
use App\Http\Controllers\Api\V1\Trip\NavigationRouteController;
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

        /*
         * Where an interrupted sign-up resumes (Feature 01 v3 §5).
         *
         * 🔴 Behind `auth:sanctum` and NOTHING else, which is the whole point. Its job is to
         * answer "how far did this account get", so gating it on a complete profile would make it
         * unavailable in exactly the state it exists to describe. It stays reachable while
         * suspended too — it is the call that returns `RESTRICTED_ACCOUNT`, and a client that
         * could not ask would have no way to learn why it is stuck.
         */
        Route::get('onboarding-state', [OnboardingController::class, 'state']);

        /*
         * Preferences likewise: the intro slides are seen BEFORE the profile exists, so recording
         * that they were seen cannot require one. Nothing here grants anything, so there is no
         * reason to hold it behind account standing either.
         */
        Route::patch('preferences', [OnboardingController::class, 'updatePreferences']);

        /*
         * What the inside of the car should be like, per mode (Feature 02 v3).
         *
         * 🔒 Stored and returned, and nothing else — nothing in search or matching reads these.
         * v3: "Avoid claiming these are strict filters until matching rules are defined."
         */
        Route::get('comfort-preferences', [ComfortPreferenceController::class, 'index']);
        Route::patch('comfort-preferences', [ComfortPreferenceController::class, 'update']);

        // Completing the profile is blocked while suspended — it is a
        // profile-sensitive action, which scenario H lists explicitly.
        Route::middleware('account.active')->group(function (): void {
            Route::put('profile/basic', [ProfileController::class, 'storeBasic']);

            /*
             * The role step comes immediately after the profile, so a complete profile is
             * required: it enforces the documented order rather than trusting the app to follow
             * it, and F01 §5 is explicit that somebody who skipped ahead is sent back to the
             * profile first.
             */
            Route::post('initial-role', [OnboardingController::class, 'storeInitialRole'])
                ->middleware('profile.complete');
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

            /*
             * The five-item checklist (Feature 02 v3). Outside the gate for the same reason as
             * `eligibility`: it is the screen that explains what is missing, so requiring the
             * thing it explains would make it unreachable in the one state it exists for.
             */
            Route::get('verification', [DriverApplicationController::class, 'verification']);

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

        /*
         * "Share Live Trip" — a temporary tokenised link a trusted contact opens.
         *
         * 🔒 The public page itself is NOT here: it is `GET /s/{token}` in routes/web.php, because
         * it is a page for a person and the token is its whole credential. These three are the
         * authenticated side — make a link, see whether anybody opened it, stop it.
         */
        Route::post('trips/{trip}/live-share', [SafetyController::class, 'shareLiveTrip']);
        Route::get('safety/live-shares', [SafetyController::class, 'liveShares']);
        Route::delete('safety/live-shares/{share}', [SafetyController::class, 'revokeLiveShare']);

        Route::get('safety/blocked-users', [SafetyController::class, 'blocked']);
        Route::post('safety/blocked-users', [SafetyController::class, 'block']);
        Route::delete('safety/blocked-users/{user}', [SafetyController::class, 'unblock']);

        /*
         * Ratings (Chapter 9, screen 37).
         *
         * 🔴 Double-blind, and enforced in the data rather than in the screen (pitfall #26). Every
         * route here is about the caller's OWN rating — the one thing they may read before the
         * reveal. There is deliberately no route that returns somebody else's: that needs the
         * `visible()` filter, and mixing the two in one controller is how the filter gets
         * forgotten on the convenient path.
         *
         * In the signed-in tier rather than behind `account.active`: a suspended account still has
         * journeys it travelled on, and the other person is owed the rating as much as before.
         */
        /*
         * Notifications (Phase 12, Chapter 11). Signed-in tier, not active: a member whose
         * account was just put on hold must still be able to read the notice saying so.
         */
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/read', [NotificationController::class, 'markRead']);
        Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
        Route::patch('notifications/preferences', [NotificationController::class, 'updatePreferences']);

        Route::get('ratings/pending', [RatingController::class, 'pending']);
        Route::get('ratings/mine', [RatingController::class, 'mine']);
        Route::post('bookings/{booking}/rating', [RatingController::class, 'store']);
        Route::patch('ratings/{rating}', [RatingController::class, 'update']);

        /*
         * Reviews, as a stranger sees them — and as their subject does.
         *
         * 🔒 Keyed on the COMMUTE rather than on a user id, because no payload in this API returns
         * a user identifier and a `/users/{id}/reviews` route would have forced us to start. The
         * client already holds the commute id from search.
         *
         * 🔒 Both listings are anonymous and carry a month rather than a date — a commute seats
         * one to three people, so an exact date names the reviewer even when the payload does not,
         * and the driver already knows where the passenger lives. See `PublicReviewResource`.
         */
        Route::get('commutes/{commute}/reviews', [RatingController::class, 'commuteReviews']);
        Route::get('ratings/about-me', [RatingController::class, 'aboutMe']);
        Route::post('ratings/{rating}/report', [RatingController::class, 'report']);

        /*
         * Reports. Rate-limited per Chapter 10 §Security, generously: the failure to avoid is
         * refusing a real report, not admitting a spurious one that a human reads and closes.
         */
        Route::get('incidents', [SafetyController::class, 'incidents']);
        Route::get('incidents/{incident}', [SafetyController::class, 'showIncident']);
        Route::post('incidents', [SafetyController::class, 'reportIncident'])
            ->middleware('throttle:safety-reports');

        /*
         * Evidence on a report.
         *
         * 🔒 Metadata out, bytes in. There is no route at any access level on this API that returns
         * a file, a URL or a stored path — the reviewer reads evidence through the dashboard, which
         * authenticates against a separate user table. Pitfall #23.
         *
         * The upload shares the reports' rate limiter rather than running unthrottled: an
         * authenticated image-processing endpoint is the most expensive thing a signed-in account
         * can reach, and the cap per report bounds storage but not the work of being refused.
         */
        Route::get('incidents/{incident}/evidence', [SafetyController::class, 'evidence']);
        Route::post('incidents/{incident}/evidence', [SafetyController::class, 'attachEvidence'])
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
        /*
         * A member's profile as a stranger sees it (Feature 02 v3).
         *
         * 🔒 Here rather than in the public tier despite being called a "public profile": it is the
         * one payload reachable for an ARBITRARY user id, so left unauthenticated it is a scraper's
         * index of the platform's drivers. Deliberately NOT behind the verified tier either — a
         * passenger needs no verification to check out the driver she is about to ride with, and
         * that is the thing we most want her to do.
         */
        Route::get('users/{user}/public-profile', [PublicProfileController::class, 'show']);

        // The image behind `avatarPath`. Inline-renderable because it is re-encoded on approval
        // rather than being the uploaded document — see the controller.
        Route::get('users/{user}/avatar', [AvatarController::class, 'show']);

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
         * 🔴 **Passenger verification was REMOVED here** (Feature 02 v3: "No passenger
         * verification. Passenger can book without ID verification", confirmed as a product
         * decision). These routes used to sit behind `verified:government_id`.
         *
         * The comment that stood here argued the opposite — "looking at commutes is how someone
         * decides whether Rafeeq is worth verifying for, but getting into a stranger's car is
         * not" — and it is worth saying plainly what replaced that reasoning rather than deleting
         * it quietly, because it was not a bad argument. What changed is WHO is relied on to make
         * the judgement:
         *
         *   - the DRIVER approves every single seat request by hand, and she sees the passenger's
         *     trust level and verified levels before she does. That approval is now the check.
         *   - a driver who wants more can still set her own `min_trust_level`, which is a HARD
         *     filter, so "verified passengers only" remains available to anybody who wants it —
         *     as her choice rather than the platform's blanket rule.
         *
         * 🔒 What did NOT get cheaper: the DRIVER side. Publishing and starting a commute still
         * require `verified:government_id` plus an admin-approved driver profile and a verified
         * vehicle — see the `driver` and `commutes` groups above. The asymmetry is deliberate: the
         * person carrying strangers is checked, the person riding is vouched for by the driver who
         * agreed to carry her.
         *
         * The driver-side routes in this block (`driver/seat-requests`, `driver/bookings`,
         * `driver/balance`, `driver/pickup-requests`) lost the middleware too, and keep working
         * exactly as before: each one is scoped to a commute the caller owns, and owning a
         * published commute already requires an approved driver profile, which already requires
         * this verification. The gate was redundant there and only ever bit passengers.
         */
        Route::post('commutes/{commute}/seat-requests', [SeatRequestController::class, 'store']);
        Route::get('seat-requests', [SeatRequestController::class, 'index']);

        /*
         * Seats offered to her while she waits (Feature 03 v3).
         *
         * 🔒 Both are scoped to her own waiting requests, and a miss is a 404 rather than a 403 —
         * a 403 would confirm the id exists, which turns this into an oracle for who is waiting on
         * which commute.
         */
        Route::get('seat-offers', [SeatOfferController::class, 'index']);
        Route::post('seat-offers/{offer}/accept', [SeatOfferController::class, 'accept']);
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
         * Her waiting list, and the one place a driver hands out seats herself (Feature 03 v5).
         *
         * 🔴 This is v5's named exception to the automatic fan-out. Everywhere else a seat that
         * frees goes out to everybody waiting and the first acceptance wins; resuming a paused
         * commute means refilling a whole car for weeks of mornings, and v5 lets her choose — and
         * favour the passengers she had before. She still only decides WHO may race.
         */
        Route::get('driver/commutes/{commute}/waitlist', [WaitlistController::class, 'index']);
        Route::post('driver/commutes/{commute}/waitlist/offers', [WaitlistController::class, 'offer']);
        Route::delete('driver/seat-offers/{offer}', [WaitlistController::class, 'withdrawOffer']);

        /*
         * Her map (Feature 03 v5 §3): the ordered stops she actually drives, and what a pending
         * request would add to them.
         *
         * 🔒 Driver-only, and the ownership check is not a formality — the payload carries every
         * confirmed passenger's pickup and drop-off, which is a list of front doors and workplaces.
         * v5 §4 forbids showing those to unrelated passengers.
         */
        Route::get('driver/commute-occurrences/{trip}/navigation-route', [NavigationRouteController::class, 'show']);
        Route::get('driver/seat-requests/{seatRequest}/route-preview', [NavigationRouteController::class, 'preview']);

        /*
         * The money, as the two people in the car see it (Phase 8, Bible §8).
         *
         * 🔴 Nothing here MOVES money. No payment method, no capture, no payout, no refund —
         * those need a provider that has not been chosen, and a half-built capture path is
         * worse than none. These two read what the cash path has already recorded.
         *
         * 🔒 `driver/balance` is hers alone: there is no endpoint at any access level that
         * reads another driver's income or debt. The group statement shows a passenger only her
         * own rows — what her fellow passengers pay is not her business, and they may be on
         * different prices after a change.
         */
        Route::get('driver/balance', [MoneyController::class, 'driverBalance']);
        Route::get('groups/{group}/statement', [MoneyController::class, 'groupStatement']);

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
        // "Cancel today" — one day off, before it starts; the commute stands (screen 23).
        Route::post('trips/{trip}/cancel', [TripController::class, 'cancel']);

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
        /*
         * Trip chat (Phase 12, Chapter 11). In the active + verified tier with the bookings
         * themselves: a member on hold keeps their inbox but not new messages.
         */
        Route::get('bookings/{booking}/messages', [ChatController::class, 'index']);
        Route::post('bookings/{booking}/messages', [ChatController::class, 'store'])
            ->middleware('throttle:chat-messages');
        Route::post('messages/{message}/report', [ChatController::class, 'report'])
            ->middleware('throttle:safety-reports');

        Route::post('trips/{trip}/location', [TripLocationController::class, 'store']);
        Route::get('trips/{trip}/location', [TripLocationController::class, 'show']);
    });
});
