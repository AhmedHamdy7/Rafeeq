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

    /*
    |--------------------------------------------------------------------------
    | API documentation
    |--------------------------------------------------------------------------
    */
    'docs' => [
        /*
         * 🔒 DEPLOY-ONLY. Whether `/docs/api` is open to anyone who knows the URL.
         *
         * Off by default, and that default is the honest one: the OpenAPI document is the complete
         * API surface — every endpoint, every field name, every error code — which is exactly what
         * the mobile team needs and exactly what somebody probing the platform would start by
         * collecting. It does not hand out data or bypass a single check; it hands out a map.
         *
         * On a staging instance with seeded fictional data that trade is clearly worth it, and it
         * is the alternative to emailing a JSON file after every change. On anything holding real
         * people's journeys it is not, so:
         *
         *   · it is ignored outright when APP_ENV=production (see AppServiceProvider)
         *   · turning it off again is one variable, with no deploy
         *
         * The generated document is also available without exposing anything: `composer openapi`
         * writes it to a file you can send them.
         */
        'public' => (bool) env('RAFEEQ_PUBLIC_API_DOCS', false),
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
         * How long an identity or vehicle document's file is kept after UPLOAD before
         * `files:purge-expired` destroys it (ERD §18). Decided 2026-10-06: 90 days from upload,
         * editable from Settings. Stamped onto each row as `purge_after` when the file arrives,
         * so a later change never extends the life of a file sent under the old policy.
         */
        'document_retention_days' => 90,

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

        /*
         * 🔒 The largest PICTURE we will open, in megapixels — which is a different limit from the
         * one above and is the one that matters.
         *
         * A compressed image says nothing about what it costs to decode. A few hundred kilobytes
         * of JPEG can declare 20000 × 15000 pixels, and GD allocates four bytes per pixel — 1.2 GB
         * — before anything else gets a say. That is a decompression bomb, and it is reachable by
         * any signed-in account through the document and evidence endpoints. A byte limit does not
         * stop it, because the file really is small.
         *
         * 16 megapixels covers every mainstream phone camera (12 MP is typical) with room above
         * it, and bounds the decoded bitmap at about 64 MB, which fits inside a 128 MB process.
         * Raising this means raising `memory_limit` with it — see DEPLOYMENT.md.
         *
         * Clients should downscale before uploading anyway: nothing here needs more than 2400px on
         * its longest side, and the image is resized to that regardless.
         */
        'max_document_megapixels' => 16,

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

    /*
     * DEPLOY-ONLY. `RAFEEQ_RATE_LIMITS=off` turns off the request limits (OTP, search, chat, reports)
     * for a test environment. Ignored on APP_ENV=production — see RateLimits.
     */
    'rate_limits_enabled' => env('RAFEEQ_RATE_LIMITS', 'on') !== 'off',

    'payment' => [
        /*
         * How long after the driver confirms somebody travelled before the money is recorded as
         * collected (Master Plan §15.6: "two hours before any money moves"). Long enough for a
         * driver who tapped the wrong name to notice and for a passenger to object first.
         */
        'collection_delay_minutes' => 120,

        /*
         * The most a driver may owe the platform in uncollected fees from cash trips before they
         * may not publish (Bible §8.1: `payment.max_driver_debt_piastres = 20000`, 200 EGP; open
         * question #6 in MASTER_PLAN §19 — the documented figure until decided otherwise).
         * Existing bookings continue; only publishing stops.
         */
        'max_driver_debt_piastres' => 20_000,
    ],

    'booking' => [
        /*
         * The platform's share of each seat, as a percentage.
         *
         * ✅ Decided 2026-10-06 (MASTER_PLAN §19 open question #5, Screen Map §8.1): the fee is
         * DEDUCTED from the driver's price — `price = platform_fee + driver_amount`, the rider
         * pays the published price and nothing on top — at 3%, flat, and staff may change it
         * from the dashboard's Settings page (whole percents).
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

        /*
         * How soon the platform promises to look again at an account it put on hold
         * (screen 35: "Expected update — within 24h").
         *
         * Written onto the suspension when it is created, so a member told 24 hours is held
         * to 24 hours even if this is later changed. Overdue holds are flagged on the
         * MEMBERS page — an account frozen and then forgotten is the failure this exists to
         * make visible.
         */
        'suspension_review_hours' => 24,
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

        /*
         * How far off the published route counts as a deviation, in metres.
         *
         * 🔴 This number decides when the platform says something is wrong, so both mistakes it
         * can make are expensive. Too small and it fires on a diversion round roadworks, a
         * one-way system, or a phone's own error — and an alert that cries wolf is an alert
         * everybody learns to dismiss, including on the morning it matters. Too large and a car
         * genuinely going the wrong way is never flagged.
         *
         * A kilometre, because a driver choosing a different street is not a deviation and a
         * driver a kilometre off the corridor is. Checked only while the run is IN PROGRESS: on
         * the way to collect people, being off the direct line is the job.
         */
        'deviation_threshold_meters' => 1_000,

        /*
         * How long a car on the road may go without sending a position before the live board
         * flags it (Phase 13).
         *
         * A phone in a tunnel goes quiet for a minute and that is nothing; a phone that has
         * been quiet for several is a flat battery, a revoked permission, or something worse,
         * and the person watching the board cannot tell which without asking. Two minutes is
         * twenty-four missed five-second pings — long enough that the Ring Road's tunnels do
         * not trip it, short enough that a real silence is on the screen while it still
         * matters.
         */
        'gps_silence_alert_seconds' => 120,
    ],

    /*
     * Safety (Chapter 10, Phase 11).
     */
    /*
    |--------------------------------------------------------------------------
    | Ratings (Phase 10, Bible §9)
    |--------------------------------------------------------------------------
    */
    'rating' => [
        /*
         * 🔴 How long a completed journey stays rateable — and the same window after which a
         * one-sided rating is revealed anyway.
         *
         * ONE number, not two. The Bible says a rating becomes visible when both parties have
         * rated "or seven days pass". If submission outlived that window, somebody could wait for
         * the reveal, read what the other person wrote, and only then write theirs — double-blind
         * defeated through the front door. So the window that reveals is the window that closes.
         */
        'window_days' => 7,

        /*
         * How long somebody may still change what they wrote, in minutes.
         *
         * 🔒 This is for fixing a typo, and nothing more. It is bounded by something stronger than
         * its own length: an edit is refused the moment the rating becomes visible, whatever the
         * clock says — otherwise "rate, wait for the reveal, read theirs, revise mine" would be a
         * legitimate API call.
         */
        'edit_window_minutes' => 15,
    ],

    /*
     * Notifications (Chapter 11, Phase 12).
     */
    'notifications' => [
        /*
         * Which push transport sends to phones. Only `log` exists today: choosing FCM/APNs needs
         * a project and credentials that do not exist yet. Until then every message still lands
         * in the member's in-app inbox, and each push row records `no_push_provider`.
         */
        'push_driver' => env('RAFEEQ_PUSH_DRIVER', 'log'),
    ],

    /*
     * Trip chat (Chapter 11 §Trip Chat): one conversation per booking, between that day's
     * passenger and driver, for "pickup clarification, arrival updates, minor delays" — and
     * explicitly "not intended for long-term messaging". These numbers are what keep it that.
     */
    'chat' => [
        // How long before departure the conversation opens. A recurring member's booking three
        // weeks out does not need a channel open for three weeks.
        'opens_hours_before' => 24,

        // How long after the journey ends it stays open — "the trip plus a grace period", for
        // "I left my umbrella in your car".
        'grace_minutes' => 120,

        // When a run never records its end, how long after departure the conversation closes
        // anyway. Without this a run nobody completed would keep its chat open for ever.
        'max_hours_after_departure' => 12,

        // Per sender, per minute. Generous for "I'm here / where are you"; tight for flooding.
        'messages_per_minute' => 20,
    ],

    'safety' => [
        /*
         * Night escort mode (Master Plan §170: "auto-arms 9 PM–5 AM, with monitoring from the
         * operations team"). 1 = every corridor is armed each night; 0 = only what staff arm by
         * hand. The Bible names this key: `safety.night_escort_enabled`.
         */
        'night_escort_enabled' => 1,

        // The night window, in Cairo hours. Ends the next morning.
        'escort_starts_hour' => 21,
        'escort_ends_hour' => 5,

        /*
         * How long somebody has to take back an SOS before it is treated as real.
         *
         * 🔴 The countdown runs on the PHONE and the row is written the instant the button is
         * pressed — see TriggerSosAction for why that order is deliberate. This number is what the
         * client counts down and what gets copied onto the row, so a review months later can ask
         * "how long did she have to cancel" and get the answer that was true then.
         *
         * Ten seconds: long enough to notice a pocket press, short enough that somebody who meant
         * it is not watching a progress bar while it matters.
         */
        'sos_countdown_seconds' => 10,

        /*
         * How many emergency contacts one person may keep.
         *
         * A limit because this list is who gets told where somebody is, and an unbounded one is a
         * way to broadcast a person's movements to a crowd. Five is more than anybody needs and
         * fewer than an abuser could use.
         */
        'max_emergency_contacts' => 5,

        /*
         * How many reports one person may file in an hour.
         *
         * Chapter 10 §Security asks for this by name ("prevent false incident spam",
         * "rate-limit reports"). Deliberately generous: somebody in a genuinely bad situation may
         * file two or three in quick succession, and the failure mode to avoid is refusing a real
         * report — not admitting a spurious one, which a human reads and closes.
         */
        'reports_per_hour' => 10,

        /*
         * 🔒 How long evidence attached to a report is kept, in days.
         *
         * Longer than a GPS trail because a report can become a legal matter and the file IS the
         * evidence, but still bounded: photographs of an incident involving identifiable people are
         * not something to hold for ever by default.
         */
        'evidence_retention_days' => 365,

        /*
         * 🔒 How many files one report may carry.
         *
         * A cap at all, because `incident_evidence` is never deleted: anything written there is
         * written for a year, so an unbounded upload path is an unbounded commitment. Five is more
         * than any real report needs — a photo of the damage, a screenshot of the messages — and
         * few enough that a bored client cannot fill a bucket.
         *
         * Generous on purpose about which five: the limit refuses the sixth file, never the report.
         */
        'max_evidence_per_incident' => 5,

        /*
         * The largest single evidence file, in kilobytes.
         *
         * Smaller than an identity document's allowance, because this is a photograph taken in the
         * moment rather than a scan a reviewer has to read small print on — and the bytes are
         * re-encoded and downscaled before storage anyway.
         */
        'max_evidence_size_kilobytes' => 6144,

        /*
         * How long after a journey ends a live-share link keeps working, in minutes.
         *
         * A grace rather than an instant cut-off: a contact who opens the link as the car pulls
         * in should see it arrive rather than an expired page, and "the trip ended" is a moment
         * the driver chooses — which can be a few minutes after everybody is actually home.
         */
        'live_share_grace_minutes' => 15,

        /*
         * 🔒 The ceiling on a share whose journey has not finished yet.
         *
         * The link has to expire at SOME point even if the driver never taps "complete" — and
         * that point is the difference between a safety feature and a standing window onto
         * wherever somebody goes next. Three hours is longer than any commute in the product and
         * short enough that a forgotten run does not leave a live link open all evening.
         */
        'live_share_max_minutes' => 180,
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

            /*
             * DEPLOY-ONLY. A fixed code, so a shared server can be signed into while there
             * is still no SMS provider.
             *
             * 🔴 Why this exists at all: the only transport writes the code to the log, and
             * an external team working against a deployed instance cannot read a log. Without
             * this they reach the OTP screen and stop — so the choice is between a fixed code
             * and the team being blocked entirely.
             *
             * 🔒 And why it is safe to have in the codebase: `RequestOtpAction` refuses it in
             * production, checking the ENVIRONMENT rather than this value. Config is exactly
             * what a wrong deploy gets wrong, so a forgotten `RAFEEQ_DEV_OTP_CODE` in a
             * production `.env` changes nothing. Same guard, same reasoning, as
             * `LogOtpSender` refusing to run there.
             *
             * Null disables it and every code is random again. It must be exactly
             * `auth.otp.length` digits — anything else and nobody could sign in, which is
             * why the Action throws rather than letting it through.
             */
            'dev_fixed_code' => env('RAFEEQ_DEV_OTP_CODE'),

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

    /*
     * How long rows are kept before `model:prune` deletes them (ERD §18). Legal and privacy policy,
     * not tuning — so here rather than in platform_settings, where a dashboard toggle could quietly
     * extend how long the platform holds people's messages.
     */
    'retention' => [
        'otp_challenges_days' => 7,
        'notifications_days' => 90,
        'messages_months' => 12,
        'payment_webhooks_days' => 90,
        'security_events_months' => 24,
        'admin_actions_months' => 24,
    ],

];
