<?php

/*
|--------------------------------------------------------------------------
| Rafeeq domain configuration
|--------------------------------------------------------------------------
|
| These are the SHIPPED DEFAULTS only. Almost everything here is overridable
| at runtime through `platform_settings` (binding standard #11: no magic
| number lives in code), read via the per-domain settings accessors —
| `Identity\Support\AuthSettings`, `Identity\Support\ProfileSettings`,
| `Identity\Support\ConsentRegistry`. Never read this file directly from an
| Action, a Request or a Resource: go through the accessor, so the admin
| dashboard can retune the platform without a deploy.
|
| The one exception is marked DEPLOY-ONLY below. Infrastructure wiring must
| not be reachable from a dashboard: a mistyped transport there would take
| authentication down for everyone, and unlike a policy number it cannot be
| validated by looking at it.
|
| Where a value bounds a database column, the column width is the real
| ceiling — raising the setting past it turns a validation error into a
| truncated write. Those ceilings are noted inline.
|
*/

return [

    /*
     * The legal documents in force right now. Bumping a version here is what
     * makes every account's consent stale, so the app can require a
     * re-acceptance — which is the entire point of storing a version
     * alongside each consent rather than a bare boolean.
     */
    'legal' => [
        'terms_version' => env('RAFEEQ_TERMS_VERSION', '1.0'),
        'privacy_version' => env('RAFEEQ_PRIVACY_VERSION', '1.0'),
    ],

    'verification' => [
        /*
         * DEPLOY-ONLY. Which filesystem disk holds identity documents.
         *
         * Decision D7: a private S3-compatible bucket, never a public URL.
         * The default `documents` disk is private local storage for
         * development; production sets this to `s3`. It is not runtime-tunable
         * because moving the disk out from under existing rows would orphan
         * every document already stored.
         */
        'documents_disk' => env('RAFEEQ_DOCUMENTS_DISK', 'documents'),

        /*
         * DEPLOY-ONLY. Whether a document link points straight at storage
         * (a presigned URL, decision D7) or at our own signed route.
         *
         * An explicit flag rather than asking the disk what it supports: that
         * capability is not stable. `Storage::fake()` makes EVERY disk claim it
         * can presign, so a capability check silently takes a different branch
         * under test than in production — which is the one place the difference
         * must not exist, because the two branches have different security
         * properties. Turn this on wherever `documents_disk` is S3.
         */
        'presigned_document_urls' => env('RAFEEQ_PRESIGNED_DOCUMENT_URLS', false),

        /*
         * DEPLOY-ONLY. Which scanner inspects an upload before it can be
         * submitted for review. `signature` is the development scanner and
         * refuses to run in production, so shipping without a real engine
         * fails loudly instead of waving malware through.
         */
        'virus_scanner' => env('RAFEEQ_VIRUS_SCANNER', 'signature'),

        // How long a document access link stays valid. Short because the link
        // is the only thing standing between a leaked URL and someone's
        // national ID.
        'document_url_ttl_seconds' => 120,

        'max_document_size_kilobytes' => 8192,

        // Attempts before a verification type is locked and needs an admin to
        // reopen it — an upload loop is the cheapest way to probe what a
        // reviewer accepts.
        'max_submission_attempts' => 5,

        /*
         * What the Verification Centre tells somebody to expect while they wait —
         * the screen's "Pending review · usually under 2 hours".
         *
         * ⚠️ A TARGET, not a guarantee, and nothing enforces it: no alert fires when the
         * queue runs past it. It is here rather than hard-coded because operations will
         * want to change it without a deploy the first busy week (standard #11), and a
         * number nobody can adjust becomes a lie instead of an estimate.
         *
         * Deriving it from how long the queue has actually been taking would be better,
         * and needs review timings the platform has not collected yet.
         */
        'expected_review_minutes' => 120,
    ],

    'booking' => [
        /*
         * The platform's share of each seat, as a percentage.
         *
         * 3.0 is the figure the Engineering Bible uses throughout (§2014:
         * `payment.platform_fee_pct = 3.0`). It is ALSO open question #5 in
         * MASTER_PLAN §19 — "is 3% confirmed, and is it flat or tiered?" —
         * deferred to Phase 8.
         *
         * Building on the documented number is safe because every booking
         * freezes its own snapshot at approval: changing this later moves
         * nothing that already exists (pitfall #42).
         */
        'platform_fee_percent' => 3.0,

        /*
         * How long a seat request waits for an answer before expiring. A request
         * nobody answered is worse than a refusal: the passenger cannot look
         * elsewhere while it holds their one active request per commute.
         */
        'request_expiry_hours' => 48,

        // How many people may wait for a seat on one commute. Beyond this the
        // queue is long enough that joining it is a false hope.
        'max_waitlist_size' => 10,

        /*
         * Cancellation fees are NOT implemented: the policy is open question #7
         * in MASTER_PLAN §19, deferred to Phase 8, and the chapter itself is
         * explicit that Chapter 7 is "very general" on it.
         *
         * Until it exists, cancelling releases the seat and charges nothing. A
         * guessed fee would be money taken from a real person on the strength of
         * an assumption, which is the one kind of guess that must not ship.
         */
        'cancellation_fee_piastres' => 0,

        /*
         * How far ahead a recurring membership is seated.
         *
         * Bound to the same horizon as trip generation on purpose: bookings can
         * only be made for days that exist, and generation only creates 30 days
         * at a time. A larger number here would silently seat fewer days than it
         * promised; a smaller one would leave a committed member unseated on days
         * that were already generated.
         */
        'recurring_horizon_days' => 30,
    ],

    'api' => [
        /*
         * Paging for every list that grows with use.
         *
         * A default is not an optimisation, it is a correctness rule: an unbounded list
         * endpoint answers a driver's second year with every booking they have ever had,
         * in one response, and the first time that happens is in production on a phone.
         *
         * The ceiling matters as much as the default — `perPage` comes from the client,
         * so without one a caller can ask for a million rows and the limit is only
         * advisory.
         */
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    'admin' => [
        /*
         * How many 30-second TOTP steps either side of now are accepted.
         *
         * 1 means a code stays valid for about 90 seconds, which covers a phone clock
         * drifting and somebody typing slowly. Larger values widen the window in which
         * a captured code is still usable — and the replay guard
         * (`admin_users.mfa_last_used_timestamp`) only stops the SAME code being reused,
         * not a different code from a wider window.
         */
        'mfa_window' => 1,

        /*
         * Chapter 12 §Security: "session timeout". Much shorter than a member's
         * session, because an unattended admin browser is a far worse thing to leave
         * open than an unattended passenger app — it can approve drivers and read
         * identity documents.
         */
        'session_idle_minutes' => 30,
    ],

    'group' => [
        /*
         * How much warning a member owes the group before leaving.
         *
         * The point is not to trap anyone — it is that a driver who planned their
         * month around four passengers should not discover on Sunday night that
         * one of them is gone. Each group may set its own; this is the default a
         * new group starts with, and it matches `commute_groups.notice_period_days`.
         */
        'default_notice_period_days' => 7,

        /*
         * How long before departure a member may still change "I'm coming" to
         * "I'm away". After this the driver is already planning around the answer
         * they were given, which is the whole reason the declaration exists.
         */
        'attendance_cutoff_hours' => 2,

        /*
         * The longest planned absence. Beyond this it is not an absence, it is
         * leaving — and calling it an absence would keep a seat nominally held by
         * someone who is not coming back this term.
         */
        'max_absence_days' => 60,
    ],

    'matching' => [
        /*
         * How long a cached result set stays usable. An hour because seats move:
         * longer, and the cache starts offering days that have filled up.
         */
        'score_cache_minutes' => 60,

        /*
         * How long a saved demand keeps looking for a match. A request nobody
         * matched for two months is no longer what that person wants, and
         * notifying them then would be worse than silence.
         */
        'demand_expiry_days' => 60,

        /*
         * The score a newly published commute must reach before its match is
         * worth interrupting someone for. A notification about a poor match
         * teaches people to ignore notifications.
         */
        'notification_score_threshold' => 60,

        /*
         * At most one match notification per demand per day, however many
         * commutes are published. Anti-spam is the point: a passenger who saved
         * one request should not be woken by a burst.
         */
        'notification_cooldown_hours' => 24,

        // Chapter 5: "search requests rate limited".
        'searches_per_user_per_minute' => 20,
    ],

    'commute' => [
        // Chapter 4 §3: "maximum pickup points configurable".
        'max_pickup_points' => 4,
        'max_dropoff_points' => 2,

        /*
         * How far ahead scheduled trips are generated. NOT to the schedule's
         * end date: 5,000 offers × ~110 workdays would be 550,000 rows on day
         * one, in the fastest-growing table in the system. A daily job rolls
         * the horizon forward instead.
         */
        'generation_horizon_days' => 30,

        /*
         * Local hour the night before after which a trip stops taking bookings,
         * so a driver knows their passenger list before they sleep.
         */
        'booking_deadline_hour' => 21,

        /*
         * A commute is shared cost, not a fare. The ceiling is what stops the platform
         * being used as an unlicensed taxi service, so it is a legal boundary rather
         * than a preference.
         *
         * 50–120 EGP, per ERD §23.3 and screen 24's own slider, which runs from EGP 50
         * to EGP 120. These were 5–500, which is not a shared-cost range: at the bottom
         * it let somebody price a fifty-kilometre commute at five pounds, and at the top
         * it allowed five hundred — a fare, and the exact thing the ceiling exists to
         * prevent. Three sources said 50–120 and the config was the outlier.
         */
        'min_price_piastres' => 5_000,
        'max_price_piastres' => 12_000,

        // Chapter 4 §4: "no infinite commutes" — an end date is mandatory, and
        // this bounds how far out it may be.
        'max_schedule_months' => 12,
    ],

    /*
     * The trip itself (Chapter 8, Phase 9).
     */
    'trip' => [
        /*
         * How long before departure a driver may press "start".
         *
         * There has to be a window. Starting the night before would put a run "underway"
         * for twelve hours, which breaks the two things read off that state: the
         * passenger's live map, and the GPS trail a dispute is settled from. Ninety
         * minutes is enough for a driver who leaves early and not enough to matter.
         */
        'start_window_minutes' => 90,

        /*
         * How late a run may set off and still count as on time.
         *
         * 🔴 This number decides what `on_time_rate` means, and that figure is shown to
         * strangers deciding whether to get into somebody's car. Ten minutes because
         * Cairo traffic is not a character flaw: a driver who leaves at 07:12 for an
         * 07:05 departure kept her promise, and scoring her as late would make the
         * number measure the city rather than the person.
         */
        'on_time_threshold_minutes' => 10,

        /*
         * The wait timer's grace period — Bible §7, "5 minutes".
         *
         * 🔴 A passenger who is two minutes away is not a no-show, and a driver who
         * waits for everybody is late for four other people. This number is the whole
         * of that compromise, which is why it belongs in settings the dashboard can
         * move rather than in the code that enforces it.
         */
        'wait_grace_seconds' => 300,

        // The most a driver may add on top, in one extension.
        'wait_extension_seconds' => 120,

        /*
         * How long a passenger has to say "that is not right" about a trip they were
         * marked present for.
         *
         * 🔴 This is the safeguard that makes decision D18 acceptable at all. D18 lets
         * the DRIVER decide whether a passenger travelled — which means one party
         * decides the other's bill. The Master Plan (§15.6) pairs it with a 24-hour
         * dispute window and a two-hour delay before any money moves, and without those
         * two the decision is just an unchecked charge.
         */
        'dispute_window_hours' => 24,

        /*
         * How long after a driver confirms attendance before collection begins
         * (Master Plan §15.6, safeguard 3). Reduces human error: a driver who taps the
         * wrong passenger has two hours to notice before anybody is charged.
         *
         * Not acted on until Phase 8 — recorded here because the number belongs with
         * the others it is part of a set with.
         */
        'settlement_delay_hours' => 2,

        /*
         * Live location (Chapter 8, pitfall #46).
         */

        /*
         * How many positions one request may carry.
         *
         * A client buffers while it has no signal, so a batch after a tunnel is legitimately
         * large — but the cap is what stops a single request queueing a hundred thousand rows
         * for insert. Sixty is ten minutes of pings at five-second intervals, which is longer
         * than any tunnel on the Ring Road.
         */
        'location_batch_max' => 60,

        /*
         * How far in the future a device's clock may be and still be believed.
         *
         * `recorded_at` is the DEVICE's time, which is not ours. Phone clocks are routinely a
         * few seconds out, so rejecting those would throw away honest data — but a point
         * timestamped an hour from now is either a broken clock or a fabrication, and either
         * would poison the trail a dispute is read from.
         */
        'location_clock_skew_seconds' => 120,

        /*
         * The worst accuracy worth keeping, in metres.
         *
         * A phone with no satellite fix guesses from cell towers and can be kilometres out.
         * Drawing those puts the car in the wrong district on a passenger's map, and storing
         * them makes the dispute trail worse rather than better. 500m is generous: a real GPS
         * fix in a city is 5–50m, and the loose readings this rejects are the ones that were
         * never about this street.
         */
        'location_max_accuracy_meters' => 500,

        /*
         * 🔒 How long a GPS trail is kept — ERD §23.4: **90 days**.
         *
         * A legal and ethical boundary, not a tuning knob. `trip_locations` is a
         * minute-by-minute record of where a real person was, kept for one reason: a no-show
         * dispute has no other evidence. The dispute window is 24 hours and a support case
         * takes days, not months — so anything past this is a record with no purpose left,
         * which is the definition of data that should not exist.
         */
        'location_retention_days' => 90,
    ],

    /*
     * "The fair suggested price" on the publish screen (ERD §23.3):
     *
     *     per seat = (km × cost per km) ÷ assumed occupancy, rounded to the step
     *
     * Every one of these is a policy figure the dashboard must be able to move
     * (standard #11), and `cost_per_km_piastres` most of all — it tracks the fuel price,
     * so it changes on somebody else's schedule, and the ERD notes the dashboard has a
     * "fuel index update" broadcast that tells drivers when it does. A suggestion built
     * on a hard-coded fuel cost would quietly advise people to undercharge the week
     * petrol goes up.
     */
    'pricing' => [
        'cost_per_km_piastres' => 750,

        /*
         * How many passengers the cost is split between when suggesting a price.
         *
         * An assumption by necessity: the suggestion is made while the driver is still
         * filling in the form, before anybody has booked. Splitting by seats actually
         * sold would change the advice every time somebody joined — and the price is
         * frozen per booking anyway, because a fixed price per seat is a decision
         * (ERD §23.2: the amount does not move with the number of passengers).
         */
        'assumed_occupancy' => 3,

        // Suggestions land on round numbers, because they are meant to be typed in.
        'rounding_step_piastres' => 500,
    ],

    'geo' => [
        /*
         * DEPLOY-ONLY. Which routing engine answers geographic questions.
         *
         * `straight_line` needs no provider and no API key: it draws straight
         * lines and assumes an average speed. Unlike the OTP and virus-scan
         * stand-ins it does NOT refuse to run in production — a rough travel
         * estimate is a degraded product, while a provider outage taking
         * publishing down entirely would be a broken one.
         */
        'engine' => env('RAFEEQ_GEO_ENGINE', 'straight_line'),

        /*
         * How long a cached route stays usable. The DISTANCE between two places
         * does not change, so this can be generous; travel time does, which is
         * why the two are cached separately (see CachingGeoEngine).
         */
        'route_cache_days' => 30,

        /*
         * How far outside a route's own extent the search box reaches, so a
         * passenger near the road still matches it. Too small is the one error
         * that cannot be recovered downstream: an offer outside its own box is
         * invisible to a search standing next to it.
         */
        'search_margin_metres' => 2000,

        // How far from a pickup point a corridor's origin may sit and still be
        // considered the same corridor.
        'corridor_match_metres' => 3000,
    ],

    'driver' => [
        // Chapter 3 §7: "vehicle year configurable". The floor is a policy
        // decision about what the platform is willing to put passengers in,
        // not a technical limit.
        'vehicle_minimum_year' => 2005,

        // §7: "seats between 2 and 8". This is the vehicle's TOTAL seats
        // including the driver; bookable seats are always one fewer.
        'vehicle_minimum_seats' => 2,
        'vehicle_maximum_seats' => 8,

        'maximum_vehicles_per_driver' => 3,

        /*
         * A licence must still be valid this far into the future before an
         * application is accepted. Reviewing takes 24–48 hours (§4), so a
         * licence expiring tomorrow would be approved and immediately
         * worthless — and the driver would have published commutes by then.
         */
        'licence_minimum_validity_days' => 30,
    ],

    'profile' => [
        /*
         * ASSUMPTION, flagged for product/legal sign-off: Chapter 2 §17.3
         * requires a minimum age ("do not allow users below the minimum
         * supported age") but never states the number, and neither do the
         * Master Plan nor the Bible. 18 is used because it is the age of
         * legal majority in Egypt and the minimum driving-licence age, so it
         * is the only figure that works for drivers and passengers alike.
         *
         * Runtime-tunable precisely BECAUSE it is an assumption: when the
         * real policy lands it is a dashboard edit, not a release.
         */
        'minimum_age_years' => 18,

        // Chapter 2 §17.3: "two to fifty characters". Ceiling: `users
        // .full_name` is varchar(100), so a max above 100 would truncate.
        'full_name_min_length' => 2,
        'full_name_max_length' => 50,
    ],

    'auth' => [

        'otp' => [
            /*
             * DEPLOY-ONLY — never expose this in the admin dashboard.
             *
             * Which transport actually delivers the code. The SMS provider is
             * still open question #2 in RAFEEQ_MASTER_PLAN.md §19, so `log` is
             * the only driver that exists today — it refuses to run in
             * production on purpose, so shipping without a real provider
             * fails loudly instead of silently swallowing every code.
             */
            'driver' => env('RAFEEQ_OTP_DRIVER', 'log'),

            // Chapter 2 §23.2 shows a six-digit code. This is the OTP, not the
            // local PIN (which is four digits per MASTER_PLAN §13).
            'length' => 6,

            // Chapter 2 §12: "2 to 5 minutes" lifetime, "30 to 60 seconds"
            // resend cooldown.
            'ttl_seconds' => 120,
            'resend_cooldown_seconds' => 45,

            'max_attempts' => 5,
            'max_resends' => 3,
        ],

        'session' => [
            // Short-lived access token + long-lived rotating refresh token
            // (Chapter 2 §23.3). Keep the access window small: a stolen-device
            // revocation (scenario G) only bites once the access token dies.
            // Exactly the figures in the Bible's §1.3 walkthrough.
            'access_ttl_minutes' => 15,
            'refresh_ttl_days' => 60,
        ],

        'pin' => [
            /*
             * Informational for the client only — the server NEVER sees a PIN
             * value, it only records `devices.has_local_pin`. Chapter 2 says
             * six digits; MASTER_PLAN §13 resolves that conflict in favour of
             * the prototype's four. Exposed so the app reads one source of
             * truth instead of hard-coding it.
             */
            'length' => 4,
        ],

        /*
         * Abuse controls (Chapter 2 §26, scenario D). Three independent axes:
         * one phone cannot be harassed with SMS, one IP cannot enumerate, and
         * one challenge cannot be brute-forced.
         */
        'rate_limits' => [
            'otp_requests_per_phone_per_hour' => 5,
            'otp_requests_per_ip_per_hour' => 20,
            'otp_verifications_per_challenge_per_minute' => 10,
        ],
    ],

];
