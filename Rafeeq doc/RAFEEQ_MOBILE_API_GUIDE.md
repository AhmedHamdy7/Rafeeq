# RAFEEQ — Mobile API Guide

> **Who this is for:** the Flutter team building the 47 designed screens.
>
> **What it answers:** for every screen, which endpoints it runs on, what each field means, and
> what is not built yet — so you can start on the screens that are ready and know exactly which
> ones to leave until later.
>
> **Two documents, different jobs.** This is the *guide*: screen mapping, field meanings, rules
> you cannot see in a schema. The **OpenAPI document** is the *contract*: exact types, request
> shapes, every error code per endpoint. Generate it with `composer openapi`, or read it at
> `/docs/api` on any running instance. Where the two ever disagree, **the OpenAPI document is
> generated from the code and therefore right** — tell us and we will fix this file.
>
> **Base URL:** `{host}/api/v1` · **Auth:** bearer token · **Format:** JSON only.

**Last updated:** 2026-10-23 · **154 endpoints live** · Phases 0–7, 9 and 11 complete · 10, 12 and 13 all but a decision each

---

## Summary

### What is ready

**154 endpoints live** and tested, across the 47 designed screens:

| | Count | Covers |
|---|---|---|
| ✅ **Fully served** | ~32 screens | Sign-in and sign-up · verification · driver and vehicles · publishing a commute · search and matching · seat requests · the group · the live trip · **ratings and reviews** · **the Safety Centre** (SOS · trusted contacts · reports with evidence · blocking · live share) · notifications inbox · trip chat |
| ⚠️ **Served, missing a field or two** | ~8 screens | Each one says exactly what is absent, in the screen index. |
| ⛔ **Not served** | ~3 screens | Payments and the wallet (34) · a driver cancelling a single day (43, 45, 46). Both have a phase and a reason in section 7. |
| 🚫 **No API needed** | 4 screens | Device states, or static copy. |

### Where to start

The suggested order is in **Build order** below. In short: sign-in (5→6→7→8) → verification (15, 30)
→ home (9) → search (10, 11, 12) → seat request (13, 14) → trips and the group (19, 20) → then the
driver side (23, 24, 25, 31, 28, 29) → and the live trip last (17, 36, 40, 41).

If you would rather see the lifecycle before building any of it, **2.1** walks the whole cycle as an
ordered sequence of calls, against accounts that are already seeded on staging.

**How this file is laid out.** Sections 1 to 3 are global: the conventions every response obeys, the
suggested build order, and the index of all 47 screens. Then **4.1 to 4.16 are one chapter per
feature** — auth, account, verification, driver, commutes, search, bookings, pickup points, the live
trip, groups, home, safety, ratings, notifications, chat, money. Open the chapter for the feature you
are building: its endpoints, the shapes they return, and a card naming its screens are all in that
one place. 4.0 is a flat index of every endpoint if you only need to find where something is
described.

### Seven things to know before you start

1. **Every response has the same shape** (`success` · `data` · `meta`?). Errors do too, carrying
   `error.code` — **switch on the `code`, never on the message text.** The text is translated and
   will change; the code will not.
2. **All money is in piastres** (`*Piastres`) — whole numbers, never decimals. 8000 = EGP 80. Never
   divide by 100 and store the result.
3. **Times are UTC in ISO 8601**, with a local wall clock alongside where it matters for display.
   Anything named `*Local` is for showing only — **never compute with it.**
4. **Days are a bitmask**: Sat 1 · Sun 2 · Mon 4 · Tue 8 · Wed 16 · Thu 32 · Fri 64. "Sunday to
   Thursday" is 62.
5. **Privacy is built into the API, not into the screen.** A phone number, a full name and a gender
   are **never** returned about another person, and a meeting point comes back **fuzzed** until the
   booking is confirmed. You do not need to hide anything in the UI — we do not send it.
6. **Every list that grows with use is paginated.** Use `meta.hasMore`, not the row count.
7. **`null` means "not known", not zero.** A `null` rating means nobody has rated that person yet —
   **do not draw it as nought stars.**

### This file is kept current

Updated with **every new endpoint and every phase completed**, and
`tests/Feature/MobileApiGuideTest.php` fails the build if it drifts: every endpoint named here must
exist, every live endpoint must be named here, and the `nextStep` values must match the enum. So
this file **cannot go stale** without the build going red. The last-updated date is above; the change
log is at the end.

---

## 1. Conventions

Everything in this section applies to every endpoint. Read it once.

### 1.1 The response envelope

Every response, success or failure, has the same outer shape.

**Success:**

```json
{
  "success": true,
  "data": { },
  "meta": { "page": 1, "perPage": 20, "total": 84, "lastPage": 5, "hasMore": true }
}
```

| Field | Meaning |
|---|---|
| `success` | Always `true` on 2xx. Do not use it as your only check — use the HTTP status. |
| `data` | The payload. An object, an array, or occasionally a single flag like `{"revoked": true}`. |
| `meta` | **Present only on paginated endpoints.** Absent — not `null` — everywhere else. A client model that requires it will fail to parse most responses. |

**Failure:**

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_DEADLINE_PASSED",
    "message": "This commute stopped taking bookings for that day.",
    "fields": { "tripDate": ["2026-09-27"] }
  }
}
```

| Field | Meaning |
|---|---|
| `error.code` | A stable machine code. **Switch on this.** It never changes for a given condition; the message might. |
| `error.message` | Ready to show a person, already in their language (see 1.4). Do not build your own copy from the code. |
| `error.fields` | Extra context, always a map of `string` → `array of string`. For validation it is field name → messages. For a domain refusal it is whatever helps you explain: `allowed`, `currentStatus`, `closedAt`, `windowMinutes`. Sometimes `{}`. |

### 1.2 Authentication

```
Authorization: Bearer {accessToken}
```

Three endpoints need no token: `POST /auth/otp/request`, `POST /auth/otp/verify`,
`POST /auth/session/refresh`. Everything else does.

| Token | Lifetime | Notes |
|---|---|---|
| `accessToken` | **15 minutes** | Short on purpose. Expect 401s in normal use and refresh. |
| `refreshToken` | **60 days** | **Single use.** Every refresh returns a NEW refresh token; the old one is dead. Store the new one before you use it. |

**Reusing a refresh token is treated as theft**, not as a mistake: the whole session family is
revoked and you get `AUTH_SESSION_REUSE_DETECTED`. If two of your threads refresh at once, one
of them will kill the session. Serialise refreshes behind a single lock.

On `401` with `AUTH_DEVICE_REVOKED` the device was signed out remotely — clear local state and
go to the phone screen. On plain `UNAUTHENTICATED`, refresh once and retry.

**Four access tiers.** A 403 is not always a bug:

| Tier | Means | Reachable with |
|---|---|---|
| public | no credential | OTP + refresh only |
| signed-in | valid token, account may be suspended or profile half-finished | `auth/me`, logout, devices, consents, preferences, onboarding state, verification centre |
| active, profile complete | not suspended, profile complete | **everything a passenger does** — search, seat requests, bookings, groups, the live trip, ratings, safety |
| verified | the above **plus** an approved `government_id` | **the driver surface only** — the application, vehicles, and publishing or managing a commute |

🔴 **A passenger needs no identity verification.** She books with a verified phone number and
nothing more. The driver approves each seat request by hand and sees the passenger's trust level
before she does, and a driver who wants a higher bar sets her own `minRating` and trust filters —
her choice rather than a blanket platform rule.

The asymmetry is deliberate: the person **carrying** strangers is checked, the person riding is
vouched for by the driver who agreed to carry her. So `VERIFICATION_REQUIRED` now only ever comes
back from the driver surface.

`ACCOUNT_SUSPENDED` and `ACCOUNT_PROFILE_INCOMPLETE` are **403, deliberately not 401** — the
session is fine, so do not throw it away and restart at the phone screen.

`ACCOUNT_SUSPENDED` carries screen 35's data in `error.fields`, each as a one-item list like
every other field: `caseNumber`, `reasonCode`, `suspendedAt`, `reviewDueAt` — so a client that
meets it in the middle of a flow can show the screen without another call.

### 1.3 Pagination

Paginated endpoints take `?page=` and `?perPage=` (default 20, max 100) and return `meta`.

**Use `meta.hasMore`**, not "did I get fewer rows than I asked for". The two disagree when a row
is filtered after the query.

Paginated today: `GET /matches` · `GET /my-bookings` · `GET /driver/bookings` ·
`GET /seat-requests` · `GET /driver/seat-requests` · `GET /pickup-requests` ·
`GET /driver/pickup-requests` · `GET /groups` · `GET /groups/{group}/members` ·
`GET /groups/{group}/attendance` · `GET /groups/{group}/absences` · `GET /account/devices`.

**Not paginated, by design:** `GET /home` and `GET /driver/home`. Each part is capped at what
the screen shows, so the response cannot grow with use — a passenger with 400 bookings gets the
same size payload as one with three. "See all" goes to the paginated list.

### 1.4 Language

```
Accept-Language: ar    (or en)
```

Arabic is the default. This changes `error.message` and any human-facing string we generate. It
does **not** translate user content — a driver's note comes back as they typed it.

### 1.5 Time

- Every instant is **UTC, ISO 8601**: `2026-09-27T05:05:00+00:00`.
- Fields ending `Local` are a **local wall clock for display** (`2026-09-27 07:05:00`) with no
  offset. Never compute with them.
- `timezone` accompanies recurring schedules (`Africa/Cairo`). A schedule is stored as a local
  time plus a zone, and each day's UTC instant is computed per day — **so the offset between
  `departureTime` and `departureAt` is not constant across a DST change.** Always use the
  instant on the specific trip.
- `*InMinutes` / `*InSeconds` countdowns are **the server's answer at the moment of the
  response**. They go stale immediately. Use them to correct the device clock, then count down
  from the matching instant. They are intentionally **negative** when the moment has passed.

### 1.6 Money

All amounts are **integer piastres** in a field ending `Piastres`. 1 EGP = 100 piastres.

Never hold money in a float. `88.20 * 3` is `264.59999999999997` in every language you will use.
Divide by 100 only at the moment you render.

A booking's money is **frozen at approval** and never recomputed. If the driver raises the price
tomorrow, your stored booking is still right.

### 1.7 IDs

Every id is a **26-character ULID** string: `01m3fa8h2bs9tqpfq86bnymd3c`. Treat them as opaque.
They sort chronologically, which is useful, but do not parse them.

### 1.8 Days bitmask

| Sat | Sun | Mon | Tue | Wed | Thu | Fri |
|---|---|---|---|---|---|---|
| 1 | 2 | 4 | 8 | 16 | 32 | 64 |

Sunday–Thursday (the Egyptian working week) = `62`. Any field named `*Mask` uses this.

### 1.9 Enums come back UPPER_CASE

Status-like values are returned upper case (`CONFIRMED`, `PASSENGER_NO_SHOW`, `IN_PROGRESS`) and
accepted either way on input. Type-like values stay lower case (`cash`, `trial`, `women_only`).
The OpenAPI document enumerates the exact set per field — do not infer it from an example.

### 1.10 Rate limits

A `429` always carries `Retry-After` (seconds). Honour it; do not retry on a fixed backoff.
OTP request and verify, and search, are the limited ones.

### 1.11 Privacy rules that affect what you receive

These are enforced on the server. You do not need to hide anything — we do not send it.

| Rule | What it means for you |
|---|---|
| **No phone, full name, email or gender of another member, ever** | Another person is always a `person` object (see 5.1): public first name, trust level, badges, numbers. There is no endpoint that returns more. |
| **Meeting point is fuzzed until the booking is confirmed** | `meetingPoint.isExact: false` means the coordinates are rounded to ~110m. Show the area, not a pin on a door. |
| **Plate number only on a confirmed booking** | `vehicle.plateNumber` is `null` otherwise. Colour and model are always there — that is what identifies the car at a gate. |
| **No GPS trail endpoint exists** | You can read the car's *current* position during a run. The history is kept for disputes, read by support, and deleted after 90 days. |

---

## 2. Build order

The order that keeps you unblocked. Each step only needs the ones above it.

| # | Screens | Why here |
|---|---|---|
| 1 | 5, 6 — phone, OTP | Nothing works without a token. |
| 2 | 7, 8 — basic profile, role | `profileStatus` gates the `active` tier. Until it is `BASIC_COMPLETE` most endpoints 403. |
| 3 | ~~2, 3, 4~~ | 🔴 **Gone.** The local PIN, biometric login and account switching left the MVP — no PIN setup, no PIN entry, no "forgot PIN", no account picker. Session restore is silent. Skip straight to step 4. |
| 4 | 15, 30 — verification centre, identity capture | 🔴 **No longer blocks booking.** `verified:government_id` now gates **only the driver flow** — the application, vehicles, and publishing. A passenger books with a verified phone number and nothing else, so you can build steps 5–8 before this one. Still build it: it drives the home banner, the trust level a driver sees when deciding on your request, and her optional `minRating`/trust filters. |
| 5 | 9 — home | One call. Gives you a real screen early and exercises the envelope, the person shape and the verification banner. |
| 6 | 10, 11, 12 — discover, filters, match details | The search engine. Read 4.6 first; the score breakdown is designed to be shown. |
| 7 | 13, 14 — seat request, done | First write path with a domain refusal you must render properly. |
| 8 | 19, 20 — trips, group | The lists. Pagination matters here. |
| 9 | 23, 24, 25, 31 — driver home, publish, review, vehicle | The driver side. 31 needs the document upload flow from step 4. |
| 10 | 28, 29 — request review, pickup approval | Driver decisions. |
| 11 | 17, 36, 40, 41 — active trip, check-in, no-show, wait timer | The live run. Needs a WebSocket (section 6) and is the most stateful part. |
| 12 | 21, 22, 26, 27, 35 | Account screens. 22 and 26 have no API yet (section 7). |

---

### 2.1 The whole cycle, request by request

The section above says which screens to build first. This one says what the calls actually look like
**in order**, so the lifecycle is visible before you build any of it — and every request below works
today, against the seeded demo data.

Two accounts travel through it. Where the two columns sit side by side, the passenger's call and the
driver's call are both needed for the step to complete.

> **Follow along:** the staging database is seeded with the accounts in the table at the end of this
> section, and `RAFEEQ_DEV_OTP_CODE` is set, so every OTP is the same six digits. You can run the
> whole sequence with `curl` before writing a line of Dart.

#### A. Getting a token (both roles)

```
1  POST /auth/otp/request          { phone, purpose: "authentication", device: {...} }
   → 202  { challengeId, expiresInSeconds, resendAfterSeconds }

2  POST /auth/otp/verify           { challengeId, code, device: {...} }
   → 200  { user: {...}, session: { accessToken, refreshToken, ... }, nextStep }
```

🔴 **`nextStep` is the router for the whole app, and reading it is the difference between a client
that works and one that guesses.** There are exactly five values, and the server picks one in this
order of precedence:

| `nextStep` | Show | Why it outranks what follows |
|---|---|---|
| `PHONE_OTP` | Screen 5 | No usable session. Returned by refresh when the token is dead, so you have one vocabulary for "go back to the phone screen" rather than inferring it from a 401. |
| `RESTRICTED_ACCOUNT` | Screen 35 | A restricted account must never be routed home, whatever else is true. 🔴 This is the **whole account**. A **driver-only** restriction does NOT return this — that person goes `HOME` with full passenger access, and you detect it from `GET /driver/eligibility`, which returns the `driver_suspended` blocker with a message and `nextStep: CONTACT_SUPPORT`. Her commutes also stop appearing in search, and publishing answers `403`. |
| `BASIC_PROFILE` | Screen 7, then 8 | The profile is unfinished **or no role has been chosen yet**, so it is resumed — never skipped. See the warning below: this is the answer even when the profile looks complete. |
| `ROLE_SELECTION` | Screen 8 | Passenger / Driver / Both. 🔴 Returned by `PUT /account/profile/basic` **and by nothing else** — see below. |
| `HOME` | Screen 9 | Nothing is outstanding. Read `user.capabilities` and `activeMode` to pick which home. |

Do not infer the destination from which fields happen to be null, and do not add a sixth case of
your own: `GET /auth/me` returns the same field and is reachable **while suspended**, which is how
the app recovers if it ever loses its place.

> 🔴 **`ROLE_SELECTION` comes from one endpoint only, and this surprises everybody once.**
>
> A **cold start** with the profile saved but no role chosen answers **`BASIC_PROFILE`**, not
> `ROLE_SELECTION`. That is deliberate (Feature 01 §5): somebody who closed the app on the role
> screen comes back to her profile, sees the name and date she entered, confirms them, and is then
> asked for the role. Dropping her straight on Role would show a screen with no context behind it.
>
> So the sequence is: `PUT /account/profile/basic` → `ROLE_SELECTION` → `POST /account/initial-role`
> → `HOME`. If you ask `GET /auth/me` or `GET /account/onboarding-state` in the middle of that, you
> get `BASIC_PROFILE` — and submitting the profile again is the correct way forward, not a loop.
>
> **If you still send `registeredRole` with the profile, nothing changes for you.** The role is
> recorded in the same request and you go straight to `HOME`, exactly as before. The new screen is
> opt-in, so you can adopt it whenever suits you.

> ⚠️ **Identity verification is not in this list, on purpose.** It is not a step in sign-up — it is a
> gate on specific endpoints, which answer `403 VERIFICATION_REQUIRED` naming the levels they want.
> So a person reaches home and browses first, and only meets verification when they try to ask for a
> seat. Drive that screen from `GET /account/verifications` and from the home banner, never from
> `nextStep`.

```
3  PUT /account/profile/basic      { fullName, gender, dateOfBirth }
   → 200   profileStatus becomes BASIC_COMPLETE, nextStep: ROLE_SELECTION

3b POST /account/initial-role      { role: "passenger" | "driver" | "both" }
   → 200   capabilities filled in, nextStep: HOME
```

Until step 3 succeeds most endpoints answer `403 ACCOUNT_PROFILE_INCOMPLETE`. The seeded accounts
are already past both steps.

**Step 3b is new and step 3 did not change for you.** If you keep sending `registeredRole` in step
3 — as every build does today — the role is recorded there, `nextStep` is `HOME`, and you can skip
3b entirely. Omit it and you get the role screen instead. Both are supported; pick whichever suits
your release.

Two things about 3b worth knowing before you wire it:

- **It is safe to retry.** Enrolling twice is the same as enrolling once, so a timeout you are not
  sure committed can simply be sent again.
- **`both` starts on the Passenger home**, and the driver walkthrough runs the first time the driver
  side is opened — not at the end of sign-up. Record that it was shown with
  `PATCH /account/preferences { driverWalkthroughSeen: true }` so a second device does not replay it.

If the app is killed anywhere in this sequence, ask `GET /account/onboarding-state` at launch and
follow its `nextStep`. It is reachable before the profile exists and while suspended, which is
exactly when you need it.

```
4  POST /account/verifications/government_id/documents    multipart: kind, file
   POST /account/verifications/government_id/documents    (the second side)
   POST /account/verifications/government_id/submit
   → 200   status becomes PENDING, and a human decides
```

🔴 **Submitting is not being verified.** A reviewer approves it, so on a fresh account the next step
is a wait — which is why the seeded accounts come pre-approved. `GET /account/verifications` is what
screen 15 renders while waiting.

```
5  POST /auth/session/refresh      { refreshToken }
   → 200  a new pair
```

Access tokens last **15 minutes**. Refresh on a `401 AUTH_SESSION_EXPIRED` and retry the original
request once — this is the single most common reason a client "randomly stops working" after a
quarter of an hour.

#### B. Passenger: finding a seat and asking for it

```
6  GET /home
   → 200  greeting · verification banner · nextJourney · topMatches · savedSearches

7  GET /search/commutes?origin[lat]=..&origin[lng]=..&destination[lat]=..&
                        destination[lng]=..&daysMask=62&arrivalWindowStart=06:45:00&
                        arrivalWindowEnd=08:00:00&maxWalkMinutes=15&maxDetourMinutes=15
   → 200  a ranked list; each row has commuteId, score + its breakdown, driver summary, vehicle

8  POST /commutes/{commute}/pickup-preview   { lat, lng }
   → 200  { addedMinutes, runTotalMinutes, maxDetourMinutes, withinLimit }
```

Step 8 creates nothing. It exists so the passenger can see the detour **before** committing to a
custom meeting point, and so your screen can grey out a point the driver would refuse.

```
9  GET /commutes/{commute}/reviews
   → 200  anonymous reviews, month-dated (4.13)

10 POST /commutes/{commute}/seat-requests
   { commitment: "trial"|"recurring", scheduledTripId?, requestedDaysMask?, seats,
     meetingPreference, introMessage, paymentType, agreedToRules: true }
   → 201  the request, status PENDING
```

🔴 **`agreedToRules` must be true and the refusals here are the ones to render properly**, not as a
generic error: `SEAT_UNAVAILABLE` (409 — taken while they were deciding), `BOOKING_DEADLINE_PASSED`
(409), `BOOKING_ALREADY_REQUESTED` (409), `VERIFICATION_REQUIRED` (403 — send them to step 4 and
bring them back).

#### C. Driver: answering it

```
11 GET /driver/home
   → 200  nextRun · pendingRequests (count + first five) · stats

12 GET /driver/seat-requests
   → 200  each with the passenger's public summary and commitment

13 POST /driver/seat-requests/{request}/approve        → 201  { bookings: [...] }
   POST /driver/seat-requests/{request}/reject         { reason }
   POST /driver/seat-requests/{request}/waitlist
```

🔴 **Approval is the most dangerous operation in the platform** and the one place your UI must not
assume success: it runs under a row lock and can still lose to another approval on the last seat.
Handle `SEAT_UNAVAILABLE` on the driver's side too. A trial returns one booking; a recurring
membership returns one per committed day — one response shape for both.

```
14 GET /my-bookings            (passenger)      → 200  paginated
   GET /groups/{group}         (either)         → 200  overview · rules · members
```

Only now does the passenger get the **exact** meeting point and the vehicle's **plate**:
`BookingStatus::grantsExactDetails()` gates both until the booking is `CONFIRMED`. Before that the
point is deliberately fuzzed — see 1.9 and 4.1. Do not treat the coarse point as a bug.

#### D. The day of the trip

```
15 POST /groups/{group}/attendance    { date, status: "coming"|"away" }   (passenger, screen 36)
   → 200
```

🔴 **This is the DECLARED attendance, not boarding.** It says "I intend to come". Who actually
travelled is step 19, and the driver records that. Two different tables on purpose.

```
16 POST /trips/{trip}/start                                  (driver)  → 201  the session
17 POST /trips/{trip}/status   { status: "EN_ROUTE" }         (driver)  → 200
   POST /trips/{trip}/location { points: [ {lat,lng,recordedAt,accuracyMeters,speedKmh}, ... ] }
   → 200  { accepted, rejected }
```

Positions are sent in **batches**, and `recordedAt` is the **device's** clock — a phone that loses
signal in a tunnel and sends six buffered points on the other side is describing six different
moments. The server bounds them; send what you recorded.

```
18 GET /trips/{trip}/location            (passenger on the run)
   → 200  { position, lastLocationAt }
   WebSocket: private-trip.{sessionId}   (section 6)
```

The socket is the fast path and this endpoint is the fallback. **Build the polling first** — a phone
on a bad connection at a bus stop is exactly what a live map is for and exactly where a socket fails
to open.

```
19 POST /trips/{trip}/check-in   { bookingId }      (driver)  → 200
   POST /trips/{trip}/no-show    { bookingId }      (driver)  → 200
   POST /trips/{trip}/wait-timers { bookingId }     (driver)  → 201
   POST /bookings/{booking}/dispute { reason }      (passenger, 24 hours)  → 201
```

🔴 The driver confirms attendance (decision D18) **and the passenger has 24 hours to contest it**.
Both halves are built; a client that ships the first without the second is shipping one party
deciding the other's bill with no recourse.

```
20 POST /trips/{trip}/status  { status: "IN_PROGRESS" }   (driver)  → 200
21 POST /trips/{trip}/complete                            (driver)  → 200
```

Completing from `EN_ROUTE` is refused — `IN_PROGRESS` first. `en_route` means she is still
collecting people, so finishing from it would record a journey nobody was on.

#### E. After the trip

```
22 GET /ratings/pending
   → 200  the journeys awaiting this person's rating, with rateableUntil

23 POST /bookings/{booking}/rating   { stars, comment?, tags? }
   → 201  your own rating; isVisible is false until the other side rates
```

🔴 **Both sides rate before either can read the other** (4.13). `isVisible: false` says nothing
about whether they have rated — do not word it as "waiting for them".

```
24 PATCH /ratings/{rating}      { stars?, comment?, tags? }
   → 200  — refused the moment it becomes visible, whatever editableUntil said
25 GET /ratings/about-me        → 200  what was said about you, anonymous
26 POST /ratings/{rating}/report { reason }   → 201  flags for a human; removes nothing
```

#### F. Safety — available at every step above

```
POST /sos                              { isDiscreet?, lat?, lng?, tripSessionId? }  → 201
POST /sos/{sos}/cancel                                                              → 200
GET  /safety/emergency-contacts · POST · PATCH · DELETE
POST /trips/{trip}/live-share          { contactId? }   → 201  { url, token } ONCE
POST /incidents                        { category, description?, bookingId? }       → 201
POST /incidents/{incident}/evidence    multipart: kind, file                        → 201
POST /safety/blocked-users             { userId, reason? }                          → 201
```

🔴 **Every one of these works from a half-finished sign-up.** No verification gate, no complete
profile, no active trip. That is deliberate — somebody in trouble at a roadside will not finish
uploading a national ID first — so **do not gate the safety button in your own navigation either.**

#### No PIN, no biometric login, no account switching

🔴 **Removed from the MVP** (Feature 01 v3 §10), and with them screens 2, 3 and 4. If you built
any of this, it can come out:

| Gone | What replaces it |
|---|---|
| PIN setup, PIN entry, forgot/reset PIN | Nothing. Session restore is silent — a valid refresh token gets you in. |
| `hasLocalPin` on the device payload and request | Nothing. The field is **ignored** rather than refused, so you can drop it from the app whenever suits you. |
| `pinLength` on auth responses | Nothing. |
| `purpose: "pin_reset"` on `/auth/otp/request` | Nothing — it is now a `422`. `authentication` is the only purpose. |
| ~~CREATE_PIN~~, ~~LOCAL_SECURITY_SETUP_OR_HOME~~ | See the `nextStep` table above. |
| Account switching | Nothing. One account per installation. |

**What to keep storing:** the refresh token, in `flutter_secure_storage`
(`AndroidOptions(encryptedSharedPreferences: true)`) — guarded by the device's own lock if you
like, but **not** by a PIN of ours. On a `401 AUTH_SESSION_EXPIRED`, refresh once and retry; on a
`401` from refresh itself the token is dead, so clear storage and return to the phone screen.

`biometricEnabled` still exists on `PATCH /account/devices/current/security`, but it is now a
**preference the devices screen displays** — it unlocks nothing and the server makes no decision
from it.

#### Accounts seeded on staging

| Phone | Who | What they already have |
|---|---|---|
| `01125847213` | Ahmed Ibrahim | Verified passenger · confirmed seat · saved search · standing request |
| `01014531739` | Omar Aly | The same |
| `01033334444` | Mariam | Verified passenger · bookings · **a completed, rated trip** |
| `01011112222` | Nour | Approved **driver** · published commute · group · pending seat requests |

Six published commutes, ~130 bookable days, one revealed rating pair. Start at step 7 with Mariam or
one of the testers; start at step 11 with Nour. Every OTP is the fixed development code.

---

## 3. Screen index

Status: ✅ fully served · ⚠️ served, something named missing · ⛔ no API yet · 🚫 needs none.

### Sign-up and sign-in

| # | Screen | Endpoints | Status |
|---|---|---|---|
| 1 | Splash / welcome | — | 🚫 Language toggle is local. |
| 2 | ~~Login (PIN)~~ | — | 🔴 **Removed from the MVP** (v3). Session restore is silent: a valid refresh token gets you in with no screen at all. |
| 3 | ~~Use another account~~ | — | 🔴 **Removed from the MVP** (v3). One account per installation. |
| 4 | ~~Forgot PIN~~ | — | 🔴 **Removed from the MVP** (v3). `purpose=pin_reset` is now a `422`. |
| 5 | Phone | `POST /auth/otp/request` | ✅ |
| 6 | OTP | `POST /auth/otp/verify` | ✅ Drive the countdown from `resendAvailableInSeconds`, and on 429 from `Retry-After`. |
| 7 | Basic profile | `PUT /account/profile/basic` | ✅ **Send `firstName` + `lastName`** (or `fullName` — both work). `lastName` optional. ⚠️ Two things still differ from your screen: it collects **workplace/university in this step** where we have a separate verification endpoint (section 8), and it offers **Woman/Man only** while the API also accepts `prefer_not_to_say` — see the note below. |
| 8 | Role | `POST /account/initial-role` | ✅ **Now its own endpoint** (v3). Reached when `PUT /account/profile/basic` answers `nextStep: ROLE_SELECTION` — which happens when you omit `registeredRole`. Keep sending it in step 7 and this screen is skipped, exactly as today. |
| — | Onboarding recovery | `GET /account/onboarding-state` | ✅ Not a screen — call it at launch to find out which of 5–9 to open after an interrupted sign-up. |

### Search and matching

| # | Screen | Endpoints | Status |
|---|---|---|---|
| 9 | **Home** | **`GET /home`** | ✅ One call: greeting, verification banner, next journey, top matches, saved corridors. The check-in button belongs to screen 36. |
| 10 | Discover | `GET /search/commutes` | ⚠️ Vehicle, score and breakdown, walk and detour, commitment, seats, rules, rating and badges — all there. **Missing: `why[]`**, the match-reason chips. |
| 11 | Filters | local + `POST /saved-searches` | ⚠️ The **minimum-rating** filter has no data behind it until ratings exist (Phase 10). |
| 12 | **Match details** | `GET /search/commutes` | ⚠️ Rating and badges ✅. **Missing: the price breakdown** (route contribution ÷ passengers + platform fee) — blocked on an open money decision, section 8 — and **reviews** (Phase 10). |
| 18 | Create commute request | `POST /commute-demands` | ✅ `flexibilityMinutes` and `wantsReturnTrip` are stored and returned. **Note the rename: the budget is `budgetMonthlyPiastres`, a MONTHLY ceiling**, not per seat. Return-leg *matching* itself is not built — the demand is recorded. |
| 42 | Matching spinner | — | 🚫 Loading state. |

### Verification

| # | Screen | Endpoints | Status |
|---|---|---|---|
| 15 | **Verification centre** | `GET /account/verifications` | ✅ `level` / `of` / `percentage`, plus per level: state, `actionNeededReason` in the reviewer's own words, `expectedReviewMinutes`, `attemptsRemaining`. |
| 30 | Identity capture | `POST /account/verifications/{type}/documents` · `/submit` · `POST /account/consents` | ⚠️ Biometric consent exists. **Confirm with us that `selfie` is an accepted document kind before you build the liveness step.** |

### Driver and publishing

| # | Screen | Endpoints | Status |
|---|---|---|---|
| 23 | **Driver home** | **`GET /driver/home`** | ✅ One call: today's run, countdown, attendance counts, seats open, what to collect and what she keeps, approved passengers with their meeting points, pending requests with the full count, and her stats. A passenger who is not a driver gets an **empty** payload, not a 404 — the role switch lives on this screen. **"Cancel today"** → `POST /trips/{trip}/cancel` (4.9). |
| 24 | Publish route | `POST /commutes` · `PUT /commutes/{commute}/route` · `PUT /commutes/{commute}/schedule` · `GET /commutes/{commute}/price-suggestion` | ✅ The suggestion returns the number, the slider bounds, and the reasoning behind it. **Price bounds are 5,000–12,000 piastres (EGP 50–120)** — matching your slider. |
| 25 | Publish review | `GET /commutes/{commute}` · `POST /commutes/{commute}/publish` | ✅ |
| 31 | Vehicle capture | `POST /driver/vehicles` · `POST /driver/vehicles/{vehicle}/documents` | ⚠️ **Missing: a second vehicle photo** (one `photo_path` column), **`Seat belts (all seats)` is not modelled anywhere**, and **`Air conditioning` is modelled per COMMUTE** (`rules.ac`), not per vehicle as your screen has it. Section 8. |

### Bookings and groups

| # | Screen | Endpoints | Status |
|---|---|---|---|
| 13 | **Seat request** | `POST /commutes/{commute}/seat-requests` · `POST /commutes/{commute}/pickup-preview` | ✅ The preview returns the detour for a point **without creating anything**, including the cumulative total for the whole run. |
| 14 | Request done | from the request's own response | ✅ |
| 19 | **Trips** | `GET /my-bookings` · `GET /driver/bookings` · `GET /seat-requests` · `GET /driver/seat-requests` · `GET /groups` | ⚠️ Five tabs × two roles, largely covered. **Missing: a per-trip earnings figure for the driver**, history with ratings (Phase 10), cancelled-with-refund state (Phase 8). |
| 20 | **Commute group** | `GET /groups/{group}` · `/members` · `/attendance` · `/absences` · `POST /groups/{group}/leave` · `POST /groups/{group}/pickup-request` | ⚠️ Overview, rules and members ✅. **The `calendar` tab is designed but was never built in the prototype — needs a decision.** The `payments` tab is Phase 8. |
| 28 | **Driver request review** | `GET /driver/seat-requests` · `POST .../approve` · `.../reject` · `.../waitlist` | ⚠️ Rating, trips, commitment, badges ✅; the waitlist button ✅. **Missing: `Fit for your route 96%`** — a match score from the *driver's* side, which today is only computed for a passenger's search. |
| 29 | Custom pickup approval | `GET /driver/pickup-requests` · `POST .../approve` · `.../suggest-alternative` · `.../reject` | ✅ Three buttons exactly as designed, fuzzing until approval, and the detour checked against the **whole run** rather than the single request. |
| 46 | Cancel today (sheet) | — | ⛔ **No endpoint cancels one day.** Pause/archive stop the whole commute. Section 8. |
| 47 | Direction (sheet) | — | 🚫 Local. |

### Live trip

| # | Screen | Endpoints | Status |
|---|---|---|---|
| 17 | **Active trip** | `GET /trips/{trip}` · `GET /trips/{trip}/location` · channel `private-trip.{sessionId}` | ✅ Status, timings, `isUnderway`, `lastLocationAt`, the live position, and **route deviation** (`deviationDetectedAt`). |
| 36 | **Pre-trip check-in** | `GET`/`POST /groups/{group}/attendance` | ✅ This screen is the **declared** attendance, not boarding confirmation. `I'm coming` = `coming`, `Can't make it` = `away`. **Missing: `5 min late`** — a third declared state that does not exist yet. Section 8. |
| 40 | No-show | `POST /trips/{trip}/no-show` · `POST /bookings/{booking}/dispute` | ✅ The driver records it; the passenger has 24 hours to contest it. |
| 41 | **Driver wait timer** | `GET`/`POST /trips/{trip}/wait-timers` · `POST .../extend` | ⚠️ **There is deliberately no endpoint that stops a timer** — "She's here" is the check-in and "Mark no-show & depart" is the no-show, and both close it. **Missing: `Stop 2 of 3`** (needs an ordered pickup sequence) and `notified twice`, Call, Message (Phase 12). |
| 39 | **Route changed** | `GET /trips/{trip}` | ⚠️ The deviation is **detected and reported** on the trip session. **Missing: the push that tells you about it** (Phase 12) — today you learn of it by reading the trip. |
| 43 · 45 | Driver cancelled → backup · cancel confirmation | — | ⛔ Phase 9, blocked on section 8 #3. |

### Account

| # | Screen | Endpoints | Status |
|---|---|---|---|
| 21 | **Profile** | `GET /auth/me` · `GET /account/stats` | ✅ The three numbers come from `user_stats`. **Every rate is `null` until it has been computed — show a dash, not a zero.** Role switching needs a product decision. |
| 22 | Notifications | `GET /notifications` · `PATCH /notifications/read` · `GET`/`PATCH /notifications/preferences` | ✅ Inbox, unread badge and switches — section 4.14. Push needs a provider decision (FCM) before phones actually buzz; the inbox works without it. Trip chat is the next slice. |
| 26 | **Privacy & blocked** | `GET`/`POST /safety/blocked-users` · `DELETE .../{user}` | ✅ Blocking works in **both directions** from the next search. 🔒 Nothing tells the blocked person, and the list is one-directional — who I blocked, never who blocked me. Privacy toggles themselves are yours/local. |
| 27 | Help & legal | `GET /account/consents` for versions | ⚠️ Static content is yours; the consent versions in force come from the API. |
| 35 | Account restricted | `GET /auth/me` → `user.suspension` · or the `ACCOUNT_SUSPENDED` (403) refusal's `error.fields` | ✅ Everything the screen shows now has a source: the reason as a sentence (`suspensionReason`, in the request's language), the **Reference** (`suspension.caseNumber`, e.g. `RF-482913`) and the **Expected update** (`suspension.reviewDueAt`). See the `user` object in 4.1. |

### Not built yet

| # | Screen | Phase |
|---|---|---|
| 37 | Rating | ✅ `GET /ratings/pending` · `POST /bookings/{booking}/rating` · `PATCH /ratings/{rating}` · `GET /ratings/mine`. 🔴 **Double-blind** — nothing tells you whether the other person has rated. Section 4.13. |
| 16 · 44 | **Safety centre · discreet alert** | ✅ `POST /sos` (with `isDiscreet`) · `POST /sos/{sos}/cancel` · `GET`/`POST /safety/emergency-contacts` · **Share Live Trip**: `POST /trips/{trip}/live-share` · `GET /safety/live-shares` · `DELETE /safety/live-shares/{share}`. Missing: Contact Support — section 7. |
| 32 | **Support / incident** | ⚠️ `POST /incidents` · `GET /incidents` · `GET /incidents/{incident}`. **Missing: evidence upload** — section 7. |
| 34 | Payment failed | ⛔ **8** — payments |
| 33 · 38 | Offline · location denied | 🚫 Device states |

---

## 4. Endpoint reference

Every field below is what you actually receive. Types are in the OpenAPI document; the column
here says what the value **means** and when it is `null`.

**One chapter per feature, and each one stands on its own.** 4.1 to 4.16 are the features; open the
one you are building and it has the endpoints, the shapes those endpoints return, and a card at the
top naming its screens and anything else it needs. The response shapes used to live together in
their own section at the end of the file, which meant reading two places a few hundred lines apart
to understand one call — so each shape now sits in the chapter that returns it. The only exception is
`person`, in **5**: it comes back from search, member lists, request reviews, attendance rows and
wait timers, so it belongs to no single feature.

Everything before this point is **global** — the envelope, auth, pagination, time, money and the
privacy rules in **1** apply to every chapter below, and are worth reading once before any of them.

### 4.0 Complete endpoint index

Every endpoint the API serves today, and where it is described below. `tests/Feature/MobileApiGuideTest.php`
fails if this list and the running routes ever disagree in either direction.

| Endpoint | Section |
|---|---|
| `POST /auth/logout` | 4.1 Auth and session |
| `GET /auth/me` | 4.1 Auth and session |
| `POST /auth/otp/request` | 4.1 Auth and session |
| `POST /auth/otp/verify` | 4.1 Auth and session |
| `POST /auth/session/refresh` | 4.1 Auth and session |
| `GET /account/consents` | 4.2 Account, devices, consents |
| `POST /account/consents` | 4.2 Account, devices, consents |
| `GET /account/devices` | 4.2 Account, devices, consents |
| `PATCH /account/devices/current/security` | 4.2 Account, devices, consents |
| `DELETE /account/devices/{device}/session` | 4.2 Account, devices, consents |
| `POST /account/initial-role` | 4.2 Account, devices, consents |
| `GET /account/onboarding-state` | 4.2 Account, devices, consents |
| `PATCH /account/preferences` | 4.2 Account, devices, consents |
| `PUT /account/profile/basic` | 4.2 Account, devices, consents |
| `GET /account/stats` | 4.2 Account, devices, consents |
| `GET /account/reliability` | 4.2 Account, devices, consents |
| `GET /users/{user}/public-profile` | 4.2 Account, devices, consents |
| `GET /users/{user}/avatar` | 4.2 Account, devices, consents |
| `GET /account/comfort-preferences` | 4.2 Account, devices, consents |
| `PATCH /account/comfort-preferences` | 4.2 Account, devices, consents |
| `GET /account/verifications` | 4.3 Verification |
| `GET /account/verifications/documents/{document}` | 4.3 Verification |
| `POST /account/verifications/organization` | 4.3 Verification |
| `POST /account/verifications/{type}/documents` | 4.3 Verification |
| `POST /account/verifications/{type}/submit` | 4.3 Verification |
| `GET /driver/application` | 4.4 Driver application and vehicles |
| `POST /driver/application` | 4.4 Driver application and vehicles |
| `DELETE /driver/application` | 4.4 Driver application and vehicles |
| `PUT /driver/application/licence` | 4.4 Driver application and vehicles |
| `POST /driver/application/submit` | 4.4 Driver application and vehicles |
| `GET /driver/eligibility` | 4.4 Driver application and vehicles |
| `GET /driver/verification` | 4.4 Driver application and vehicles |
| `GET /driver/vehicles` | 4.4 Driver application and vehicles |
| `POST /driver/vehicles` | 4.4 Driver application and vehicles |
| `PATCH /driver/vehicles/{vehicle}` | 4.4 Driver application and vehicles |
| `POST /driver/vehicles/{vehicle}/activate` | 4.4 Driver application and vehicles |
| `POST /driver/vehicles/{vehicle}/documents` | 4.4 Driver application and vehicles |
| `GET /commutes` | 4.5 Commutes (driver) |
| `POST /commutes` | 4.5 Commutes (driver) |
| `GET /commutes/{commute}` | 4.5 Commutes (driver) |
| `PATCH /commutes/{commute}` | 4.5 Commutes (driver) |
| `DELETE /commutes/{commute}` | 4.5 Commutes (driver) |
| `POST /commutes/{commute}/pause` | 4.5 Commutes (driver) |
| `POST /commutes/{commute}/pickup-preview` | 4.5 Commutes (driver) |
| `GET /commutes/{commute}/price-suggestion` | 4.5 Commutes (driver) |
| `POST /commutes/{commute}/publish` | 4.5 Commutes (driver) |
| `POST /commutes/{commute}/resume` | 4.5 Commutes (driver) |
| `PUT /commutes/{commute}/route` | 4.5 Commutes (driver) |
| `PUT /commutes/{commute}/schedule` | 4.5 Commutes (driver) |
| `POST /commutes/{commute}/seat-requests` | 4.5 Commutes (driver) |
| `GET /commute-demands` | 4.6 Search, demands, saved searches |
| `POST /commute-demands` | 4.6 Search, demands, saved searches |
| `DELETE /commute-demands/{demand}` | 4.6 Search, demands, saved searches |
| `GET /matches` | 4.6 Search, demands, saved searches |
| `GET /places` | 4.6 Search, demands, saved searches |
| `GET /saved-searches` | 4.6 Search, demands, saved searches |
| `POST /saved-searches` | 4.6 Search, demands, saved searches |
| `DELETE /saved-searches/{search}` | 4.6 Search, demands, saved searches |
| `GET /search/commutes` | 4.6 Search, demands, saved searches |
| `GET /bookings/{booking}` | 4.7 Seat requests and bookings |
| `PATCH /bookings/{booking}/cancel` | 4.7 Seat requests and bookings |
| `POST /bookings/{booking}/dispute` | 4.7 Seat requests and bookings |
| `GET /driver/bookings` | 4.7 Seat requests and bookings |
| `GET /driver/balance` | 4.16 Money |
| `GET /groups/{group}/statement` | 4.16 Money |
| `GET /driver/seat-requests` | 4.7 Seat requests and bookings |
| `POST /driver/seat-requests/{seatRequest}/approve` | 4.7 Seat requests and bookings |
| `POST /driver/seat-requests/{seatRequest}/reject` | 4.7 Seat requests and bookings |
| `POST /driver/seat-requests/{seatRequest}/waitlist` | 4.7 Seat requests and bookings |
| `POST /driver/seat-requests/{seatRequest}/counteroffer` | 4.7 Seat requests and bookings |
| `POST /seat-requests/{seatRequest}/counteroffer/accept` | 4.7 Seat requests and bookings |
| `POST /seat-requests/{seatRequest}/counteroffer/decline` | 4.7 Seat requests and bookings |
| `GET /driver/commutes/{commute}/waitlist` | 4.7 Seat requests and bookings |
| `POST /driver/commutes/{commute}/waitlist/offers` | 4.7 Seat requests and bookings |
| `DELETE /driver/seat-offers/{offer}` | 4.7 Seat requests and bookings |
| `GET /driver/commute-occurrences/{trip}/navigation-route` | 4.7 Seat requests and bookings |
| `POST /driver/commutes/{commute}/change-proposals` | 4.7 Seat requests and bookings |
| `GET /driver/change-proposals` | 4.7 Seat requests and bookings |
| `PATCH /driver/change-proposals/{proposal}` | 4.7 Seat requests and bookings |
| `DELETE /driver/change-proposals/{proposal}` | 4.7 Seat requests and bookings |
| `GET /change-proposals` | 4.7 Seat requests and bookings |
| `POST /change-proposals/{proposal}/respond` | 4.7 Seat requests and bookings |
| `GET /driver/seat-requests/{seatRequest}/route-preview` | 4.7 Seat requests and bookings |
| `GET /my-bookings` | 4.7 Seat requests and bookings |
| `GET /seat-offers` | 4.7 Seat requests and bookings |
| `POST /seat-offers/{offer}/accept` | 4.7 Seat requests and bookings |
| `GET /seat-requests` | 4.7 Seat requests and bookings |
| `DELETE /seat-requests/{seatRequest}` | 4.7 Seat requests and bookings |
| `POST /seat-requests/{seatRequest}/pickup-request` | 4.7 Seat requests and bookings |
| `GET /driver/pickup-requests` | 4.8 Pickup points |
| `POST /driver/pickup-requests/{pickupRequest}/approve` | 4.8 Pickup points |
| `POST /driver/pickup-requests/{pickupRequest}/reject` | 4.8 Pickup points |
| `POST /driver/pickup-requests/{pickupRequest}/suggest-alternative` | 4.8 Pickup points |
| `POST /groups/{group}/pickup-request` | 4.8 Pickup points |
| `GET /pickup-requests` | 4.8 Pickup points |
| `POST /pickup-requests/{pickupRequest}/accept-alternative` | 4.8 Pickup points |
| `GET /trips/{trip}` | 4.9 Live trip |
| `GET /trips/{trip}/attendance` | 4.9 Live trip |
| `POST /trips/{trip}/cancel` | 4.9 Live trip |
| `POST /trips/{trip}/check-in` | 4.9 Live trip |
| `POST /trips/{trip}/complete` | 4.9 Live trip |
| `GET /trips/{trip}/location` | 4.9 Live trip |
| `POST /trips/{trip}/location` | 4.9 Live trip |
| `POST /trips/{trip}/no-show` | 4.9 Live trip |
| `POST /trips/{trip}/drop-off` | 4.9 Live trip |
| `POST /trips/{trip}/still-driving` | 4.9 Live trip |
| `POST /bookings/{booking}/end-early` | 4.9 Live trip |
| `POST /trips/{trip}/start` | 4.9 Live trip |
| `POST /trips/{trip}/status` | 4.9 Live trip |
| `GET /trips/{trip}/wait-timers` | 4.9 Live trip |
| `POST /trips/{trip}/wait-timers` | 4.9 Live trip |
| `POST /trips/{trip}/wait-timers/{timer}/extend` | 4.9 Live trip |
| `GET /groups` | 4.10 Groups |
| `GET /groups/{group}` | 4.10 Groups |
| `GET /groups/{group}/absences` | 4.10 Groups |
| `POST /groups/{group}/absences` | 4.10 Groups |
| `DELETE /groups/{group}/absences/{absence}` | 4.10 Groups |
| `GET /groups/{group}/attendance` | 4.10 Groups |
| `POST /groups/{group}/attendance` | 4.10 Groups |
| `POST /groups/{group}/leave` | 4.10 Groups |
| `GET /groups/{group}/members` | 4.10 Groups |
| `GET /driver/home` | 4.11 Home aggregates |
| `GET /home` | 4.11 Home aggregates |
| `GET /ratings/pending` | 4.13 Ratings |
| `GET /ratings/about-me` | 4.13 Ratings |
| `POST /ratings/{rating}/report` | 4.13 Ratings |
| `GET /commutes/{commute}/reviews` | 4.13 Ratings |
| `GET /ratings/mine` | 4.13 Ratings |
| `POST /bookings/{booking}/rating` | 4.13 Ratings |
| `PATCH /ratings/{rating}` | 4.13 Ratings |
| `GET /incidents` | 4.12 Safety |
| `POST /incidents` | 4.12 Safety |
| `GET /incidents/{incident}` | 4.12 Safety |
| `GET /incidents/{incident}/evidence` | 4.12 Safety |
| `POST /incidents/{incident}/evidence` | 4.12 Safety |
| `GET /safety/blocked-users` | 4.12 Safety |
| `POST /safety/blocked-users` | 4.12 Safety |
| `DELETE /safety/blocked-users/{user}` | 4.12 Safety |
| `GET /safety/emergency-contacts` | 4.12 Safety |
| `POST /safety/emergency-contacts` | 4.12 Safety |
| `PATCH /safety/emergency-contacts/{contact}` | 4.12 Safety |
| `DELETE /safety/emergency-contacts/{contact}` | 4.12 Safety |
| `GET /safety/live-shares` | 4.12 Safety |
| `DELETE /safety/live-shares/{share}` | 4.12 Safety |
| `POST /trips/{trip}/live-share` | 4.12 Safety |
| `POST /sos` | 4.12 Safety |
| `POST /sos/{sos}/cancel` | 4.12 Safety |
| `GET /notifications` | 4.14 Notifications |
| `PATCH /notifications/read` | 4.14 Notifications |
| `GET /notifications/preferences` | 4.14 Notifications |
| `PATCH /notifications/preferences` | 4.14 Notifications |
| `GET /bookings/{booking}/messages` | 4.15 Trip chat |
| `POST /bookings/{booking}/messages` | 4.15 Trip chat |
| `POST /messages/{message}/report` | 4.15 Trip chat |

One route is deliberately **not** in this index and **not** in the OpenAPI document: `/s/{token}`,
the page a trusted contact opens. It is not under `/v1`, it takes no auth token, and it returns HTML
rather than the envelope — a web page for a person, not an endpoint for the app. You never call it;
you hand the URL the server gives you to the operating system's share sheet. See 4.12.

## 4.1 Auth and session

> Proving a phone number and keeping a session alive. Every other chapter assumes you have a token from here.
>
> **Screens** 1 · 5 · 6 · **Shapes** `session`, at the end of this chapter

#### `POST /auth/otp/request` — public

Send a code. **This is also registration** — a number nobody has used before gets an account on verify, so there is no separate register call.

**Request:** `phone` (any Egyptian format; Arabic-Indic digits are normalised), `purpose`
(`authentication` — the only accepted value; `pin_reset` is gone and now returns `422`),
`device.publicId` (a stable id you generate and keep),
`device.platform` (`ios`/`android`), `device.model`, `device.osVersion`, `device.appVersion`.

| Response field | Meaning |
|---|---|
| `challengeId` | Pass this back to `/verify`. Not a secret, but single-purpose. |
| `expiresInSeconds` | How long the code is valid. Show a countdown. |
| `resendAvailableInSeconds` | Do not enable "resend" before this elapses — it will 429. |
| `maskedPhone` | `+2010****678`. Safe to display; confirms we read the number they meant. |

#### `POST /auth/otp/verify` — public

| Response field | Meaning |
|---|---|
| `accountState` | `NEW` (just created) or `EXISTING`. Drives whether you show onboarding. |
| `nextStep` | **The screen to go to next**, decided by the server. Exactly five values: `PHONE_OTP`, `RESTRICTED_ACCOUNT`, `BASIC_PROFILE`, `ROLE_SELECTION`, `HOME` — in that order of precedence, explained in 2.1. Follow it rather than deciding yourself; it accounts for restriction and half-finished profiles you cannot see. 🔴 `ROLE_SELECTION` comes from `PUT /account/profile/basic` only — a cold start answers `BASIC_PROFILE`, see 2.1. 🔴 Verification is **not** one of them — it is a per-endpoint gate on the DRIVER surface, not a sign-up step. |
| `pinLength` | How many digits your local PIN entry should take (currently 4). Configurable server-side; do not hard-code. |
| `session` | See 4.1. |
| `user` | See 4.2. |
| `device` | See 4.2. |

#### `POST /auth/session/refresh` — public

**Request:** `refreshToken`.

Returns `session` and `nextStep`. **The old refresh token is dead the moment this succeeds.**
See 1.2 for why reuse revokes everything.

#### `GET /auth/me` — signed-in

Returns `user`, `device`, `nextStep`. **Reachable while suspended on purpose** — this is how the
app learns it is suspended. Call it on cold start.

#### `POST /auth/logout` — signed-in

Returns `{"revoked": true}`. Revokes this device's session only.

---


#### The `session` shape

| Field | Meaning |
|---|---|
| `accessToken` | Bearer token, 15 minutes. |
| `refreshToken` | 60 days, **single use**. |
| `accessExpiresAt` / `refreshExpiresAt` | UTC instants. Refresh a little before the first rather than waiting for a 401. |

## 4.2 Account, devices, consents

> The account itself: the profile, the role step, signed-in devices, consents and the restricted-account state.
>
> **Screens** 7 · 8 · 21 · 27 · 35 · **Shapes** `user`, `device` and `stats`, at the end of this chapter

#### `GET /account/devices` — signed-in, paginated

An array of `device` (4.2). `isCurrent` marks the one you are calling from.

#### `DELETE /account/devices/{device}/session` — signed-in

Returns `{"revoked": true}`. **Stays reachable while suspended**: revoking a stolen phone must not
depend on account standing.

#### `PATCH /account/devices/current/security` — signed-in

**Request:** `hasLocalPin`, `biometricEnabled`, `pushToken`.

🔒 `hasLocalPin` is a **flag, not a value**. Never send the PIN itself; there is no field for it
and there never will be. `pushToken` is stored encrypted.

#### `GET /account/consents` · `POST /account/consents` — signed-in

| Response field | Meaning |
|---|---|
| `currentVersions` | The version of each document in force right now, e.g. `{"terms": "1.0", "privacy": "1.0"}`. |
| `outstanding` | Which consents this person has **not** accepted at the current version. Non-empty means you must show the acceptance screen — bumping a version makes everyone's consent stale again, which is the whole reason we store a version rather than a boolean. |

**POST request:** `type` and `version` — the version you showed them.

#### `PUT /account/profile/basic` — signed-in, active account

**Request:**

| Field | | Values |
|---|---|---|
| `fullName` | **required unless you send `firstName`** | Arabic or Latin letters; spaces, `-`, `'`, `.` allowed; at least one letter. Length bounds are a platform setting. |
| `firstName` | **required unless you send `fullName`** | ✅ **The design collects two fields, and this is the one.** Also what other members see — your screen says so: *"Only your first name and photo are shown to other members."* |
| `lastName` | optional | Optional **even when sending `firstName`**, because a single name is a real name: somebody called only `Sara` must be able to finish signing up. |
| `gender` | required | `woman` · `man` · `prefer_not_to_say`. 🔒 Never returned by any endpoint. ⚠️ **Your screen offers Woman/Man only.** The third value is kept server-side on purpose: `prefer_not_to_say` is a real answer, and it is **not** the same as "not answered" — a half-registered woman with an unknown gender would be silently excluded from women-only commutes. If the screen will never send it, nothing breaks; existing accounts that hold it keep working. |
| `registeredRole` | **optional** | `driver` · `passenger` · `both`. 🔴 Was required. Send it to finish in one step and go straight to `HOME`; omit it and the response answers `nextStep: ROLE_SELECTION`, which you satisfy with `POST /account/initial-role`. Both paths are supported — nothing breaks if you keep sending it. |
| `dateOfBirth` | optional | **`YYYY-MM-DD`** only. Must make the person at least the minimum age (18 today — a platform setting, do not hard-code it) and no more than 120. |
| `publicFirstName` | optional | Up to 50 characters. Defaults to the first word of `fullName`. |
| `preferredLanguage` | optional | `ar` · `en` |
| `orgType` | optional | `work` · `university` · `school` |
| `organizationId` | optional | A 26-character organisation id. |

All values are **lower-case** in the request; the response returns statuses upper-case (see 4.2).
Idempotent — sending it again overwrites rather than failing.

Returns `user` and `nextStep`. See screen 7 above: the split-name question is open.

#### `POST /account/initial-role` — signed-in, active account, profile complete

The last step of onboarding. **Request:** `role` — `passenger` · `driver` · `both`.

**Safe to send again.** Enrolling twice is the same as enrolling once, so a retry after a dropped
connection needs no special handling — it will not create duplicate capabilities or move the
original timestamp.

Returns `user` (with `capabilities` filled in) and `nextStep`, which is `HOME` once this succeeds.

| Choosing | You get | Where to start |
|---|---|---|
| `passenger` | `["PASSENGER"]` | Passenger home |
| `driver` | `["DRIVER"]` | Driver walkthrough, then driver home |
| `both` | `["PASSENGER", "DRIVER"]` | **Passenger** home. The driver walkthrough runs the first time the driver side is opened, not now. |

> 🔒 **This is not driver approval.** Choosing `driver` records what the person came for and opens
> the driver surface so they can apply. Publishing a commute still needs an approved driver profile
> and a verified vehicle — see 4.4. Never treat a successful call here as permission to drive.

Calling this before the profile is complete answers `403`; finish `PUT /account/profile/basic`
first.

#### `GET /account/onboarding-state` — signed-in

Where an interrupted sign-up resumes. **Safe to call at launch**, before anything else is known,
and reachable **while suspended** — it is how the app learns it is restricted.

| Field | Means |
|---|---|
| `nextStep` | **The field to act on.** Computed by the same router as sign-in and refresh, so the three can never disagree. |
| `profileComplete` | Whether name and gender are saved. |
| `initialRoleSelected` | Whether the role step is done. Do not infer this from `capabilities` being empty. |
| `capabilities` | `["PASSENGER"]`, `["DRIVER"]`, both, or empty. |
| `onboardingSeen` | Whether the three intro slides were shown on any device. |
| `driverWalkthroughSeen` | Whether the driver walkthrough was shown. |
| `activeMode` | `passenger` · `driver` · `null` — which home was last open. |

The booleans are there so a screen can explain itself ("we kept your name"). Do not recombine them
into your own routing rule — `nextStep` already is that rule, and it accounts for restriction
states you cannot see.

#### `PATCH /account/preferences` — signed-in

UI state kept across devices. Every field is optional; send only what changed.

| Field | Values |
|---|---|
| `activeMode` | `passenger` · `driver` — which home to open next launch. |
| `onboardingSeen` | `true` once the intro slides have been shown. |
| `driverWalkthroughSeen` | `true` once the driver walkthrough has been shown. |
| `language` | `ar` · `en` — used for **notifications we send you**. The language of a *response* always follows that request's `Accept-Language` header instead. |
| `theme` | `light` · `dark` · `system` |

> 🔒 **This grants nothing.** `activeMode` picks a screen, not a permission. Setting it to `driver`
> without the driver capability stores the preference and every driver action is still refused —
> capability is checked on each protected request, never taken from a client flag.

The two `...Seen` flags are **one-way**. Sending `true` records the first time only, and sending
`false` does not clear them: the stored date is what tells support whether somebody had seen the
safety explanation before a given trip. Re-showing a walkthrough is a support action.

#### `GET /account/comfort-preferences` · `PATCH /account/comfort-preferences` — signed-in

The comfort chips you want remembered, so a passenger does not re-tick them on every search. **One
screen whose content changes with the active mode**, so the `GET` returns **both** modes in one
call and you can switch without a round trip.

```
GET  → { passenger: { chosen: true,  rules: ["nonsmoking","quiet"] },
         driver:    { chosen: false, rules: [] } }

PATCH  { mode: "passenger", rules: ["quiet", "nonsmoking"] }
     → the same both-modes shape back
```

| Field | Values |
|---|---|
| `mode` | `passenger` · `driver` — **required.** |
| `rules[]` | `nonsmoking` · `quiet` · `ac` · `nofood` · `luggage` · `front`. **Replaces** the saved list. |
| `chosen` | Whether she has ever answered for that mode. |

🔴 **These are the same six chips a commute offers and a search already accepts** — the exact values
you pass as `requiredRules` on `GET /search/commutes`, and the exact values a driver sets as the
rules on her own commute. That is the point: a saved list can be handed straight to a search with no
translation.

🔴 **`chosen: false` and `chosen: true, rules: []` are different, and the difference matters.** The
first is "never answered" — do not send it to a search. The second is "none of them", a real answer
she gave. Send `rules: []` to record that, or to clear a saved list.

*(This is a boolean rather than `null` vs `[]` on purpose: those two are one `?.` apart and read the
same in most languages, so the distinction would have been lost in the first client that touched
it.)*

**The same chip means opposite things in each mode**, which is why `mode` is required: as a
**passenger** you are saying what you would like, as a **driver** what your car offers.

> 🔒 **Saving a default filters nothing.** Your chips reach the matcher as `requiredRules`, where
> they are a **soft score** (`score.comfort`) and never an exclusion — a commute offering none of
> them is still returned, just lower. A test holds this. Do not tell somebody a commute was hidden
> because of a comfort chip.

**Reading never creates anything**, so a `GET` from somebody who has never opened the screen returns
two nulls.

#### `GET /users/{user}/public-profile` — active, profile complete

Another member's profile, as somebody who has never met her sees it. This is what a passenger opens
to check out the driver she is about to ride with.

| Field | Means |
|---|---|
| `id` · `publicFirstName` | 🔒 **The public handle, never the full name.** |
| `avatarPath` | The approved identity photo, or **`null`** — which is the normal case. Draw initials from `publicFirstName` when it is null. A photo only exists after a **driver's** identity is approved; a passenger-only account never has one. |
| `joinedMonth` | `YYYY-MM`. Month precision on purpose — see the note on reviews. |
| `trustLevel` · `verifiedLevels[]` | As in the `person` shape (**5**). |
| `isVerifiedDriver` | 🔴 **`true` only when a reviewer has actually approved her.** Applying is not approval, and this is the field a passenger uses to decide a stranger is safe. |
| `asPassenger` | `completedTrips`, `rating`, `onTimeRate`. |
| `asDriver` | The same three — **absent entirely** for somebody who does not drive, rather than zeroed. A `0` renders as a driver with a terrible record instead of a person who has never driven. Plus `onTimeMetricVersion` and `onTimeSampleSize`: **how** that percentage was computed and over how many runs. Two drivers at "88%" under different rules are not comparable, and 88% over nine runs is not the claim 88% over four hundred is — so do not render the figure as a confident badge without reading the sample size. Both are `null` on a rate computed before the rule was recorded. |
| `reviews` | `count`, and `items[]` — the five most recent, in the same shape as a commute's reviews: no reviewer, month precision. |

> 🔒 **What this never returns, for anybody:** full name, phone, email, date of birth, gender, any
> document or document path, staff notes, or any booking id. A test asserts each one separately.

**A suspended account answers `404`, and it is the same `404` as an id that never existed.** Do not
read anything into which you got — telling the two apart would make this an oracle for "this person
is suspended", which is nobody's business outside the safety team.

**No `area` field**, although the design's subtitle shows one (`Joined Mar 2025 · New Cairo`). The
only way to produce it today is to derive it from where she lives, and a district derived from
somebody's home address handed to a stranger who typed a user id is a rough answer to "where does
she live". If it is wanted, it should be a field she fills in and can leave blank.

#### `GET /users/{user}/avatar` — active, profile complete

**The image behind `avatarPath`.** This is the display-URL flow this file used to say was undecided.

Returns the JPEG inline (`image/jpeg`, `nosniff`, `Cache-Control: private, max-age=3600`), so you
can point an image view straight at it with your bearer token. **`404` means "draw initials"** — it
is the normal answer, not an error worth showing.

| You get a photo when | You get initials when |
|---|---|
| the person is an **approved driver** whose selfie a reviewer approved | everything else |

> 🔒 **A verified passenger still shows initials, and that is deliberate.** Many passengers verify
> their identity — it raises their trust level and some drivers ask for it — but her photo was
> collected to prove who she is to a reviewer, not to be published to strangers. Publishing it
> because she happened to verify would change what she handed it over for. A driver's photo is
> published because she is the one offering to put strangers in her car.
>
> 🔒 **There is no upload-photo endpoint, and will not be one.** The avatar is a consequence of an
> approved identity — which is exactly what makes it worth something to the passenger looking at
> it: the face on the profile is the face a human being checked against an ID. What you receive is a
> separate, re-encoded 256×256 image, never the identity document.

#### `GET /account/stats` — signed-in

The caller's **own** numbers only. See 4.2.

#### `GET /account/reliability` — signed-in

🔴 **Her own reliability standing, and every event behind it** (Feature 03 v5). v5 builds
enforcement on this number — a monthly admin review, a temporary suspension, a permanent ban — so
she has to be able to read it, and the reasons, **before** a review rather than after.

```
{ "asDriver":    { "score": 100, "events": [] },
  "asPassenger": { "score": 92,  "events": [ { "kind": "PASSENGER_CANCELLED_LATE", ... } ] },
  "windowDays": 180, "perfectScore": 100 }
```

| Field | Means |
|---|---|
| `asDriver` / `asPassenger` | **Two separate records.** One account is one person doing two things; a driver's cancellations never reach her passenger standing. |
| `score` | `0`–`perfectScore`, and **lower is worse**. Everybody starts at `perfectScore`: a new member has not failed anybody, and starting her at zero would make the number say she had. |
| `events[]` | `kind` · `weight` · `occurredAt` · `bookingId` / `tripId` / `commuteId` · `counts` · `reversedAt` · `reversedReason`. |
| `counts` | Whether this event still counts against the score. **Render a `counts: false` event struck through rather than hiding it** — that she appealed and won is the most favourable thing on her record. |
| `windowDays` | How far back the **score** looks. The events themselves are kept for ever. |

Event kinds today: `DRIVER_CANCELLED_BOOKING` · `DRIVER_CANCELLED_DAY` ·
`DRIVER_DISPLACED_PASSENGER` (one per passenger a pause or vehicle replacement displaced) ·
`PASSENGER_NO_SHOW` · `PASSENGER_CANCELLED_LATE`. **Expect new kinds**; show an unknown one as a
plain row.

> ⚠️ **The weights are placeholders pending a product decision.** v5 defers the fee brackets
> outright and says nothing about score weights. Their proportion to each other is deliberate; their
> absolute size is not policy anybody has agreed, so **do not build a UI that implies a threshold**
> ("you are 8 points from suspension").
>
> 🔒 **There is no endpoint for anybody else's.** The number is a judgement, and the path takes no
> parameter at all. It is not on public profiles and v5 does not ask for it to be.
>
> **Not built:** the appeal *submission* flow. The reversal exists — an approved appeal clears an
> event's impact while leaving it readable — but a driver cannot yet file one from the app, so that
> happens through support.

**What is deliberately absent:** `cancellationRate` on `GET /account/stats` is still always `null`.
It was published before anything computed it; the reliability score is the measure that now exists,
and the rate will either be derived or removed rather than left as a field that looks computed.

---


#### The `user` shape

| Field | Meaning |
|---|---|
| `id` | |
| `phone` / `phoneVerified` | 🔒 **Your own only.** Never present for anybody else. |
| `fullName` / `publicFirstName` | Same rule. |
| `profilePhotoPath` | Storage path, and **your own only**. ✅ The display flow is settled: fetch `GET /users/{user}/avatar`, which streams the image inline. Note this field is set as soon as your selfie is approved, while the **avatar endpoint publishes a photo only for an approved driver** — so a verified passenger has a path here and still shows initials to everybody, including herself. |
| `dateOfBirth`, `email` | Own only. |
| `accountStatus` | `ACTIVE` · `SUSPENDED` · `PENDING_DELETION` · `DELETED`. `PENDING_DELETION` is reserved for the 30-day grace after a deletion request (not built yet); treat any value you do not know as "not active". |
| `suspensionReason` | Present only while suspended: **one sentence, already in the request's language**, saying why in general terms (e.g. "A safety report is under review, so new bookings are paused."). Show it as written. It is a category, never the note staff wrote, and never names anybody. |
| `suspension` | Present only while suspended: `caseNumber` (the reference the member quotes, e.g. `RF-482913`), `reasonCode` (`safety_report` · `identity_check` · `payment_issue` · `policy_breach` — for an icon or analytics, not for wording), `suspendedAt`, `reviewDueAt` (the promised "expected update" time — show it as a time, and keep showing it if it has passed rather than hiding it). `caseNumber` can be `null` only for an account suspended before holds were recorded. |
| `profileStatus` | `NOT_STARTED` (signed in with a phone, basic profile not filled yet) · `BASIC_COMPLETE` (after `PUT /account/profile/basic` succeeds). **Until `BASIC_COMPLETE` the `active` tier refuses most endpoints** with `403 ACCOUNT_PROFILE_INCOMPLETE` — send the person to screen 7. *(An earlier version of this file said `PHONE_ONLY`; the API has always returned `NOT_STARTED`.)* |
| `capabilities[]` | 🔴 **What this account may actually do:** `["PASSENGER"]`, `["DRIVER"]`, both, or empty before the role step. **Drive your UI from this, not from `registeredRole`.** |
| `initialRoleSelected` | Whether the role step is done. Do not infer it from `capabilities` being empty — this is the field `nextStep` is derived from. |
| `registeredRole` | `passenger` · `driver` · `both` — **what they signed up as, which is history.** 🔴 It can legitimately disagree with `capabilities`: somebody who joined as a passenger and later added driving keeps `registeredRole: passenger` while holding both capabilities. Use it for analytics, never for deciding what to show. |
| `orgType` / `organizationId` | Their claimed workplace or university. |
| `preferredLanguage` | `ar` · `en`. |
| `trustLevel` | `0`–`4`, recomputed from the rows rather than incremented. |
| `outstandingConsents[]` | Which consents are stale at the current version. Non-empty means show the acceptance screen. |

#### The `device` shape

| Field | Meaning |
|---|---|
| `id`, `platform`, `model`, `osVersion`, `appVersion` | As registered. |
| `isTrusted` | |
| `hasLocalPin` | 🔒 A **flag**. The server never sees the PIN's value. |
| `biometricEnabled` | |
| `lastSeenAt`, `revokedAt` | |
| `isCurrent` | The device you are calling from. Do not offer "revoke" on it without a confirmation. |

#### The `stats` shape

| Field | Meaning |
|---|---|
| `completedTripsAsPassenger` / `completedTripsAsDriver` | Counts. |
| `ratingAsPassenger` / `ratingAsDriver` | `null` until Phase 10. |
| `onTimeRate` / `cancellationRate` | `0`–`100`, or `null` when not yet computed. |
| `noShowCount` | |
| `computedAt` | When these were last recalculated. `null` means never. |

🔴 **Every rate is `null` rather than `0` when it has not been computed.** Rendering 0% would tell
a brand-new member they are unreliable.

## 4.3 Verification

> Submitting identity evidence and reading back what was approved. A gate on other chapters, not a step in sign-up.
>
> **Screens** 15 · 30 · **Shapes** —

#### `GET /account/verifications` — signed-in

The Verification Centre read model. Also powers the home banner.

| Response field | Meaning |
|---|---|
| `level` | How many levels are approved right now. |
| `of` | How many there are in total. |
| `percentage` | `level / of` rounded. Use it directly rather than recomputing — we round the same way everywhere, and the home banner reads the same numbers. |
| `rows[]` | One per level, in display order. |

Each row:

| Field | Meaning |
|---|---|
| `type` | `phone`, `government_id`, `selfie`, `organization`. |
| `state` | `VERIFIED` · `REQUIRED` (not started) · `UNDER_REVIEW` · `ACTION_NEEDED`. |
| `actionNeededReason` | **The reviewer's own words**, when the state is `ACTION_NEEDED`. Show it verbatim — the server refuses to record a decision without one, so it is never empty when it matters. |
| `expectedReviewMinutes` | What to promise while waiting. **A target, not a guarantee** — nothing alerts if the queue runs past it. |
| `attemptsRemaining` | Submissions left before the level locks and needs an admin to reopen it. Warn at 1. |
| `expiresAt` | For a document with an expiry; `null` when it does not expire. An expired level stops counting towards `level`, so a badge can disappear without anybody doing anything. |

#### `POST /account/verifications/{type}/documents` — active, profile complete

**Request:** `kind` (which side or type of document), `file` (jpeg/jpg/png/webp).

| Response field | Meaning |
|---|---|
| `id` | The stored document. |
| `kind` | Echoed back. |
| `scanStatus` | The upload is scanned, stripped of metadata and re-encoded **before** it is stored. A rejection is a 422 with `DOCUMENT_REJECTED_BY_SCANNER`, not a status you poll. |
| `url` | A **short-lived signed URL** (about 2 minutes) so the person can see what they uploaded. Do not cache it; ask again. |
| `verification` | The whole centre payload again, so you can re-render progress without a second call. |

#### `POST /account/verifications/{type}/submit` — active, profile complete

Returns the centre payload. `VERIFICATION_NOT_SUBMITTABLE` if a required document is missing —
read `error.fields` for which.

#### `POST /account/verifications/organization` — active, profile complete

**Request:** `organizationId`, `email` (must be on that organisation's domain).

`ORGANIZATION_EMAIL_MISMATCH` if the domain does not match.

---

## 4.4 Driver application and vehicles

> Becoming a driver: the application, the licence, the vehicle and its documents. Needs 4.3 approved first.
>
> **Screens** 31 · **Shapes** —

All of these need `verified:government_id` except `eligibility`.

#### `GET /driver/verification` — active, profile complete

**The five-item checklist your driver card is drawn from.** Reachable **without** any verification,
on purpose: it is the screen that explains what is missing.

```
{ overall: "in_progress", completed: 3, of: 5, licenceExpiresAt: null,
  items: [ { key: "government_id",       status: "approved",     rejectionReason: null, expiresAt: "..." },
           { key: "selfie",              status: "not_started",  ... },
           { key: "driving_licence",     status: "action_needed", rejectionReason: "The licence photo is too blurry to read.", ... },
           { key: "vehicle_registration", status: "under_review", ... },
           { key: "vehicle_details",     status: "not_started",  ... } ] }
```

**The five items, in this order, and they can be done in any order:** `government_id` · `selfie` ·
`driving_licence` · `vehicle_registration` · `vehicle_details`.

| `status` | Means |
|---|---|
| `not_started` | Nothing submitted. |
| `in_progress` | Something uploaded, not submitted for review. |
| `under_review` | With a reviewer. |
| `approved` | Checked and accepted. |
| `action_needed` | Rejected — **`rejectionReason` says which document and why.** |
| `expired` | Was approved; the date has passed. |

🔴 **`rejectionReason` is per item, so you can say "replace only that document".** Rejecting one
item never clears the others — a test holds that. Do not send somebody back to re-upload all five.

| `overall` | Headline to show |
|---|---|
| `not_started` | "Complete driver verification" |
| `in_progress` | "Finish the remaining steps in any order, then submit for review" |
| `under_review` | "Driver verification in review" |
| `verified` | Approved. |
| `expiring` | "Licence expires soon" — ⚠️ **still verified.** Show the warning, keep the verified badge, and use `licenceExpiresAt` for the date. |
| `renewal_required` | Licence expired. Publishing and driving are paused. |
| `suspended` | Driver access suspended — **passenger mode still works** (see 4.1 on `nextStep`). |

**`suspended` outranks everything**, including an expired licence: it is the state a person decided,
and the one that explains why her commutes stopped.

> ⚠️ **`completed` can be 4 while `overall` is `verified`.** Approval today requires a valid licence
> and a reviewer's decision — it does **not** require a selfie — so an approved driver may still
> have an outstanding item. Render the count honestly; it is not a bug. We have flagged this as a
> gap in the approval gate rather than hiding it here.

#### `GET /driver/eligibility` — active, profile complete

**Deliberately outside the verification gate**: its whole job is to tell somebody what they are
missing, so gating it would leave them with a refusal and no explanation.

| Field | Meaning |
|---|---|
| `eligible` | Whether they can start an application at all. |
| `blockers[]` | Machine codes for what is missing, e.g. `GOVERNMENT_ID_NOT_VERIFIED`, `UNDER_MINIMUM_AGE`. Render your own copy per code. |

#### `GET` / `POST` / `DELETE /driver/application` — verified

`POST` creates or updates the draft; `DELETE` withdraws it.

| Field | Meaning |
|---|---|
| `status` | `DRAFT` · `PENDING_REVIEW` · `APPROVED` · `REJECTED` · `SUSPENDED` · `EXPIRED_DOCUMENTS`. |
| `licenceExpiry` | Date only. **Re-checked at the moment a run starts**, not only at approval — an expired licence stops that morning's trip. |
| `hasNationalId` / `hasLicenceNumber` | 🔒 **Booleans, not the values.** Both are stored encrypted and neither is ever returned. Your screen shows "on file", not the number. |
| `verifiedAt` | When a reviewer approved it. |
| `rejectionReason` | The reviewer's words. Show verbatim. |
| `missing[]` | What still has to be supplied before `/submit` will accept it. Drive your checklist from this rather than your own logic. |
| `vehicles[]` | The vehicle shape below. |

#### `PUT /driver/application/licence` — verified

**Request:** `nationalId` (14 digits), `licenceNumber`, `licenceExpiry` (must be in the future).

#### `POST /driver/application/submit` — verified

`DRIVER_APPLICATION_INCOMPLETE` lists what is missing in `error.fields`.

#### `GET` / `POST /driver/vehicles` · `PATCH /driver/vehicles/{vehicle}` — verified

**POST/PATCH request:** `make`, `model`, `year`, `colour`, `plateNumber`, `seats`,
`transmission`, `fuelType`.

| Field | Meaning |
|---|---|
| `plateNumber` | As entered. The normalised form we deduplicate on is internal and not returned. |
| `seats` | **Total including the driver.** Bookable seats are one fewer — a 5-seat car offers 4. |
| `isActive` | Only one vehicle per driver may be active at a time. |
| `status` | `PENDING` · `APPROVED` · `REJECTED`. A commute can only be published on an approved, active vehicle. |
| `documents[]` | `id`, `type`, `status`, `expiresAt`. **No path and no URL** — a document's storage path never appears in any payload. |

#### `POST /driver/vehicles/{vehicle}/activate` — verified

🔴 **This is how a driver replaces her car**, and three rules changed here.

| | |
|---|---|
| **The replacement must be approved first** | `422 VEHICLE_NOT_ALLOWED` otherwise. Add the vehicle, upload its documents, wait for a reviewer — then activate. An unapproved car cannot enter service. |
| **An approved driver can now add a vehicle** | She could not before: adding one answered `409 DRIVER_APPLICATION_LOCKED` once she was approved, so changing her car was impossible. The replacement starts `pending` and is reviewed on its own, while her current car keeps working. |
| **Her commutes move with her — her passengers do not** | 🔴 **This reversed on 2026-10-19.** Every future confirmed booking across **all** her commutes is **cancelled**, and each displaced passenger is put back on that commute's waiting list and told once, with a `vehicle_replaced_seat_cancelled` notification. The commutes themselves move to the new car, because a commute is her template and she would otherwise rebuild each one by hand. |

> 🔴 **Why the reversal.** Bookings used to be kept, with a `booking_vehicle_changed` notice naming
> the new plate. But a replacement car starts a **fresh verification**, and a run cannot start in a
> car no reviewer has approved — so the "kept" seat was one we already knew we could not honour, and
> she would have found out at the kerb. Cancelling tells her while she can still arrange something
> else, and puts her back on the list so she gets first look when the new car clears.
>
> **`booking_vehicle_changed` no longer exists.** Nothing produces it: there is no surviving booking
> to tell about. Remove any handling for it.

**It can still be refused for seats.** If a live commute advertises more seats than the replacement
has, the whole swap answers `422` and nothing moves — the seat count is published and people search
on it, so a commute selling four seats cannot run in a three-seater. Re-price or re-size the commute
first, or use a bigger car.

**The old vehicle is kept**, inactive, with its documents. It is what carried every completed
journey and the evidence that it was allowed to.

Makes this the active vehicle and deactivates the others, in one transaction — so there is never a
moment with none active, and never one with two.

#### `POST /driver/vehicles/{vehicle}/documents` — verified

**Request:** `type` (registration, insurance…), `file`, `expiresAt`.

---

## 4.5 Commutes (driver)

> A driver publishing a commute — the route, the weekly schedule, the price, and pausing or archiving it.
>
> **Screens** 24 · 25 · **Shapes** the commute offer, at the end of this chapter

#### `GET /commutes` · `GET /commutes/{commute}` — verified

The caller's own commutes. Full shape in 4.5.

#### `POST /commutes` — verified

**Request:** `commuteType` (`recurring`/`one_time`), `vehicleId`, `direction`
(`to_work`/`to_home`), `seatsTotal` (1–7), `pricePerSeatPiastres` (**5,000–12,000**),
`maxDetourMinutes` (0–30), `maxWalkMinutes` (0–30), `audience` (`women_only`/`any_verified`),
`minTrustLevel` (0–4), `allowsCustomPickup`.

Creates a **draft**. Route and schedule arrive through their own endpoints because each is a whole
thing that has to be validated together. `allowsCustomPickup` defaults to **false** — a commute
that accepts pickup proposals has to say so.

#### `PATCH /commutes/{commute}` — verified

Revises terms after publishing: price, seats, rules, audience. **Applies to days generated from
now on, never to a day somebody already booked.** Route and schedule lock once published
(`COMMUTE_NOT_EDITABLE`, 409).

#### `PUT /commutes/{commute}/route` — verified

**Request:** `origin{lat,lng,address,placeId}`, `destination{...}`, `pickups[]` (max 4),
`dropoffs[]` (max 2). A PUT because the route is replaced whole.

#### `PUT /commutes/{commute}/schedule` — verified

**Request:** `daysMask`, `departureTime` (`HH:MM:SS`, **local wall clock**), `timezone`,
`startDate`, `endDate` (**mandatory** — there are no infinite commutes).

**New: each weekday can differ.** Two optional fields, and **sending neither behaves exactly as
before** — one time, one price, one seat count for the whole week.

| Field | Means |
|---|---|
| `promisedArrivalTime` | `HH:MM:SS` local. When you promise to arrive, applied to every day unless a day overrides it. ⚠️ **Arriving later than this counts against the driver's on-time rate**, outside a five-minute tolerance. Omit it and no promise is recorded — and nothing is scored. |
| `perDay` | Per-day differences, **keyed by the day's bit**: `{"2": {"departureTime": "06:30:00", "pricePerSeatPiastres": 2500, "seatsTotal": 2}}` sets Sunday only. Each day accepts `departureTime`, `promisedArrivalTime`, `pricePerSeatPiastres`, `seatsTotal`. |

```
PUT { daysMask: 62, departureTime: "07:05:00", startDate: "...", endDate: "...",
      promisedArrivalTime: "08:00:00",
      perDay: { "2": { departureTime: "06:30:00", pricePerSeatPiastres: 2500 } } }
```

The bits are the same ones `daysMask` uses: **1** Sat · **2** Sun · **4** Mon · **8** Tue ·
**16** Wed · **32** Thu · **64** Fri.

Anything you leave out of a day follows the top-level values, and a day you do not mention runs like
the rest of the week. **Use it when a day genuinely differs** — an early Sunday, a cheaper Thursday,
one fewer seat on the day she takes her own child.

> 🔴 **Each day's trips are generated from that day's own values**, and the trip snapshots them. So a
> Sunday priced at 2500 produces Sunday occurrences at 2500 while the rest of the week keeps the
> commute's price — and a later price change still only affects **future** trips.
>
> Sending the whole thing again **replaces** the week; it never merges. A day you drop disappears.

#### `GET /commutes/{commute}/price-suggestion` — verified

The fair-price number for screen 24, with its reasoning.

| Field | Meaning |
|---|---|
| `suggestedPiastres` | What to pre-select. **Advice, never a rule** — anything between the bounds is accepted. |
| `minPiastres` / `maxPiastres` | The slider's ends. The ceiling is a legal boundary: a commute is shared cost, and a price far above the cost of driving would make this a taxi service. |
| `runCostPiastres` | What the whole journey costs to drive, before splitting. Show it as "full route contribution". |
| `assumedOccupancy` | How many passengers the split assumed. Show it beside the number so the driver sees the reasoning rather than trusting a figure. |
| `distanceKm` | The measured route length, one decimal. |

`COMMUTE_INCOMPLETE` (422) if the route has not been saved yet.

#### `POST /commutes/{commute}/publish` — verified

Computes the route, writes the search box, generates the first bookable days. Re-checks driver and
vehicle: `DRIVER_LICENCE_EXPIRED`, `COMMUTE_VEHICLE_UNAVAILABLE` and `COMMUTE_INCOMPLETE` are all
expected failures here.

**`DRIVER_DEBT_LIMIT_REACHED` (403)** — the driver owes more in platform fees from cash trips than the
limit (200 EGP by default; operations can change it). `error.fields.outstandingPiastres` and
`error.fields.capPiastres` give both numbers. **Her existing rides continue** — starting a run is never
refused for this. The same refusal applies to `resume`. Show the amount and how to settle it.

#### `POST /commutes/{commute}/pause` · `/resume` — verified

Pausing stops it being found, stops new days being generated, and 🔴 **cancels every future
confirmed booking on it**. Resume re-checks the licence and vehicle and catches up the missed days.

🔴 **This reversed on 2026-10-19 and it needs a confirmation step on your side.** Pausing used to
leave existing bookings alone — "a driver who pauses for a week and resumes finds her group intact".
It no longer does, and the screen must say so before she taps: *"This cancels N booked seats. They
go back on your waiting list."*

| | |
|---|---|
| **What is cancelled** | Confirmed bookings on days that are still `scheduled` and still in the future. Each becomes `CANCELLED_BY_DRIVER` with `cancelledReason: commute_paused`. |
| **A run already underway is NOT touched** | That is Cancel Active Trip, a different decision with its own fees. Pausing cannot reach into a journey people are sitting in. |
| **Where the passengers go** | Onto this commute's waiting list, keeping the terms she already approved, marked `displacedReason: commute_paused`. They are told once each, naming the number of days, with `commute_paused_seat_cancelled`. |
| **No fan-out** | The freed seats are **not** offered to anybody. An offer would announce "a seat just opened" for a journey that has stopped — and would land on the women who just lost theirs. |
| **Resume gives nothing back** | Cancelled seats never auto-restore. She offers them to the waiting members she chooses, with `POST /driver/commutes/{commute}/waitlist/offers`. |

> ⚠️ The same thing happens **without her tapping pause**: a commute is paused automatically when
> her licence lapses or her vehicle is suspended. The passenger's notification is deliberately
> passive — *"X's commute has been paused"* — because naming the real cause would tell her
> passengers about her documents.

#### `DELETE /commutes/{commute}` — verified

Archives. Nothing is deleted: history stays, and only future days nobody has taken are cancelled.

---


#### The commute-offer shape

| Field | Meaning |
|---|---|
| `id`, `status` | `DRAFT` · `PUBLISHED` · `PAUSED` · `ARCHIVED`. |
| `commuteType` | `recurring` · `one_time`. |
| `direction` | `to_work` · `to_home`. |
| `vehicleId`, `seatsTotal` | `seatsTotal` is bookable seats, already excluding the driver. |
| `pricePerSeatPiastres`, `currency` | Always `EGP` today. |
| `maxDetourMinutes` / `maxWalkMinutes` | The driver's limits. |
| `audience`, `minTrustLevel` | Who may join. |
| `allowsCustomPickup` | **Defaults to false.** |
| `routePolyline`, `routeDistanceMeters`, `routeDurationSeconds` | Computed at publish; `null` on a draft. **Only ever returned to the commute's own driver.** |
| `publishedAt`, `pausedAt`, `pausedReason`, `archivedAt` | `pausedReason` is `by_driver` · `vehicle_suspended` · `licence_expired` — the last two are automatic, so show the right copy. |
| `missing[]` | What still has to be done before it can be published. Drive the wizard from this. |
| `locations[]` | `{id, type, lat, lng, address, placeId, sequence}`; type is `origin` · `pickup` · `dropoff` · `destination`. |
| `schedule` | `{daysMask, departureTime, timezone, startDate, endDate, generatedUntil}`. `generatedUntil` is **how far ahead bookable days currently exist**, rolled forward daily — not the end of the commute. |
| `rules` | A map of `{key: bool}`. |
| `upcomingTrips[]` | The next generated days (4.7). |

## 4.6 Search, demands, saved searches

> A passenger finding a commute, saving a search, or registering demand where nothing runs yet.
>
> **Screens** 10 · 11 · 12 · 18 · 42 · **Shapes** the match result, at the end of this chapter · `person` in 5

#### `GET /search/commutes` — active, profile complete

**Query:** `origin[lat,lng]`, `destination[lat,lng]`, `daysMask`, `arrivalWindowStart`,
`arrivalWindowEnd` (`HH:MM:SS`), `maxWalkMinutes` (0–45), `maxDetourMinutes` (0–45),
`seatsNeeded`, `audiencePreference`, `budgetMonthlyPiastres`, `flexibilityMinutes` (0–60),
`wantsReturnTrip`, `rules[]`.

**Verification is NOT required here** — searching is how somebody decides whether Rafeeq is worth
verifying for. What they are *eligible* to see is decided inside the query from their own account:
a women-only commute is excluded for a non-woman before anything is scored, so you will never
receive a result they cannot then request.

Returns an array of match results — see 4.6, which explains the score breakdown.

#### `GET` / `POST` / `DELETE /commute-demands/{demand}` — active, profile complete

A saved request for when nothing matched. The platform tells the passenger when something turns up.

🔒 **No endpoint, at any access level, returns one passenger's demand to anybody else** — drivers
included. A driver publishes a journey; the platform finds the passenger. That asymmetry is what
stops Rafeeq being an auction on people.

| Field | Meaning |
|---|---|
| `status` | `ACTIVE` · `MATCHED` · `CANCELLED` · `EXPIRED`. |
| `budgetMonthlyPiastres` | **A monthly ceiling.** Per-ride affordability is derived from it and the days requested — do not treat it as a per-seat figure. |
| `flexibilityMinutes` | How much the arrival window can bend. |
| `wantsReturnTrip` | Recorded; return-leg matching itself is not built yet. |
| `expiresAt` | When it stops looking. |

`DELETE` cancels rather than deletes — the row is why a notification was sent, and erasing it
would leave notifications pointing at nothing.

#### `GET /matches` — active, profile complete, paginated

Commutes found for the caller's demands, newest first.

| Field | Meaning |
|---|---|
| `score` | The score **when the match was found**. The commute may have changed since; this records why the interruption was justified. |
| `originLabel` / `destinationLabel` | Where it goes. Origin and destination only — the intermediate stops are other passengers' meeting points. |
| `departureTimeLocal` / `timezone` | Local wall clock plus zone. |
| `pricePerSeatPiastres`, `audience`, `driver`, `vehicle` | As on a search result, with the same restraint. |
| `deliveredAt` / `clickedAt` | Set once notifications exist (Phase 12). `null` today. |

#### `GET` / `POST` / `DELETE /saved-searches/{search}` — active, profile complete

**POST request:** `title`, `filters{...}` — the same shape the search endpoint accepts.

Returns `id`, `title`, `filters`, `createdAt`. The dedup signature is internal and not returned.

#### `GET /places` — signed-in

**Query:** `q`, `lat`, `lng`, `radiusMetres` (100–50,000).

The shared place catalogue. Only needs a signed-in account — a passenger looking for a meeting
point has no reason to be verified first. Returns `id`, `name`, `nameAr`, `type`, `lat`, `lng`,
`city`, `district`.

---


#### The match-result shape

| Field | Meaning |
|---|---|
| `commuteId` | The commute. |
| `tripId` / `tripDate` | **The specific day this card matched on** — the soonest that fits. This is what you pass as `scheduledTripId` for a trial. |
| `departureAt` / `departureLocal` | The instant, and the wall clock to display. |
| `seatsAvailable` | On that day. |
| `pricePerSeatPiastres` | Snapshotted on the day — a later price change does not move it. |
| `audience` | `women_only` · `any_verified`. |
| `driver` | A `person` (5.1) plus `completedTrips` from the driver profile. |
| `vehicle` | `make`, `model`, `colour`. 🔒 **No plate** — a search result has not earned it. |
| `meetingPoint` | `{lat, lng, address, walkMinutes}` — where they would meet and how far they would walk. |
| `score` | The breakdown, below. |
| `detourMinutes` | What this passenger would add to the driver's run. |
| `rules` | A map, e.g. `{"nonsmoking": true, "quiet": true}`. |
| `otherDays[]` | The other days of the same commute that also fit — `{id, tripDate, departureAt, seatsAvailable}`. **One commute is one card**, not twenty near-identical ones. |

🔒 **No route polyline.** A passenger deciding between commutes needs the meeting point, the time
and the price. The full road a driver takes every day, handed to anyone who searches, is a movement
pattern nobody agreed to publish.

**The score is designed to be shown, not trusted.** A passenger choosing between two commutes
deserves to see *why* one ranked above the other:

| Component | Max | What it measures |
|---|---|---|
| `overlap` | 30 | How much of their route runs along the driver's. |
| `schedule` | 25 | How well the departure fits the arrival window. Decays over 30 minutes; half an hour out scores 0. |
| `detour` | 15 | How little the driver is inconvenienced. |
| `audience` | 10 | Whether it matches the audience they asked for. **Never decides eligibility** — that is settled in the query. |
| `comfort` | 10 | How many requested rules the commute actually has. Somebody who asked for nothing gets full marks, not zero. |
| `price` | 5 | Against the **monthly** budget, divided by the rides that budget has to cover. |
| `reliability` | 5 | From the driver's record. **A driver with no history gets full marks** — starting everyone at zero would mean they are never matched, so they never build the history that would let them be matched. |
| `total` | 100 | The parts always add up to the whole. |

## 4.7 Seat requests and bookings

> Asking for a seat, the driver answering, and the booking that results — including cancelling and disputing one.
>
> **Screens** 13 · 14 · 19 · 28 · 46 · **Shapes** the seat request, the scheduled trip and the booking, at the end of this chapter

#### `POST /commutes/{commute}/seat-requests` — active, profile complete

**Request:** `commitment` (`trial`/`recurring`), `scheduledTripId` (**required for a trial**),
`requestedDaysMask` (**required for recurring**), `seats` (1–4), **`pickup` (required)**,
**`dropoff` (required)**, `meetingPreference` (`gate`/`street`/`landmark`/`custom`),
`customPickupPlaceId`, `introMessage` (max 500), `paymentType` (`cash`/`online`), `agreedToRules`
(**must be true**).

🔴 **`pickup` and `dropoff` are new and both are required.** This is the biggest change to this
endpoint. A request used to carry only `meetingPreference` — a hint about what *kind* of place she
would rather meet at — and no drop-off at all: the drop-off was assumed to be the end of the
driver's route. So a driver was approving somebody without knowing where to collect her, and
carrying her without knowing where she was going.

Give each point in one of two ways:

```
{ "pickup":  { "suggestionId": "01h..." },
  "dropoff": { "lat": 30.0684, "lng": 31.1015, "label": "Mehwar, Sheikh Zayed" } }
```

| Form | Use it when |
|---|---|
| `{ suggestionId }` | She tapped one of the driver's advertised meeting points — the `pickups` / `dropoffs` on `GET /commutes/{commute}`. |
| `{ lat, lng, label }` | She dropped her own pin. `label` is required: a pin with no name is unreadable on the driver's review screen and in a notification. |

Mix them freely — a suggested pickup with a custom drop-off is normal. Each point comes back with a
`source` of `driver_suggestion` or `custom_map`, and **show that difference**: a point the driver
advertised is one she has already agreed to stop at; a pin the passenger dropped is a proposal she
has not seen before.

> ⚠️ **A commute with no suggested points is still requestable.** Many are: a driver may publish
> with only a start and an end. Never block the screen waiting for suggestions to choose from —
> fall back to the map.
>
> ⚠️ **The driver's own start and end are NOT choosable as suggestions.** They are her journey
> endpoints, not places she offered to collect strangers. If she wants to meet there, drop a pin —
> it will be recorded as `custom_map`, which is the truth.

`agreedToRules` is mandatory and the moment is stored, not a flag — in a dispute what matters is
when they agreed. Expected refusals: `SEAT_UNAVAILABLE`, `BOOKING_DEADLINE_PASSED` (bookings close
at 21:00 the night before), `BOOKING_ALREADY_REQUESTED` (one open request per commute),
`BOOKING_OWN_COMMUTE`, `BOOKING_RECURRING_DAYS_NOT_OFFERED`, and four about the two points:

| Code | Means |
|---|---|
| `SEAT_REQUEST_POINTS_SAME` | The two pins are within 200 m of each other. |
| `SEAT_REQUEST_POINTS_OUT_OF_ORDER` | This commute reaches her drop-off before her pickup — they are the wrong way round. |
| `SEAT_REQUEST_SUGGESTION_UNKNOWN` | That `suggestionId` is not one this commute advertises. |
| `PICKUP_DETOUR_TOO_LONG` | The **pair together** takes the driver further out of her way than her `maxDetourMinutes`. `error.fields` carries `addedMinutes`, `runTotalMinutes` and `maxDetourMinutes`. |

> 🔴 The detour is measured **by us**, for both points at once, against the route as published —
> never accepted from the request. Use `POST /commutes/{commute}/pickup-preview` to show the cost
> beside the pin while she is still choosing.

#### `GET /seat-requests` — active, profile complete, paginated

The caller's own requests. See 4.7 for the shape.

#### `DELETE /seat-requests/{seatRequest}` — active, profile complete

Withdraws it, freeing the one-open-request slot.

#### `GET /driver/seat-requests` — active, profile complete, paginated

The driver's inbox, **oldest first** — the person who has waited longest is the one whose answer
is most overdue, and a request expires after 48 hours. Each carries a `passenger` (5.1).

#### `POST /driver/seat-requests/{seatRequest}/approve` — active, profile complete

One call handles both a trial and a recurring membership.

| Field | Meaning |
|---|---|
| `seatRequest` | The request, now `APPROVED`. |
| `membership` | The group member row created or upgraded. |
| `bookings[]` | **A trial produces one; a recurring membership produces one per committed day.** Do not assume a single booking. |
| `seatedDays` | How many days were successfully booked. |
| `skippedDays` | Days that could not be booked because they were already full. **Show these** — a member who thinks they have five days and has four will find out on the wrong morning. |

#### `POST /driver/seat-requests/{seatRequest}/reject` — active, profile complete

**Request:** `responseNote` (optional).

#### `POST /driver/seat-requests/{seatRequest}/waitlist` — active, profile complete

Moves the request to the waitlist and sets `waitlistPosition`.

🔴 **This changed, and your screen 20 was right all along.** It used to promote **one** person into
the driver's inbox as a fresh decision. Now, when a seat frees, it is **offered to everybody
eligible at once** and the **first acceptance wins** — because everybody on the list is already
driver-approved: she approved them when she waitlisted them. So there is no second decision to wait
for, and "waitlist fills empty seats automatically" is now accurate.

⚠️ **`waitlistPosition` is an ESTIMATE, not a queue.** Keep showing it — the design's
"Position 1 of 2 · Est. seat within ~1 week" is deliberate — but **never tell her it entitles her to
the next seat.** The person at position 2 can and does win; position only decides who is *notified*
first. Nothing in the allocation path reads it.

#### The 16-hour schedule lock

🔴 **A day stops changing 16 hours before it departs** (Feature 03 v5). Two things follow, and both
can refuse a call you already built.

| | |
|---|---|
| **`POST /driver/pickup-requests/{pickupRequest}/approve`** and **`/pickup-requests/{pickupRequest}/accept-alternative`** | Answer **`409 COMMUTE_SCHEDULE_LOCKED`** when any affected day is inside the window. `error.fields` carries `lockedFrom` and `lockHours`. Tomorrow morning's meeting point stops moving 16 hours before she stands on it. |
| **Approving or accepting a seat** | Answers **`409 BOOKING_BREAKS_FROZEN_PROMISE`** when the new rider's two points would land the run past the arrival the passengers already on board were promised. `error.fields` carries `promisedArrivalAt`, `projectedArrivalAt` and `lockedFrom`. |

> ⚠️ **What the lock does NOT change, because it was already stricter.** A published commute's route
> and schedule cannot be edited at all — `PUT /commutes/{commute}/route` and `/schedule` answer
> `409 COMMUTE_NOT_EDITABLE` on anything that is not a draft, and always have. People book specific
> days on the strength of both. So there is no "16 hours before" for those: they are frozen from
> publish.
>
> A locked day **nobody has booked** is not locked for these purposes. The lock protects the people
> who planned around the day, so a driver editing her own empty morning is not stopped.
>
> A day with **no `promisedArrivalTime`** is judged on capacity and detour alone — no promise, no
> breach. Nothing is refused against null.

**The way a published commute DOES change** is the change-proposal flow below, which exists for
exactly this: every affected passenger has to say yes.

#### Change proposals — the only way a published commute's times or stops move

🔴 `PUT /commutes/{commute}/route` and `/schedule` answer `409 COMMUTE_NOT_EDITABLE` on anything
that is not a draft, and that has not changed. To move a **published** commute's departure, promised
arrival or advertised pickup points, the driver sends a **proposal** and every affected confirmed
passenger must accept it (Feature 03 v5 §6).

| Rule | What it means for your screens |
|---|---|
| **One combined request** | Several fields move together because they only make sense together. There is **no partial accept** — the passenger gets `Accept all` / `Reject`. |
| **Silence changes nothing** | A passenger who never answers blocks it indefinitely, until the proposal expires. Do not imply a timeout approves it. |
| **One refusal ends it** | The proposal goes to `REJECTED`. The driver can revise and ask again. |
| **A revision resets every answer** | `version` increments. An answer counts only for the version it was given on — **warn the driver before she revises**, and re-read before letting a passenger answer. |
| **The last yes applies it immediately** | There is no separate Apply step. The response that completes unanimity returns `status: APPLIED`. |
| **Expires at the affected morning's departure** | `expiresAt`. A proposal about this morning is meaningless once the car has gone. |

**`POST /driver/commutes/{commute}/change-proposals`** — **Request:** `scheduledTripId`, `scope`
(`only_this_occurrence` · `all_future_on_this_weekday`), optional `effectiveFrom`, and at least one
of `departureTime` · `promisedArrivalTime` · `pickupPoints[]` (`{lat, lng, address}`).

| Refusal | When |
|---|---|
| `409 CHANGE_PROPOSAL_ALREADY_OPEN` | She already has one out. Edit that one. |
| `409 CHANGE_PROPOSAL_NOBODY_AFFECTED` | Nobody is confirmed on those days — there is nobody to ask, so there is no proposal to make. |
| `422` | Nothing actually changed, or `pickupPoints` with `only_this_occurrence`: the commute has **one** advertised route across its weekdays, so moving the stops applies to all future occurrences of that weekday. |

**`GET /driver/change-proposals`** — her pending view. Adds `affectedCount`, `acceptedCount`, and
`passengers[]` with `bookingId`, `passengerFirstName` and `response` of `ACCEPTED` ·
`AWAITING_RESPONSE` · `REJECTED` · `WITHDRAWN`.

**`PATCH /driver/change-proposals/{proposal}`** — revise. Same body. Bumps `version` and **resets
every answer**, including ones already given.

**`DELETE /driver/change-proposals/{proposal}`** — take it back.

**`GET /change-proposals`** — what the caller is being asked to agree to, with `changes` against
`previous` for the highlighted comparison, her own `bookingId`, and `myResponse`.

**`POST /change-proposals/{proposal}/respond`** — **Request:** `response` of `accepted` ·
`rejected` · `withdrawn` (take an acceptance back before it applies). `404` if she has no confirmed
booking on an affected day.

> 🔒 **A passenger is never told how the others answered.** No roster, no `acceptedCount`, no
> affected count on her view. She is being asked about her own morning; who else is in the car and
> whether they agreed is not a fact about her journey, and showing it would turn a schedule change
> into social pressure from people she may not have met.
>
> 🔴 **`all_future_on_this_weekday` clears the whole waiting list once applied** — and notifies
> everybody on it (`waitlist_cleared_by_change`) to apply again, their requests moving to
> `TERMS_CHANGED`. Even the ones that still look compatible: we cannot tell from a 07:05 application
> whether 07:30 works for her. **Nothing is cleared if the proposal is rejected or never applies.**

#### `POST /driver/seat-requests/{seatRequest}/counteroffer` — active, profile complete

🔴 **The driver's third answer: "I can take you, from somewhere else."** Feature 03 v5 §2 makes
Accept / Reject / **Counteroffer** her three explicit choices.

**Request:** `pickup` and/or `dropoff` — **at least one**, each in the same two forms as a seat
request (`{suggestionId}` or `{lat, lng, label}`) — plus an optional `note` (max 255) she will read
before deciding.

```
POST /driver/seat-requests/{seatRequest}/counteroffer
{ "pickup": { "lat": 30.0630, "lng": 31.3300, "label": "Petrol station, not the side street" },
  "note": "I can't get into that street at 7am." }
```

| | |
|---|---|
| **Nothing is committed** | The request becomes `COUNTEROFFERED`. The agreed points do **not** move, no booking is created, and no seat is taken. Only the end she actually moved is recorded — counter the drop-off and `counteroffer.pickup` stays null, so the passenger is shown one decision instead of being asked to re-agree to her own choice. |
| **It is still her one open request** | She cannot file another on this commute while the conversation is live. |
| **It expires** | On the usual request window, restarted from the counteroffer. A proposal nobody answers closes. |
| **Pending only** | A waitlisted request answers `409 SEAT_REQUEST_NOT_PENDING`: she is on a list because there was no seat, and countering her meeting point would answer a question nobody asked. |

The proposed pair is judged by the same rules as a passenger's own — `SEAT_REQUEST_POINTS_SAME`,
`SEAT_REQUEST_POINTS_OUT_OF_ORDER`, `SEAT_REQUEST_SUGGESTION_UNKNOWN`, `PICKUP_DETOUR_TOO_LONG`. A
driver can propose something unworkable too, and the check protects the riders she has already
promised. A body that moves nothing — no points, or her echoing the passenger's own coordinates back
— answers `422`.

#### `POST /seat-requests/{seatRequest}/counteroffer/accept` — active, profile complete

The passenger agreeing. **This confirms the seat** and returns the approval (same shape as
`/approve`, `201`): the driver proposed the place, so she has already said yes on those terms.

> 🔴 **It can still fail with `409 SEAT_UNAVAILABLE`.** Her counteroffer promised a **meeting
> place**, never that a seat would still be free — somebody else can take the last one while the
> conversation is open. Her request survives in that case, so she is not burned.
>
> The agreed point comes back with `source: driver_counteroffer`, not `custom_map`. A dispute about
> somebody waiting at the wrong corner turns on whose idea the corner was.

#### `POST /seat-requests/{seatRequest}/counteroffer/decline` — active, profile complete

The passenger refusing. The request closes as **`COUNTEROFFER_DECLINED`** — its own status, not
`REJECTED` (which would read as the driver refusing her) and not `WITHDRAWN` (which would read as
backing out before an answer). Both of those histories are read by somebody deciding whether to
accept the other person later.

It does **not** return to `PENDING`: the driver would see the same two points she had just declined
and the pair would loop.

> 🔒 **Chat is not consent.** v5 says so in terms, and it is why this is three endpoints rather than
> a message thread. Two people can agree a corner in chat in ten seconds and nothing in the system
> would know — the driver's route would still point at the original, the attendance geofence would
> still measure against it, and a dispute would be settled against a record that disagreed with both
> of them. Never treat a chat message as the agreement.

#### `GET /seat-offers` — active, profile complete, paginated

Seats she has been offered while waiting. Open ones first, then history.

| Field | Means |
|---|---|
| `status` | `OPEN` · `ACCEPTED` · `TAKEN_BY_OTHER` · `INELIGIBLE` · `CLOSED` |
| `tripId` · `seatRequestId` | Which day, and which waiting request it came from. |
| `trip` | `date`, `departureAt`, `departureLocal`, `pricePiastres` — so the list reads without a second call. A seat on Wednesday is a different decision from a seat tomorrow. |
| `offeredAt` · `closedAt` | |

🔴 **`TAKEN_BY_OTHER` and `CLOSED` are different and the wording matters.** The first means somebody
was quicker; the second that the seat stopped existing (the day was cancelled, or the driver filled
it herself). Show them differently — otherwise somebody believes she keeps losing races she never
ran.

> 🔒 There is **no** field here for her position, how many others were offered it, or who they are.
> A waiting list is a set of people who all want the same commute, and telling each of them who they
> are racing would turn a seat into a social problem.

#### `POST /seat-offers/{offer}/accept` — active, profile complete

Takes the seat. Returns the **booking** on success — no further driver approval.

**First acceptance wins, decided by server transaction order.** Not by whose tap was first: who
reaches us first depends on her signal and her distance from our servers, which is not a fair basis
for giving away a seat.

If she was slower: **`409 SEAT_OFFER_CLOSED`**, with `error.fields.status` saying which
(`taken_by_other`, `closed`, `ineligible`). **Show "somebody was quicker", never "something went
wrong"** — it is a state refusal, not a fault of hers.

Somebody else's offer answers **`404`**, not `403`. A `403` would confirm the id exists.

**Switching a seat — the same call, three optional fields.** This is how a passenger moves from one
commute to another without ever holding two seats or losing both.

```
POST /seat-offers/{offer}/accept
{ switchFromBookingId: "...", switchReason: "voluntary" | "driver_delay", joinOldWaitlist: true }
```

| Field | Means |
|---|---|
| `switchFromBookingId` | The confirmed booking she is giving up. **One transaction**, so there is no instant where both are confirmed and no way to end up with neither. Omit for a plain acceptance. |
| `switchReason` | `voluntary` applies the **normal cancellation policy** to the seat she leaves. `driver_delay` carries **no cancellation fee** and records the old booking as cancelled **by the driver** — so a driver running late does not land on the passenger's record. |
| `joinOldWaitlist` | `true` rejoins the old driver's waiting list for that day. **She does not approve it again** — the passenger already held a seat in that car. It **reserves nothing**: she waits for an offer like anybody else, and the seat may go to somebody quicker. |

> 🔴 **It never leaves two overlapping confirmed bookings** — if she already holds a confirmed seat
> on the target day, the call answers `409 BOOKING_ALREADY_BOOKED` and nothing moves.
>
> 🔒 The booking in `switchFromBookingId` must be **hers**. Somebody else's answers `404`.

**Not built:** a standalone switch endpoint on the booking itself (v3 proposes one; this is the offer path instead), and **recurring/future-weekday scope**.
Only a single date switches today. A half-built recurring scope would silently release days she
still wanted, so it waits rather than shipping partly done.

#### `GET /driver/commutes/{commute}/waitlist` — active, profile complete, paginated

Her own waiting list, as the screen where she chooses who to offer a seat to. **404** for anybody
else's commute.

| Field | Means |
|---|---|
| `seatRequestId` · `commuteId` · `tripId` | `tripId` is set only for a trial, which wants one specific day. |
| `commitment` | `trial` or `recurring` — **show it.** A recurring member is being invited back into the group; a trial to one morning. |
| `requestedDaysMask` · `seats` | |
| `waitlistPosition` · `joinedWaitlistAt` | Position is an estimate, as everywhere else. |
| `isPreviousPassenger` | 🔴 **The "Previous Passenger" badge.** True for somebody who has held a seat in this car before — including anybody the platform displaced when the commute was paused or the vehicle replaced. |
| `displacedAt` · `displacedReason` | Non-null when the *platform* put her back on the list, not her own choice. `commute_paused` or `vehicle_replaced`. |
| `hasOtherConfirmedCommute` | A bare boolean: she already holds a confirmed future seat somewhere. **Nothing more is published** — not whose commute, not which days. It is there so the driver is not surprised when somebody declines, and nothing is blocked on it. |
| `introMessage` · `meetingPreference` · `paymentType` | |
| `passenger` | 5.1. |

#### `POST /driver/commutes/{commute}/waitlist/offers` — active, profile complete

**Request:** either `all: true`, or `seatRequestIds: [...]` (1–100). **Exactly one of the two** — a
body with neither answers `422`, and so does a body with both.

🔴 **This is the one place a driver hands out seats herself.** Everywhere else, a seat that frees is
offered to everybody waiting automatically and the first acceptance wins. Resuming a paused commute
is different: she is refilling a whole car for weeks of mornings, and she is allowed to favour the
passengers she already had. Use `isPreviousPassenger` above to let her.

**Response:** `membersOffered` and `offersCreated`. One member can be offered several days — a
recurring waiter resumed mid-month is eligible for every remaining weekday she committed to, and she
gets **one** notification for the set, not one per day.

Her choice decides **who may race, not who wins**: each day is still settled by server transaction
order when somebody accepts. Refused with `409 COMMUTE_NOT_EDITABLE` while the commute is still
paused — an offer on a commute that is not running invites somebody to a journey that will not
happen. Ids that are not on her own list are **silently ignored** rather than refused; telling her
"that id is not yours" would answer a question about another driver's commute.

#### `DELETE /driver/seat-offers/{offer}` — active, profile complete

Takes back an offer **nobody has accepted**. Returns the offer with `status: WITHDRAWN`.

Once accepted it is a booking, and taking that back is a driver cancellation with its fees and
reliability impact — not an undo. So an accepted or closed offer answers `409 SEAT_OFFER_CLOSED`.

#### `GET /driver/commute-occurrences/{trip}/navigation-route` — active, profile complete

🔴 **The driver's map, and the `View Route in Google Maps` button.** Her start, every confirmed
passenger's two agreed points in the order she reaches them, and her end.

**The point count is `2 + 2N`.** Start + End = **2**. One confirmed passenger = **4**. Two = **6**.
Published as `pointCount` so you do not have to count, and so the screen can say "4 stops".

> 🔴 **Do NOT assume the order is `Start → A pickup → A drop-off → B pickup → B drop-off → End`.**
> It frequently is not. Stops are ordered by how far along the driver's route each point sits, so
> shared journeys **interleave** — B may be collected and set down inside A's ride. The one
> invariant you can rely on is that each passenger's pickup precedes **her own** drop-off. Render
> `stops` in the order given; never re-sort it.
>
> Nothing is deduplicated. Two riders collected at the same corner are two stops, because they are
> two people to wait for and two attendance decisions.

| Field | Means |
|---|---|
| `status` | `CONFIRMED` here. The preview endpoint below returns `PREVIEW_NOT_CONFIRMED`. |
| `stops[].kind` | `DRIVER_START` · `PASSENGER_PICKUP` · `PASSENGER_DROPOFF` · `DRIVER_END`. The driver's two endpoints are **not** passenger stops — a rider collected at the driver's start point still has her own `PASSENGER_PICKUP` there. |
| `stops[].sequence` | 0-based driving order. |
| `stops[].bookingId` · `passengerFirstName` | Null on the driver's own endpoints. The first name, as everywhere else — never a full name. |
| `stops[].lat` · `lng` · `label` | Exact. She has to drive there, and she accepted every one of these. |
| `stops[].etaAt` | When she is expected. **Measured**, not apportioned: each leg is routed and the ETAs are the running sum. Null when the day has no departure time or a leg could not be routed — we do not invent one. |
| `stops[].legDurationSeconds` · `legDistanceMeters` | The leg **into** this stop. Zero on the first. They sum to `durationSeconds` / `distanceMeters`. |
| `stops[].reachedAt` | Null means still to come. This is the one value that is not derived, and it survives every rebuild. |
| `polyline` · `distanceMeters` · `durationSeconds` | The whole run, summed from the legs. |
| `googleMapsDirectionsUrl` | Open this for the external map. **Can be null** — see below. |
| `exceedsWaypointLimit` · `waypointLimit` · `segments` | The fallback. |

> 🔴 **`googleMapsDirectionsUrl` is null when the itinerary is longer than one Maps link can carry**
> (`waypointLimit` intermediate stops, 9 today). We deliberately do **not** build a link Maps would
> silently truncate: a driver who opens a route that looks complete and is missing two passengers
> finds out when somebody is still standing on a kerb. Use `segments` instead — consecutive links,
> each inside the limit, **each starting where the previous one ended** so following them in order
> drives the whole route. When it fits, `segments` holds exactly that one URL.
>
> ⚠️ Google Maps validates **nothing**. It is a map with a route on it. Every rule about whether
> that route is acceptable — the detour, the promises to existing riders, the schedule — was decided
> by us before the link existed. Do not present it as approval.

The itinerary is recomputed when the bookings on the day change, so this can be slower on the first
read after an accept or a cancellation. Call it again after either.

#### `GET /driver/seat-requests/{seatRequest}/route-preview` — active, profile complete

What the run **would** look like if she accepted this pending request: the confirmed stops plus the
request's two points. Same shape as above, with two differences.

| | |
|---|---|
| `status` | 🔴 **`PREVIEW_NOT_CONFIRMED`.** Show it as a proposal. |
| `stops[].isProposed` | True on exactly the two points being proposed. `stops[].id` is null throughout — these rows do not exist, so there is nothing to act on. |

Nothing is written. The official itinerary stays at its current point count until she accepts —
four points can appear here while `navigation-route` still returns two.

> 🔒 **Both of these are driver-only, and a passenger gets `404`.** The payload is every other
> rider's pickup and drop-off: front doors and workplaces. A passenger's own points come back on her
> own booking and her own seat request, and nowhere else.

#### `GET /my-bookings` — active, profile complete, paginated

The caller's seats, newest first. See 4.7.

#### `GET /driver/bookings` — active, profile complete, paginated

Every seat the caller has approved. The largest list in the API.

#### `GET /bookings/{booking}` — active, profile complete

Visible to its passenger or its driver; **404 for anybody else**, because a 403 would confirm the
id exists.

#### `PATCH /bookings/{booking}/cancel` — active, profile complete

**Request:** `reason` (optional).

Releases the seat under the same lock that takes it. Which side cancelled is read from **who the
caller is**, never from the request — a passenger must not be able to record their own
cancellation as the driver's, which would move the blame for a missed ride.

**No fee is charged.** The cancellation policy is still an open question and a guessed fee would
take money from a real person on an assumption. `cancellationFeePiastres` is always `0` today.

#### `POST /bookings/{booking}/dispute` — active, profile complete

**Request:** `reason` (10–255 characters, free text).

"That's not right" on the trip receipt. See 4.9 — this is the safeguard that makes the driver's
attendance decision acceptable, and it is the passenger's only move.

---


#### The seat-request shape

| Field | Meaning |
|---|---|
| `id`, `commuteId`, `tripId` | `tripId` is set for a trial request. |
| `status` | `PENDING` · `APPROVED` · `REJECTED` · `WAITLISTED` · `WITHDRAWN` · `EXPIRED` · `ENDED`. `ENDED` means the approval outlived the membership it created — it frees the slot so the person can ask to rejoin. |
| `commitment` | `trial` · `recurring`. |
| `requestedDaysMask` | For recurring. |
| `seats` | 1–4. |
| `meetingPreference` | `gate` · `street` · `landmark` · `custom`. |
| `introMessage` | As they typed it. Filtered for abuse and phone numbers on the way in. |
| `paymentType` | `cash` · `online`. |
| `agreedToRulesAt` | **A moment, not a flag** — in a dispute what matters is when they agreed. |
| `waitlistPosition` | Only while waiting for a seat. |
| `responseNote` / `respondedAt` | The driver's answer. |
| `expiresAt` | A request nobody answers expires after 48 hours, so it stops holding the one open request. |
| `passenger` | A `person` (5.1), on the driver's inbox. |

#### The scheduled-trip and booking shapes

**Trip** — one bookable day. Passengers always book these, never a recurrence rule.

| Field | Meaning |
|---|---|
| `id` | Pass as `scheduledTripId`. |
| `tripDate` | The **local** day. |
| `departureAt` / `departureLocal` | Compute from the first; display the second. They do not differ by a constant across a DST change. |
| `status` | `SCHEDULED` · `PREPARING` · `EN_ROUTE` · `IN_PROGRESS` · `COMPLETED` · `CANCELLED`. |
| `seatsTotal` / `seatsTaken` / `seatsAvailable` | Snapshotted when the day was generated — a later change to the commute does not retroactively move them. |
| `pricePerSeatPiastres` | Snapshotted the same way. |
| `bookingDeadlineAt` | **21:00 the night before.** After this, requests are refused with `BOOKING_DEADLINE_PASSED`. |

**Booking** — one seat on one day.

| Field | Meaning |
|---|---|
| `id`, `tripId`, `commuteGroupId` | |
| `status` | `PENDING` · `CONFIRMED` · `COMPLETED` · `CANCELLED_BY_PASSENGER` · `CANCELLED_BY_DRIVER` · `EXPIRED` · `NO_SHOW`. A booking is created **already `CONFIRMED`**: the driver has just approved a request she read, so leaving it pending would invent a second approval nobody performs. |
| `seatsReserved` | |
| `boardingCode` | 🔒 **Four digits, to check against the car at the kerb** — show it under the plate, as the design does. **Both the passenger and the driver see the same number**, so she can ask and the driver can confirm. One per journey, so it changes daily. `null` on bookings made before this shipped. 🔴 **It decides nothing**: `check-in` takes no code, attendance is the driver's tap (D18), and the Master Plan rejected a code for that job because "a PIN fails when somebody is running for the car". **Never block boarding, payment or a no-show on it** — a flat battery must not lock somebody out of a car she has verified by plate and sight. |
| `price.totalPiastres` | What the seat costs. |
| `price.platformFeePiastres` | The platform's share. |
| `price.driverAmountPiastres` | What reaches the driver. |
| `paymentType` | `cash` · `online`. |
| `paymentStatus` | `NOT_DUE` (nothing recorded yet) · `PENDING` · `PAID` · `FAILED` · `REFUNDED` · `DISPUTED`. **Cash:** the passenger pays in the car; the booking turns `PAID` about **two hours after the driver confirms they travelled** (the delay lets a wrong tap be noticed). A no-show stays `NOT_DUE`. `DISPUTED` as soon as the passenger contests the attendance record, until staff decide. |
| `meetingPoint` | `{lat, lng, isExact}`, or **`null` for the standard point** — which is the run's origin. |
| `cancelledAt`, `cancelledReason`, `cancellationFeePiastres` | The fee is always `0` today. |
| `trip` | The scheduled trip above, when loaded. |

🔒 **All three money figures are frozen at approval and never recomputed.** A driver raising her
price, or the platform changing its percentage, moves nothing here. And the meeting point is
**fuzzed to ~110m until the booking is confirmed** — a pickup point is often somebody's front
door, and a pending or cancelled booking has not earned it.

✅ **The fee direction is decided (2026-10-06): the fee is deducted from the driver's price.**
`totalPiastres = platformFee + driverAmount`; the passenger pays the published seat price and
nothing on top. The rate is 3% by default and the operations team can change it, so **never
hard-code a percentage** — render the figures you are given. ⚠️ **Screens 12 and 24 need new copy:**
screen 12 must not show the fee as a line added to the seat price, and screen 24's *"You keep the
full contribution; Rafeeq's fee is charged to passengers separately"* is wrong — the driver keeps
the price minus the fee.

## 4.8 Pickup points

> Agreeing where a passenger is actually picked up, when it is not the published meeting point.
>
> **Screens** 29 · **Shapes** —

#### `POST /commutes/{commute}/pickup-preview` — active, profile complete

**Request:** `lat`, `lng`, `label`.

What a proposed point would cost the driver, **without creating anything**. Use it to warn before
the person commits.

| Field | Meaning |
|---|---|
| `addedMinutes` / `addedKm` | What THIS point adds. Computed by us from the route, never taken from the requester. |
| `runTotalMinutes` | The detour for the **whole run** including every already-approved pickup. This is the figure the limit is checked against. |
| `maxDetourMinutes` | The driver's own limit. |
| `withinLimit` | Whether a request would be accepted. Show the consequence before they ask. |

#### `POST /seat-requests/{seatRequest}/pickup-request` — active, profile complete

For a **new joiner**, before their seat is approved.

#### `POST /groups/{group}/pickup-request` — active, profile complete

For an **existing member** — "could you collect me somewhere else from now on". Use this one once
the seat is approved; the seat-request route refuses with `SEAT_REQUEST_NOT_PENDING`.

**Request (both):** `lat`, `lng`, `label`.

Refusals: `PICKUP_NOT_ON_COMMUTE` (too far off the route), `PICKUP_DETOUR_TOO_LONG` (over the
driver's limit for the whole run), `PICKUP_ALREADY_REQUESTED`. A commute with
`allowsCustomPickup: false` refuses all of them.

#### `GET /pickup-requests` · `GET /driver/pickup-requests` — active, profile complete, paginated

| Field | Meaning |
|---|---|
| `status` | `PENDING` · `APPROVED` · `SUGGESTED_ALTERNATIVE` · `REJECTED`. |
| `proposedPoint` | `{lat, lng, isExact}`. 🔒 **Fuzzed until approved** — the driver sees the neighbourhood while deciding and the exact point once she has agreed to go there. |
| `addedMinutes` / `addedKm` | Our measurement. |
| `runTotalMinutes` / `maxDetourMinutes` | The cumulative figure and the limit, so the driver decides with the whole picture. |
| `effectiveFrom` | `next_trip` today. |
| `alternativePlaceId` | Set when the driver countered with a different place. |

#### `POST /driver/pickup-requests/{pickupRequest}/approve` · `/suggest-alternative` · `/reject` — active, profile complete

The three buttons on screen 29. `suggest-alternative` takes `placeId` and **moves nothing until
the passenger accepts**. Approving writes the agreed point onto the upcoming bookings, so the
meeting point and the decision cannot disagree.

#### `POST /pickup-requests/{pickupRequest}/accept-alternative` — active, profile complete

The passenger accepting the driver's counter-offer.

---

## 4.9 Live trip

> The journey as it happens: starting, check-in, no-shows, wait timers, live position and completing the run.
>
> **Screens** 17 · 36 · 39 · 40 · 41 · 43 · 45 · **Shapes** the trip session, at the end of this chapter · **realtime: 6**

The driver drives the run; a passenger on it may watch. Both halves are in this section because
they are two sides of the same state.

#### `POST /trips/{trip}/start` — active, profile complete, driver only

"Start Today's Commute". Re-checks **everything**: account approved, licence valid, vehicle
approved and active, the day not cancelled. Each was true when the commute was published and may
have stopped being true since.

May only be called within **90 minutes before departure** (`TRIP_TOO_EARLY_TO_START`, 422, with
`departureAt` and `windowMinutes` in `error.fields`). **There is no lower bound** — a run whose
departure has passed is exactly the one a late driver needs to start.

Returns the trip session (4.9) and opens a `pending` attendance row per confirmed seat.

#### `POST /trips/{trip}/status` — active, profile complete, driver only

**Request:** `status` — one of `EN_ROUTE`, `AT_PICKUP`, `IN_PROGRESS`.

The sequence: `PREPARING → EN_ROUTE ⇄ AT_PICKUP → IN_PROGRESS → COMPLETED`.

A multi-pickup morning **cycles between `EN_ROUTE` and `AT_PICKUP`** and becomes `IN_PROGRESS`
once, on the last leg — so `IN_PROGRESS → AT_PICKUP` is refused. `IN_PROGRESS` cannot be cancelled
either: once people are in the car the run finishes or becomes an emergency.

`TRIP_INVALID_TRANSITION` (409) carries `currentStatus` and `allowed[]` in `error.fields` —
**render those**, because a driver who taps the wrong button needs to be told what to do, not that
the transition was invalid.

#### `POST /trips/{trip}/complete` — active, profile complete, driver only

Closes the session, completes the bookings, updates the counters, and clears the live position.
**No money moves** — there is a two-hour delay before collection so a driver who tapped the wrong
name can notice (and Phase 8 is not built).

#### `POST /trips/{trip}/cancel` — active, profile complete, driver only — "Cancel today" (screen 23)

`{ "reason"?: "..." }` (optional, up to 255). Calls off **one day**; the commute and every other day
stand. `200` with the day (`ScheduledTrip`, `status: "CANCELLED"`, `seatsTaken: 0`).

- Every booking on the day becomes `CANCELLED_BY_DRIVER` and every passenger gets a
  `trip_day_cancelled` notification (4.14) with the driver's first name and the date — **never the
  reason**, which may be personal and shows on a lock screen. Nothing is charged.
- A pending one-day request for that date is expired.
- `TRIP_NOT_CANCELLABLE` (409, `error.fields.status`) once the run has started, or if the day is
  already cancelled. A run already under way is ended through `POST /trips/{trip}/status` instead.
- Anybody but the day's driver gets `404`.
- A cancelled day stays cancelled: it cannot be booked or started, and is not regenerated.

Ask for confirmation first, and say how many people will be told (`GET /driver/home` already
has the count for today).

#### `GET /trips/{trip}` — active, profile complete, driver or anybody on the run

The trip session (4.9). `TRIP_NOT_STARTED` (409) if the run has not begun — a real answer rather
than an empty session.

#### `GET /trips/{trip}/attendance` — active, profile complete, **driver only**

Who is aboard. Not offered to passengers: who was marked absent this morning is not something to
hand a fellow passenger.

#### `POST /trips/{trip}/check-in` — active, profile complete, driver only

**Request:** `bookingId`, `lat`, `lng` (both optional, both-or-neither).

"Sara arrived". **Decision D18: the driver confirms.** There is no field for the status — whether
this reads as `PRESENT` or `LATE` is decided by whether a wait timer was running, so the driver is
never asked to judge somebody's punctuality.

`lat`/`lng` are **corroboration only**. A confirmation that required a good GPS fix would fail in
a basement car park, and the driver would learn to check people in from the street to make it
work. Sending nothing is completely normal.

#### `POST /trips/{trip}/no-show` — active, profile complete, driver only

**Request:** `bookingId`.

Its own endpoint rather than a status flag, because it is a different act with a different
consequence: one puts somebody in a car, the other puts a mark on their record.

**An unconfirmed passenger is NOT a no-show.** If the driver never taps anything, the row stays
`PENDING` at completion — her silence is not an absence, and nobody carries a mark for something
she did not do.

#### `POST /trips/{trip}/drop-off` — active, profile complete, driver only

**Request:** `bookingId`.

🔴 **One passenger set down while the run carries on** (Feature 03 v5). On a shared journey the
itinerary interleaves: somebody dropped at the start of the Mehwar is out of the car twenty minutes
before the driver reaches the last stop.

| | |
|---|---|
| **Her booking completes** | `COMPLETED`, immediately — which is what **enables her rating**. Before this she could not review a ride she had finished until the driver closed the whole run. |
| **The run does NOT end** | Other passengers stay `CONFIRMED` and the session stays `IN_PROGRESS`. Finishing is still `POST /trips/{trip}/complete`. |
| **Her stop leaves the live route** | `reachedAt` is set on her `PASSENGER_DROPOFF` stop, so `navigation-route` stops offering it. |
| **Idempotent** | A second tap answers `200` and changes nothing. |

Refused with `409 ATTENDANCE_NOT_CONFIRMABLE` for somebody who was never picked up —
`error.fields.currentStatus` says where she actually is. Setting down a `PENDING` row would be
recording a journey nobody said happened.

#### `POST /trips/{trip}/still-driving` — active, profile complete, driver only

🔴 **"I am still on the road."** Pushes back the moment the run would otherwise be closed for her
by 15 minutes, **as many times as she needs** — there is deliberately no limit, because a woman
stuck in Cairo traffic is not more suspicious the fourth time she says so.

Show it once the run is past its promised arrival, beside `complete`. Response carries
`stillDrivingCount` and the new `autoFinishCheckAt`.

> ⚠️ **It is a convenience, not a safeguard.** A run is only ever closed automatically when **nobody
> is aboard and nobody is still awaiting a decision**, so a driver who never taps this cannot have a
> journey with people in it ended underneath her. Closing a run with somebody aboard would cut her
> live location while she is moving; closing one with a `PENDING` row would freeze a decision nobody
> made.

#### `POST /bookings/{booking}/end-early` — active, profile complete, **passenger only**

🔴 **The safety exit.** She is in the car and wants out before her drop-off.

**Request:** `reason` — **optional**, and keep it optional in your UI. A woman who wants out of a
car is not somebody to put a form in front of.

| | |
|---|---|
| **No driver confirmation** | By design. A flow needing the driver's agreement would need it from the person she may be getting away from. |
| **Her booking becomes `ENDED_EARLY`** | A **new status**. Not `COMPLETED` (which would say she was carried where she agreed, and decides what she is charged) and not cancelled (which would say she never travelled, and lands on the cancellation history a future driver reads). |
| **No fee** | `cancellationFeePiastres` is `0`. She was carried. |
| **Location access ends at once** | `GET /trips/{trip}/location` answers `404` for her from that moment, and any live-share link she sent a friend is revoked. For most commutes where the car goes next is the driver's street. |
| **She can still rate it** | She travelled part of the way, and this is the review it matters most to allow. |
| **The driver is told** | `passenger_ended_early` — **without the reason.** It may be about her. |

Before she has been picked up this is the wrong call and answers
`409 ATTENDANCE_NOT_CONFIRMABLE`: use `PATCH /bookings/{booking}/cancel`. v5 is explicit —
"passenger pre-pickup cancel anytime; after pickup end early". The driver calling it gets `404`:
putting somebody out of her car is Cancel Active Trip, with a reason and a safe stop.

#### Attendance fields (from check-in, no-show, dispute and the list)

| Field | Meaning |
|---|---|
| `bookingId` | Which seat. |
| `status` | `PENDING` (nobody recorded it) · `PRESENT` · `LATE` · `PASSENGER_NO_SHOW` · `DRIVER_NO_SHOW` · `CANCELLED`. |
| `travelled` | Whether this counts as having travelled — what Phase 8 will collect on. Sent rather than inferred so you do not hard-code which values count. |
| `checkedInAt` / `checkedOutAt` | Boarding, and the end of the journey (set for everybody at once on completion). |
| `confirmedBy` | `driver` under D18. Sent rather than assumed, so an old record still says which method produced it once a second one exists. |
| `confirmedAt` | **When the decision was made. The 24-hour dispute window runs from here, not from the end of the trip.** |
| `gpsCorroborated` | Whether the driver's position **agreed** with the meeting point — not whether a reading was supplied. |
| `gpsConfidence` | `0.00`–`1.00`, or `null` when no reading was taken. `0.00` means we measured and it disagrees, which is a different fact from no reading at all. |
| `disputedAt` / `disputeReason` | Set when the passenger contested it. The status does **not** change — a dispute records that a record is contested and holds the money. |
| `disputeResolution` | `upheld` · `overturned` · `refunded`, once a reviewer decides. `null` while open. |
| `person` | The passenger (5.1), on the driver's list. |

**`PRESENT`, `LATE` and the no-show statuses are final.** The driver cannot change her mind — a
passenger charged on Monday must not become absent on Friday, or the dispute window would protect
nothing. A mistake is corrected by the passenger disputing and a reviewer overturning.

#### `POST /trips/{trip}/wait-timers` — active, profile complete, driver only

**Request:** `bookingId`. There is no `graceSeconds` field: how long the platform asks a driver to
wait is policy, not something the app decides.

#### `POST /trips/{trip}/wait-timers/{timer}/extend` — active, profile complete, driver only

The "+2 min" button. **Allowed even after the grace has expired** — a driver who sees somebody
running for the car at 5:10 should be able to give them two more minutes.

#### `GET /trips/{trip}/wait-timers` — active, profile complete, driver only

Finished timers included: a run where the driver waited nine minutes for one person and left
another after ninety seconds is a run somebody may ask about.

| Field | Meaning |
|---|---|
| `startedAt` | When the waiting began. Extending does **not** move this. |
| `expiresAt` | Grace plus everything added. Count down to this. |
| `remainingSeconds` | The server's answer now. **Negative once expired** — the screen keeps showing the timer with "grace ended", and how long ago it ended is what the driver decides on. |
| `graceSeconds` | What was promised, **frozen on the row** when the timer started. A dispute about this morning is settled against the promise in force this morning, not against a number changed later. |
| `extendedSeconds` | What the driver added on top. Separate from the grace so the record can say "she waited five minutes and then gave two more". |
| `hasExpired` / `isRunning` | A timer that was answered is not running whatever the clock says. |
| `outcome` | `null` while running, then `arrived` · `no_show` · `driver_left`. |

**There is no endpoint that stops a timer.** "She's here — continue" is the check-in and "Mark
no-show & depart" is the no-show; both close it in the same call.

🔴 **`no_show` and `driver_left` are different on purpose.** A driver may always depart, but
leaving after ninety seconds of a five-minute grace is a different morning from leaving after the
grace ran out. **The outcome is decided by the clock, not by anything the app sends.**

#### `POST /trips/{trip}/location` — active, profile complete, driver only

**Request:** `points[]` (1–60), each `{lat, lng, recordedAt, accuracyMeters, speedKmh}`.

An **array**, because a phone that loses signal buffers and sends the backlog. `recordedAt` is the
**device's** clock — that is what makes six buffered points describe six real moments.

| Response field | Meaning |
|---|---|
| `accepted` | How many were kept. |
| `rejected` | How many were dropped as implausible. **Log this** — the usual cause is a wrong device clock, and the driver will never notice. |

Points are dropped, not refused, when they are from the future (beyond a 2-minute skew), from
before the run could have begun, or the phone's accuracy figure is hopeless (over 500m). A batch
of six with one bad point delivers five.

#### `GET /trips/{trip}/location` — active, profile complete, driver or a **live** booking

| Field | Meaning |
|---|---|
| `position` | `{lat, lng, recordedAt, accuracyMeters, speedKmh}`, or **`null` when the run has gone quiet**. |
| `lastLocationAt` | When the last reading arrived, whether or not a position is still current. **This is how you know your map is stale rather than that the car has stopped.** |
| `isUnderway` | Whether the run is out on the road. |

🔒 Narrower than reading the trip itself: a **cancelled or completed** booking cannot read the
position. Someone who has finished their journey has no reason to keep watching the car, and where
it goes next is the driver's home.

This is the **fallback** for the WebSocket in section 6 — a phone on a bad connection at a bus
stop is exactly what a live map is for and exactly where a socket fails to open.

---


#### The trip-session shape

| Field | Meaning |
|---|---|
| `id` | **Use this as the WebSocket channel id** (section 6), not the trip id. |
| `tripId` | |
| `status` | `PREPARING` · `EN_ROUTE` · `AT_PICKUP` · `IN_PROGRESS` · `COMPLETED` · `CANCELLED` · `EMERGENCY`. |
| `startedAt` | When the driver tapped "start" — **not** when the car moved. |
| `departedAt` | When the car actually set off (first entry into `IN_PROGRESS`). `null` before that. This is what the on-time rate is measured from. |
| `completedAt` | |
| `durationSeconds` | Measured from `departedAt`, not from `startedAt`. |
| `distanceTravelledMeters` | `null` until there are GPS points to measure it from. **Not** the route's planned distance — that would present a figure we did not observe as one we did. |
| `isUnderway` | Stated rather than left for you to infer which statuses count. |
| `lastLocationAt` | When the last GPS reading arrived. **A client showing a live map needs this** — without it you draw a car that stopped reporting twenty minutes ago as though it were still there. |
| `deviationDetectedAt` | **Screen 39.** When the run FIRST went off its published route. `null` on the overwhelming majority of runs — this is an exception report, not a measurement, so do not render "0 m off route" on a normal morning. Checked only while the run is `IN_PROGRESS`: on the way to collect people, being off the direct line is the job. |
| `deviationDistanceMeters` | The FURTHEST it got, not where it is now. Together with the moment above, that is when it started and how far it went. `null` when there was no deviation. |
| `trip` | The scheduled trip, when loaded. |

🔒 **No GPS trail.** A passenger needs the car's position now, not the history of where it has
been.

---

## 4.10 Groups

> The standing group a recurring booking creates — its members, attendance and planned absences.
>
> **Screens** 20 · **Shapes** `person` in 5

#### `GET /groups` — active, profile complete, paginated · `GET /groups/{group}` — active, profile complete

🔒 Reachable only by a member of that group, 404 otherwise. A member list is a set of real first
names, trust levels and the days each person reliably travels — which is also when they are not at
home. There is no access level at which somebody outside the group can read it.

| Field | Meaning |
|---|---|
| `name` | The group's name. |
| `status` | `ACTIVE` · `PAUSED` · `DISBANDED`. |
| `minCommitmentDaysPerWeek` | What this group expects of a recurring member. |
| `noticePeriodDays` | How much warning a member owes before leaving. |
| `onTimePct` | Computed from completed trips. `0` until there are any. |
| `ridesTogetherCount` | How many mornings this group has actually shared. Show this rather than "founded 3 weeks ago". |
| `seatsOpenNextTrip` | Seats free on the next day. |
| `memberCount` | Including the driver. |
| `commute` | The commute it belongs to. |
| `members[]` | See below. |

#### `GET /groups/{group}/members` — active, profile complete, paginated

| Field | Meaning |
|---|---|
| `role` | `driver` · `member` · `trial`. |
| `status` | `active` · `notice_given` · `left` · `removed`. |
| `committedDaysMask` | Which days this member travels. |
| `joinedAt` / `leftAt` | |
| `leavesOn` | The date their notice completes, when `notice_given`. |
| `person` | See 5.1. |

#### `POST /groups/{group}/leave` — active, profile complete

Gives notice. The member stays until the notice period completes — a driver who planned her month
around four passengers should not discover on Sunday night that one is gone. A **driver cannot
leave her own group** (`GROUP_DRIVER_CANNOT_LEAVE`, 403); she pauses or archives the commute.

#### `GET` / `POST /groups/{group}/attendance` — active, profile complete, paginated

**This is screen 36.** The **declared** intention — "I'm coming tomorrow" — which is a different
table from the boarding confirmation in 4.9, on purpose: only the check-in decides money.

**POST request:** `tripId`, `status` (`coming` / `away` / `no_response`). Returns `201`.

| Field | Meaning |
|---|---|
| `tripId` | Which day. |
| `status` | `coming` · `away` · `no_response`. |
| `markedAt` | When they answered. |
| `person` | See 5.1. |

`GROUP_ATTENDANCE_NOT_DECLARABLE` (409) once it is too close to departure — after that the driver
is already planning around the answer she was given.

#### `GET` / `POST` / `DELETE /groups/{group}/absences/{absence}` — active, profile complete, paginated

Planned absence — "I'm away next week".

**POST request:** `fromDate`, `toDate`, `reason`, `releasesSeat`.

| Field | Meaning |
|---|---|
| `fromDate` / `toDate` | Inclusive. |
| `releasesSeat` | Whether the seat goes back to the pool for those days. |
| `person` | See 5.1. |

`GROUP_ABSENCE_OVERLAPS` (409) and `GROUP_ABSENCE_TOO_LONG` (422).

---

## 4.11 Home aggregates

> The two screens the app opens on. One call each, so a cold start is not six requests.
>
> **Screens** 9 · 23 · **Shapes** —

Both are **one call each** and **not paginated** — see 1.3 for why.

#### `GET /home` — signed-in

Screen 9. Signed in and nothing more, deliberately: this screen TELLS a person what is still
missing, so gating it behind a complete profile would hide the instructions behind the requirement
they explain.

| Field | Meaning |
|---|---|
| `greetingName` | Their public first name. |
| `verification` | `{level, of, percentage, isComplete}` — the same numbers as the Verification Centre, so the banner cannot disagree with the screen behind it. |
| `nextJourney` | The soonest journey that has not finished, or **`null`**. Contains the full booking (4.7) plus `originLabel`, `destinationLabel`, `departureAt`, `departureLocal`, `departsInMinutes`, `isToday`, `attendance`, `isTrial`, `driver` (5.1), `vehicle`. |
| `nextJourney.attendance` | `{coming, away, awaiting, total}` — **four counts, not "2 of 3"**. Somebody who said they are away and somebody who has not answered are not the same person to a driver deciding whether to wait, so the server does not collapse them. `total` includes the driver. |
| `nextJourney.vehicle` | `make`, `model`, `colour`, `seats`, and `plateNumber` **only when the booking is confirmed**. |
| `nextJourney.meetingPoint` | `null` means the standard point — which is the run's own origin, already in `originLabel`. A non-null value is a specifically agreed pickup. |
| `topMatches[]` | Up to 3, **ordered by score** rather than recency. Same shape as `GET /matches`. |
| `savedSearches[]` | Up to 5 — `{id, title}`. **No match count**: that would mean running the most expensive query in the product several times per home load. |

#### `GET /driver/home` — signed-in

Screen 23. **A passenger who has never applied to drive gets an empty payload, not a 404** — the
role switch lives on this screen, and a 404 would make tapping "drive" look broken.

| Field | Meaning |
|---|---|
| `greetingName` | |
| `nextRun` | Today's run, or `null`. |
| `nextRun.originLabel` / `destinationLabel` | |
| `nextRun.departureAt` / `departureLocal` / `departsInMinutes` | `departsInMinutes` is the server's countdown; see 1.5. |
| `nextRun.isToday` | Derived from the **local** clock of the journey, not the server's. |
| `nextRun.status` | The trip's status (`SCHEDULED`, `PREPARING`, `EN_ROUTE`, `IN_PROGRESS`…). |
| `nextRun.seatsTotal` / `seatsTaken` / `seatsOpen` | |
| `nextRun.attendance` | The same four counts as above. |
| `nextRun.collectPiastres` | **What to take at the door** — the sum of the frozen prices. |
| `nextRun.keepPiastres` | **What is hers** once the platform's fee is settled. Two figures because with cash they are different numbers and she needs both. Both sum what was frozen per booking, so a price change tomorrow moves neither. |
| `nextRun.passengers[]` | `bookingId`, `person` (5.1), `commitment`, `requestedDaysMask`, `seatsReserved`, `pickup`, `attendanceStatus`. |
| `nextRun.passengers[].pickup` | `{lat, lng}` exactly — she has to drive there, and she approved it. **`null` means the standard meeting point**, i.e. the run's origin. |
| `nextRun.passengers[].attendanceStatus` | The **declared** answer (`coming`/`away`), or `null` when they have not answered. Null is not "away". |
| `pendingRequests.count` | **Every** waiting request, not the length of the list. A driver with eleven should see 11. |
| `pendingRequests.items[]` | The first 5, oldest first, each a seat request (4.7) with its `passenger`. |
| `stats.onTimeRate` | `null` until it has been computed from completed runs. **Show a dash, not 0%** — a 0% badge on somebody's first morning is a number nobody earned. |
| `stats.completedTrips` | |
| `stats.avgDetourMinutes` | Averaged over approved custom pickups. `0` is true and means nobody has one; `null` means she has no commutes at all. |

## 4.12 Safety (screens 16, 26, 32, 44)

> SOS, trusted contacts, incident reports with evidence, blocking somebody, and sharing a live trip.
>
> **Screens** 16 · 26 · 32 · 44 · **Shapes** —

🔴 **Everything in this section is reachable by a signed-in account and nothing more.** No
verification gate, no complete-profile requirement, no active trip. That is deliberate and it is the
most important thing to know before you build these screens: somebody in trouble at a roadside will
not finish uploading a national ID first, and a 403 at that moment would be the worst answer this
platform could give. These routes sit in the same tier as "see that I am suspended" and "revoke a
stolen phone".

So **do not gate the Safety button in your own navigation either.** It should be reachable from a
half-finished sign-up.

#### `POST /sos` — the emergency button

**Request — every field optional.** An empty body is a valid SOS.

| Field | Meaning |
|---|---|
| `isDiscreet` | 🔒 A **silent** alert: no sound, no vibration. For the situation where being seen to ask for help is itself the danger. **The server cannot enforce this — your client must.** |
| `lat` / `lng` | Where they are, if the phone knows. Both or neither. |
| `tripSessionId` | The run they are on, if you happen to know it. **The server looks it up otherwise**, and an id it does not recognise is ignored rather than refused — a stale session must not turn an emergency into a 404. |

🔴 **The record is written the instant you call this, before any countdown finishes.** The countdown
guards against an accidental tap and runs on the phone; it does not gate the record. If the phone is
taken or its battery dies during those ten seconds, a design that waited for confirmation would have
no trace that anything happened. So: call this first, then show the countdown, then offer cancel.

Answers `201` with the SOS.

| Response field | Meaning |
|---|---|
| `id` | Pass to the cancel endpoint. |
| `countdownSeconds` | How long to count down. Copied onto the row when it was raised, so it is the window actually in force — do not hard-code 10. |
| `isDiscreet` | Echoed back. |
| `cancelledAt` | `null` unless it was taken back. |
| `respondedAt` | When a human first picked it up. `null` until then. **Show this** — it is what tells the person somebody is actually looking. Since 2026-10-05 the safety desk sets it from the dashboard, so it really does change while the alert is open. ⚠️ There is no endpoint to re-read an SOS yet: today the app learns it from the cancel refusal (`error.fields.respondedAt`, below). A push when somebody picks it up is Phase 12. |
| `resolution` | `false_alarm` · `resolved` · `escalated_police`, once decided. `null` while open. |

🔒 The responder's identity is deliberately **not** returned. An operator handling an emergency is a
member of staff doing their job, and naming them gives an angry or unwell caller a human target.

#### `POST /sos/{sos}/cancel` — "it was an accident"

🔒 **Nothing is deleted.** The row stays with a cancellation time on it, because a pattern of presses
cancelled seconds later — same route, same driver — is exactly the signal a safety team needs, and it
is invisible if each one erases itself. Your copy should not promise deletion.

Refuses with `SOS_ALREADY_RESOLVED` (409) if it was already cancelled, or **if an operator has
already picked it up** — `error.fields.respondedAt` says when. Show that differently: "I cancelled
it" and "somebody is calling me" are different situations to be in.

#### `GET` / `POST /safety/emergency-contacts` · `PATCH` / `DELETE .../{contact}`

Trusted contacts. **Request:** `name`, `phone`, `relationship`, `autoShareTrips`, `isGuardian`.

| Response field | Meaning |
|---|---|
| `id`, `name`, `relationship` | As entered. |
| `phone` | The **full** number, because this is the owner reading their own list and a masked number cannot be checked for a typo. |
| `autoShareTrips` | 🔴 **Stored, and nothing acts on it yet. Do not label it as working.** See the note below — this corrects an earlier version of this table, which said it already shared every trip. |
| `isGuardian` | Elevated access during an emergency. |
| `verifiedAt` | Whether the number has been confirmed to actually receive messages. **`null` for everybody today** — confirming it needs an OTP to that number (Phase 12). Say "unconfirmed" rather than implying the contact works. |

Rules you will hit:

- **Capped at 5** (`EMERGENCY_CONTACT_LIMIT_REACHED`, 422, with the limit in `error.fields`). This
  list is who receives somebody's location; an unbounded one is a way to broadcast their movements.
- **The number is normalised**, so the same contact cannot be added twice in two formats
  (`EMERGENCY_CONTACT_DUPLICATE`, 409).
- **Changing the number clears `verifiedAt`.** Carrying it across would mean a "verified" badge on a
  number nobody ever reached.
- **Removal is immediate and unconditional.** Do not add a confirmation dialog: somebody removing a
  contact may be doing it quickly and quietly, and every extra step is a step taken while they may be
  watched.

🔒 There is no endpoint that returns anybody else's contacts. Another person's list is a 404, and an
empty list for a stranger is genuinely empty.

> 🔴 **`autoShareTrips` does not yet do anything, and this is the one field in the API where that
> gap could hurt somebody.** A person who switches it on believes their sister will see every trip.
> Nobody will, until an outbound SMS/WhatsApp provider is chosen (2026-10-05: the notification inbox
> now exists, but it only reaches people with the app — a trusted contact usually has neither the
> app nor an account, so the link has to go to their phone number).
>
> The reason it cannot be built first is in the live-share design, two subsections down: an auto-made
> share link has to be **delivered at the moment it is created**, because the token is returned once
> and is unrecoverable afterwards. Creating the share now and delivering it later is not an option —
> it would write links nobody on earth can open. So the feature needs Phase 12's SMS or push, and
> the flag is stored meanwhile so that nobody's stated intent is lost when it lands.
>
> **What this means for your screen:** keep the switch, keep what it is called, and say plainly that
> it starts when automatic sharing is available — do not write copy in the present tense. If you
> would rather hide it until then, that is a reasonable choice and we will tell you when it works.



#### `POST /incidents` — file a report

**Request:** `category` (required), `description`, `bookingId`.

| Category | |
|---|---|
| `harassment` · `identity_mismatch` | Treated as **critical** |
| `unsafe_driving` | **high** |
| `other` | **medium** |
| `no_show` · `payment` · `lost_item` | **low** |

🔴 **There is no `severity` field and there must not be.** It is decided from the category by the
server. A reporter cannot be asked to rate their own emergency — somebody who has just been harassed
is not in a position to choose between "medium" and "high" — and a client that could set it would own
the ordering of the safety queue.

🔴 **There is no `reportedUserId` field either.** Who the report is about is derived from the booking.
Accepting it would be a way to put a mark against a stranger.

Both `description` and `bookingId` are **optional**. A report about somebody impersonating a Rafeeq
driver has no booking, and that is exactly the report the platform most needs to receive; somebody
shaken may not want to type at all.

Rate-limited per user per hour, generously — a `429` carries `Retry-After`. `INCIDENT_NOT_REPORTABLE`
(422) means the `bookingId` is not one of theirs.

#### `GET /incidents` — paginated · `GET /incidents/{incident}`

| Response field | Meaning |
|---|---|
| `category`, `description` | As filed. |
| `severity` | The platform's judgement. See above. |
| `status` | `OPEN` · `UNDER_REVIEW` · `ESCALATED` · `RESOLVED` · `CLOSED`. |
| `slaDueAt` | When the platform has undertaken to respond by, written when the report was filed. Fixed rather than recomputed — a deadline that can be recalculated is one that can be quietly moved. |
| `resolution` / `resolvedAt` | Set when the safety team resolves or closes the report — `null` before that, including while it is `ESCALATED`. `resolution` is **free text written by a member of staff to the reporter** (up to 255 characters, in whatever language they wrote it), so render it as a message from Rafeeq, not as a status label, and with `dir="auto"`. The reason for an escalation is internal and never appears here. |
| `bookingId` | The journey, when one was named. |
| `evidenceCount` | How many files are attached. |

🔒 **Own reports only, and that includes the person a report is about.** Showing the subject what was
said about them, in the reporter's own words, is how a report becomes a reason for a confrontation.
The reviewer's identity and the reported person's id are not returned either.

#### `POST /incidents/{incident}/evidence` — attach a photograph

`multipart/form-data`. **Request:** `kind` and `file`.

| Field | |
|---|---|
| `kind` | `photo` for a picture taken at the scene, `screenshot` for a capture of messages or of the app. |
| `file` | JPEG, PNG or WebP, up to 6 MB. |

🔒 **Images only, and the other kinds are refused rather than quietly accepted.** `video`, `audio`
and `document` exist in the schema and are a `422` here. The reason is worth passing on: stripping a
file's metadata is done by re-encoding it, and there is no equivalent for a video or an audio
container. Accepting one would mean either storing it unsanitised — handing over the GPS and device
identifiers inside it — or claiming a protection that is not there. If your users need to send
video, tell us and we will build the pipeline rather than widen this rule.

🔒 **The GPS in the photograph is stripped server-side**, along with all other metadata, and the
image is re-encoded as JPEG. This matters more here than anywhere else in the product: a photograph
taken at the scene of an incident carries the coordinates of **where the person was standing when
they were frightened**, and they meant to photograph a car. Do not rely on stripping it on the
device — but do tell the person what they are sending.

🔴 **Nothing can be un-attached.** The table is chain of custody and is never deleted, at any access
level, by anybody. So **confirm before uploading** rather than offering a remove button you cannot
honour. This is the one place in the API where a client-side mistake is permanent.

Answers `201` with the attachment's metadata. Errors:

| | |
|---|---|
| `INCIDENT_EVIDENCE_LIMIT_REACHED` (422) | Five files per report. `error.fields.limit` carries the number — read it rather than hard-coding five. |
| `INCIDENT_CLOSED` (409) | The case is finished. The message tells the person to file a new report, which is the right move: a file on a closed case is a file nobody will read. |
| `DOCUMENT_REJECTED_BY_SCANNER` · `DOCUMENT_UNREADABLE` (422) | The bytes failed the scan, or are not a decodable image however they were named. |
| `DOCUMENT_DIMENSIONS_TOO_LARGE` (422) | 🔴 **The picture is too many pixels, which is not the same as the file being too big.** 16 megapixels; `error.fields.maxMegapixels` carries the limit. A modern phone shooting at full resolution will hit this, so **downscale before uploading** — nothing here needs more than 2400px on the longest side and we resize to that anyway. The same limit applies to every image endpoint in the API (identity documents and vehicle documents included). |
| `404` | Not the caller's report. |

#### `GET /incidents/{incident}/evidence` — what is attached

| Response field | Meaning |
|---|---|
| `id`, `kind` | As attached. |
| `purgeAfter` | The date the file may be destroyed, fixed when it was uploaded. **Show it.** Somebody who sends a photograph of a bad moment is owed the answer to "how long do you keep this", and a date on the screen is a better answer than a policy page. |
| `createdAt` | When it arrived. |

🔒 **No path, no URL and no hash — not now and not later.** A stored path never appears in a payload;
the reporter knows what they sent, and the reviewer reads it through the dashboard, which
authenticates against a different user table entirely. So there is nothing here to forward and
nothing to adjust into a guess at somebody else's file. The flip side, and you should design around
it: **your client cannot show the user a thumbnail of what they attached.** Keep your own local copy
if the screen needs one.

#### `GET` / `POST /safety/blocked-users` · `DELETE .../{user}`

**POST request:** `userId`, `reason` (optional, **never shown to the blocked person**).

| Response field | Meaning |
|---|---|
| `id` | The block. |
| `userId` | Who was blocked. |
| `person` | Their public summary (5.1), so the list is recognisable without naming anybody fully. |
| `reason` | What the blocker wrote, to themselves. |

🔴 **The block works in both directions immediately.** Neither of you will appear in the other's
search results from the next search on. Blocking one-directionally would mean somebody who blocks a
person they are afraid of still showing up in *that person's* results — the protection would run the
wrong way.

🔒 **Nothing tells the blocked person.** No notification, no marker in any payload they can read, and
the list is one-directional: **who I blocked, never who blocked me.** Somebody blocking a person they
are frightened of must not thereby inform them of it. Your UI must not leak it either.

**Existing bookings are untouched.** A block is about the future; unwinding this week's arrangement is
a cancellation, which is a separate act with consequences for the other people in the car.

`CANNOT_BLOCK_SELF` (403) · `ALREADY_BLOCKED` (409).

#### `POST /trips/{trip}/live-share` — Share Live Trip

A temporary link somebody sends a trusted contact: "this is the car I am in, here is where it is."

🔴 **The response carries `url` and `token` exactly once.** Nothing can reproduce them afterwards —
only a hash is stored, and re-reading the share gives its view count rather than its link. So **pass
`url` to the share sheet in the same breath**: if your client drops it, the only recovery is to
create a new share. Do not cache it, do not log it, do not put it in analytics.

**Request:** `contactId` only, and it is optional. A share with no contact named is a link the person
sends themselves, which is how it will mostly be used — pasted into whichever chat they wanted. A
`contactId` must be one of **their own** emergency contacts; anything else is a `404`.

There is **no `expiresAt` field and there must not be.** How long a link lives is policy: a share
that outlived its journey would be a standing window onto wherever that person goes next, and
letting the client choose would put that one API call away.

Answers `201`.

| Response field | Meaning |
|---|---|
| `url` | 🔴 **The whole thing. Shown once.** Hand it to the share sheet. Shape: `https://<host>/s/<token>`. |
| `token` | The same credential on its own, for a client that wants to build its own copy string. Treat it exactly like `url`. |
| `id` | The share, for revoking it later. **This is safe to keep**; the token is not. |
| `tripSessionId` | The run it describes. |
| `sharedWithContactId` | The contact, when one was named. |
| `expiresAt` | When the link stops working. **Show it** — the person should know how long they have exposed. |
| `isActive` | Whether it works right now. |
| `viewCount` / `lastViewedAt` | Whether anybody opened it. See below. |

Refuses with `TRIP_NOT_STARTED` (409) **until the car is actually out** — `error.fields.tripStatus`
says where it is instead. A link made before the run starts would be a page saying nothing, and one
made after it finishes is a window onto a journey that is over. A `404` means the caller is not on
this run: only the driver, or a passenger holding a live seat, may share it.

#### `GET /safety/live-shares` — paginated · `DELETE /safety/live-shares/{share}`

The caller's own links. 🔒 **Without tokens, and that is not an oversight** — being able to re-read
them would mean a stolen access token could harvest every link a person ever made. The list gives
`isActive`, `expiresAt`, `viewCount` and `lastViewedAt`.

🔴 **`viewCount` is not analytics, so put it on the screen.** For somebody who shared a link because
they felt uneasy, "did they actually look?" is the question they opened the screen to answer.

`DELETE` ends it now rather than at its expiry, and takes effect on the next view. `200` with
`isActive: false`; `LIVE_SHARE_ALREADY_ENDED` (409) if it was already revoked, `404` for somebody
else's.

#### `GET /s/{token}` — the page the contact opens. **Not yours to call.**

Not under `/v1`, no auth token, returns HTML, absent from the OpenAPI document. The only route in
the platform that serves real data with no authentication, and it is written as if the URL were
public — because in practice it is: it gets forwarded and screenshotted.

What the page shows is deliberately narrow, and it is worth telling the user, because it is the
reassurance that makes them willing to share at all:

- **shown** — the driver's public first name, the car (colour, make, model, plate), where it is now,
  where it is going, when it is due
- **not shown** — anybody's phone number, full name or address · the other passengers, who did not
  consent to being named to this viewer at all · the GPS history · any id

🔒 The reason is that the viewer is a stranger to the **driver**. She never agreed to share anything
with this person, and the passenger cannot consent on her behalf. So the page carries what somebody
needs to act in an emergency and nothing that is still useful to them tomorrow.

Expired, revoked and never-existed all render the **same** page with the same `404`. Do not write
copy that distinguishes them: a page that said "this link has expired" would confirm that a guessed
token was once real.

---

## 4.13 Ratings (screen 37)

> Rating each other after a journey, the public reviews that result, and reporting an abusive one.
>
> **Screens** 37 · **Shapes** —

🔴 **Double-blind.** Neither person can see what the other wrote until **both have rated, or seven
days pass.** This is not a UI rule you are being asked to honour — the API does not return the other
person's rating at all until then, and there is no endpoint that would. Build the screen knowing the
data is simply not there yet.

Three consequences you will meet, each of which exists to close a way round the design:

1. **The rating window closes when the reveal happens.** One window, not two. If you could still
   rate on day eight, you could wait for day seven, read what they said, and answer it.
   `RATING_WINDOW_CLOSED` (409).
2. **An edit is refused the moment the rating becomes visible**, whatever `editableUntil` said. The
   edit window is for fixing a typo in the minute after writing; without this rule, "rate five
   stars, wait for the reveal, read theirs, revise mine to one" would be two ordinary API calls.
   `RATING_NOT_EDITABLE` (409).
3. **Nothing tells you whether the other person has rated.** Not a field, not a count, not an
   average that moved. **Do not write copy that implies it** — "waiting for them" is precisely the
   fact being withheld.

#### `GET /ratings/pending` — what screen 37 is launched from

Not paginated: this is the handful of journeys somebody still owes a rating on.

| Response field | Meaning |
|---|---|
| `bookingId` | Pass to the submit endpoint. |
| `tripDate` | Which journey, so the screen can name the day. |
| `person` | Who it is about — the public summary (5.1), the same shape as everywhere else. |
| `direction` | `passenger_to_driver` or `driver_to_passenger`. Decides which tags your screen offers. |
| `rateableUntil` | When the chance goes. **Show it**; a rating that silently becomes impossible is worse than a countdown. |

A journey drops off this list when it is rated **or** when its window closes. It never carries the
other person's rating or whether one exists.

#### `POST /bookings/{booking}/rating`

| Field | |
|---|---|
| `stars` | **Required**, 1–5, whole stars. There are no halves in the design. |
| `comment` | Optional, ≤1000 characters. **Keep it optional in your UI too** — somebody who had an uncomfortable ride may well give three stars and not want to write about it, and requiring a reason is how a rating screen becomes a form people abandon. |
| `tags` | Optional, any of six: `safe_driving` · `on_time` · `clean_car` · `great_company` · `comfortable` · `would_ride_again`. Repeats collapsed. |

🔒 **There is no `userId` and no `direction` in the request, and there will not be.** Who the rating
is about is read from the booking. A field naming the subject is a way to put stars — or a comment —
against a stranger, which is why `reportedUserId` is absent from the incident request for the same
reason.

Answers `201` with **your own** rating, which is the one thing you may read before the reveal.

| Response field | Meaning |
|---|---|
| `stars`, `comment`, `tags` | What you wrote. |
| `kind` | 🔴 **New.** `completed_ride` or `canceled_booking` — see below. It changes three behaviours, so branch on it. |
| `isVisible` | Whether anybody else can see it yet. 🔴 **`false` says nothing about whether they have rated** — it is also false when nobody has. Always `true` immediately for `canceled_booking`. |
| `editableUntil` | Until when you may change it, or `null` once you cannot. Null covers both reasons — the clock ran out, or it became visible — because your behaviour is the same either way and distinguishing them would hint at theirs. |
| `editedAt` | Set if it was changed. |

Errors: `TRIP_NOT_RATEABLE` (422 — the journey has not happened, or the cancellation was not the
driver's; `error.fields.bookingStatus` says which) · `RATING_WINDOW_CLOSED` (409) ·
`RATING_ALREADY_SUBMITTED` (409) · `404` for a journey the caller was not on.

##### Canceled Booking Feedback

🔴 **A passenger whose confirmed seat the DRIVER cancelled can now rate her, even though no ride
happened** (Feature 03 v5). The same endpoint; the server decides the `kind`.

| | |
|---|---|
| **Who** | Only the passenger, and only on a booking with status `CANCELLED_BY_DRIVER` — which includes seats lost to a **paused commute** or a **replaced vehicle**. A passenger who cancelled her own seat still cannot rate anybody, and the driver cannot rate a passenger for a journey she called off: both answer `422 TRIP_NOT_RATEABLE`. |
| **Visible at once** | `isVisible: true` on creation. There is no second rating to wait for — the driver cannot rate back — so double-blind has nothing to protect and would only delay it. **Do not show it as "pending reveal".** |
| **Editable while visible** | Unlike a completed-ride review, which locks the moment it becomes readable. With no counterpart to read, "rate low, read hers, revise" is not a thing that can happen here. |
| **Not in her star average** | These stars are shown under their own heading and deliberately do not move `rating` anywhere. v5 displays them "distinct from completed-ride review" and gives cancellations their own instrument, the Reliability Score — counting them twice would punish one event twice. |
| **The window** | Runs from the **cancellation**, not the departure. A pause can cancel a morning three weeks out, and measuring from the departure would open the window before anything happened. |
| **The driver can report it** | `POST /ratings/{rating}/report`, exactly as for any review. |

On a public profile this comes back as its own block, **separate from `reviews`**:

```
"reviews":                  { "count": 12, "items": [...] },   // journeys that happened
"canceledBookingFeedback":  { "count": 1,  "items": [...] }    // seats that were cancelled
```

> 🔒 Keep them separate on screen too. Somebody deciding whether to rely on this driver is asking
> two different questions — what she is like to ride with, and whether she turns up — and one merged
> list of stars answers the first with evidence about the second.

#### `PATCH /ratings/{rating}` · `GET /ratings/mine` — paginated

The same body as submitting; every field optional. `404` for somebody else's rating — **not a 403**,
because confirming that one exists would itself say they had rated.

`GET /ratings/mine` returns what the caller wrote, visible or not. No filter applies to your own: a
person may always read what they said.

#### `GET /commutes/{commute}/reviews` — paginated · what people said about this driver

What screen 12's reviews section runs on.

🔒 **Keyed on the commute, not on a person**, and that is deliberate rather than awkward: no payload
in this API returns a user identifier, so a `/users/{id}/reviews` route would have forced us to
start handing them out. You already hold the commute id from search.

| Response field | Meaning |
|---|---|
| `id` | For reporting it, if it is about you. |
| `direction` | `passenger_to_driver` here. |
| `stars`, `comment`, `tags` | What was written. `comment` may be `null`. |
| `month` | `YYYY-MM`. **Not a date — see below.** |
| `wasEdited` | Whether the text was changed before it became readable. |

🔒 **There is no reviewer and no exact date, and this will not change on request.** The reasoning,
because it affects what you can build:

> A commute seats one to three people. A review dated to the day identifies the journey, and the
> journey identifies the person — so a precise date names the reviewer even when the payload does
> not. That matters more here than on an ordinary marketplace for one concrete reason: **the driver
> already has the passenger's pickup point.** She knows her front door. A passenger who writes
> honestly about a driver who can find her house, and who can be identified from the date, is
> exposed in a way an anonymous shopper never is — and the result is not fairer reviews, it is
> quieter ones.

So: no avatar, no name, no "reviewed on 12 March". Render the month. If your design needs a
reviewer block, make it an anonymous one.

#### `GET /ratings/about-me` — paginated · what was said about you

The same anonymous shape, and **anonymous to you as well.** Being shown who gave you two stars is
the retaliation vector, not a courtesy. Hidden reviews are not here either — double-blind is
symmetric, and a subject who could read one early would be writing her own rating with knowledge of
it.

#### `POST /ratings/{rating}/report` — "this review is abusive"

**Request:** `reason` (required, 3–255 characters).

🔴 **A report flags for a human and takes nothing down.** The review stays on the profile and stays
in the average until a moderator decides otherwise, and the response says so (`reviewRemains:
true`). **Do not write copy promising removal.** If a report hid the review, "report every review
under four stars" would be a mechanical way to launder a record — and the people most motivated to
do that are exactly the ones a rating system exists to surface.

🔒 Only the **subject** of a review may report it. The reviewer gets a `404` (they wrote it), a
stranger gets a `404`, and so does a review that has not been revealed yet — all three
indistinguishable from a wrong id. `REVIEW_ALREADY_REPORTED` (409) for a second attempt.

#### `minRating` on search — a hard filter

`GET /search/commutes` now takes `minRating` (1–5). It **excludes**, it does not rank — a passenger
who says she will not ride with anyone under four stars is stating a condition.

🔴 **A driver nobody has rated yet is still shown.** `null` means "not rated", never zero, and
hiding new drivers would both starve the platform of them and tell the passenger something untrue.
**Label the control accordingly** — "4+ stars (includes new drivers)" or similar — because
otherwise somebody who ticks it and sees an unrated driver will read it as a bug.

#### What ratings switch on elsewhere

Once a rating is revealed it feeds `rating` in the **public summary (5.1)**, which search results,
match details, group members and the driver's request review all already carry. Those have been
returning `null` since Phase 6 for want of ratings; they will start carrying numbers with no change
on your side.

🔒 Separate averages per direction — a person's rating as a driver and as a passenger are different
numbers on the same account. `null` still means "not rated yet" and **must not be drawn as nought
stars**.

---

## 4.14 Notifications (screen 22)

> The notification list and what the person has chosen to be told about.
>
> **Screens** 22 · **Shapes** —

What the platform tells a member on its own initiative. Every message lands in the **inbox**
(`in_app`) and, if the member allows it, is also **pushed** to each signed-in phone.

> ⚠️ **Push is wired but not delivering yet.** No push provider (FCM/APNs) has been chosen, so the
> server records each push as `no_push_provider` and nothing reaches the phone. **The inbox works
> today** — poll `GET /notifications` and use `meta.unreadCount` for the badge. Send the device's push
> token at sign-in as you already can (`device.pushToken`); it will be used as soon as a provider is
> configured, with no client change.

These four routes sit in the **signed-in** tier, not `active`: a member whose account was just put on
hold must still be able to read the notice that says so.

#### `GET /notifications` — paginated, newest first

| Response field | Meaning |
|---|---|
| `type` | What happened — route on this. Today: `seat_requested` (driver) · `seat_approved` · `seat_declined` · `booking_cancelled` (to the other side) · `commute_paused_seat_cancelled` (the commute was paused, her booked days are cancelled and she is back on the waiting list; `data.commuteId`, `data.bookingId`) · `vehicle_replaced_seat_cancelled` (the same news for a replaced car; same `data`) · `seats_offered_by_driver` (a driver picked her off the waiting list and offered her seats — `data.commuteId`, `data.seatRequestId`; the first acceptance of each day still wins) · `trip_day_cancelled` (the driver called off a day — `data.tripId`, `data.bookingId`) · `match_found` (a saved request matched — `data.commuteId`) · `trip_started` · `chat_message` · `rating_due` (passenger, `data.bookingId`) · `rating_due_riders` (driver, once per run, `data.tripId`) · `rating_reminder` (last day of the window, once) · `sos_picked_up` · `report_resolved` · `report_closed` · `account_on_hold` · `account_reinstated`. **Expect new values**; show an unknown one as a plain message. |
| `category` | `booking` · `payment` · `trip` · `safety` · `marketing`. |
| `title` / `body` | **Already in the member's language** — rendered when the message was sent, and stored as written. Show them as they are. They never contain a phone number, an address or a full name: a push body appears on a lock screen. |
| `data` | Ids for the screen to open: `seatRequestId`, `commuteId`, `tripId`, `sosId`, `incidentId`. Never names. |
| `isRead` / `readAt` / `createdAt` | |

`meta.unreadCount` is the badge, counted over the whole inbox, not the page.

#### `PATCH /notifications/read`

`{ "ids": ["…", "…"] }` or `{ "all": true }`. Returns `{ "unreadCount": n }`. Ids that are not the
caller's are ignored rather than refused (refusing would confirm they exist).

#### `GET /notifications/preferences` · `PATCH /notifications/preferences`

One switch per `category` × `channel` (`push`, `in_app`), each with `enabled` and `canDisable`.

- **Safety cannot be switched off** (Chapter 11). Its switches come back `enabled: true,
  canDisable: false` — draw them on and locked. Sending `enabled: false` for safety refuses the
  **whole** request with `NOTIFICATION_CATEGORY_LOCKED` (422), so the app never shows a switch "off"
  for something still on.
- **Marketing starts off.** Everything else starts on.
- `PATCH` takes `{ "preferences": [ { "category", "channel", "enabled" } ] }` — only the switches that
  changed — and returns the full list.

🔴 **A silent SOS never pushes.** When an operator picks up a discreet alert, the member is told in
the inbox only — a phone that buzzes in a car with the person it was raised about is the one thing the
silent alert promised would not happen. Do not add a local notification for it either.

## 4.15 Trip chat (the "Message" button on screen 41)

> The scoped chat between a driver and a passenger who share a confirmed booking.
>
> **Screens** 41, the Message button · **Shapes** —

One conversation per **booking**, between that day's passenger and driver. Chapter 11 scopes it:
pickup clarification, arrival updates, minor delays — "not intended for long-term messaging". The
prototype's only entry point is the **Message** button beside **Call** on the driver's wait screen;
the passenger side opens the same conversation from the trip.

**When it is open.** From **24 hours before departure** until **2 hours after the run is completed**
(or, if nobody ever completes the run, 12 hours after departure plus those 2 hours). A cancelled
booking has no conversation. All three numbers are platform settings — read `meta.opensAt`,
`meta.closesAt` and `meta.canSend` rather than computing them.

#### `GET /bookings/{booking}/messages` — paginated, newest first

| Field | Meaning |
|---|---|
| `mine` | The caller wrote it. There are only two people; no user ids are returned. |
| `body` | As written. |
| `containsContactInfo` | Always `false` for new messages: a message containing a phone number or an email is now **refused** (`CHAT_CONTACT_INFO_NOT_ALLOWED`, below). Can still be `true` on messages sent before 2026-10-06. |
| `readAt` | When the other person opened the conversation after it arrived. |
| `sentAt` | |
| `meta.canSend` | Whether a message can be sent right now. |
| `meta.opensAt` / `meta.closesAt` | The window. |

**Opening the conversation marks the other person's messages read** — there is no separate call.

#### `POST /bookings/{booking}/messages` — `{ "body": "..." }`, 1–500 characters

`201` with the message. `CHAT_NOT_OPEN` (409, `error.fields.opensAt` / `closesAt`) outside the window.
**`CHAT_CONTACT_INFO_NOT_ALLOWED` (422, `error.fields.body`)** when the message contains a phone number
(Arabic or Western digits, spaced, `+20…`) or an email — nothing is stored or sent. Keep the user's text
in the input so they can edit it, and show `error.message`.
🔒 **The same `CHAT_NOT_OPEN` is returned when either person has blocked the other** — deliberately
indistinguishable, because "you cannot message her because she blocked you" tells somebody they were
blocked. Rate-limited per sender (`429` with `Retry-After`).

The other person gets a `chat_message` notification (4.14) that says **who** wrote, never **what** —
the body would otherwise appear on a lock screen.

#### `POST /messages/{message}/report` — `{ "reason": "..." }`

Only the **recipient** can report a message (404 otherwise). It files an ordinary report with the
safety team, quoting the message, and returns `{ "incidentId" }` — the report then shows in
`GET /incidents` like any other, with its status and the team's answer.

## 4.16 Money (screen 34, the group's `payments` tab)

> A driver's fee balance and a group's weekly statement. Online payment is not built — see 7.
>
> **Screens** 34 · **Shapes** —

🔴 **The fee comes out of the DRIVER's share, at 3%, and the passenger pays the seat price.** That
was settled on 2026-10-06 and it closes the contradiction this file carried for weeks: an 8000 seat
means the passenger owes **8000** and the driver keeps **7760**. If you built anything against the
other reading — a passenger paying 8800 for an 8000 seat — change it.

🔴 **And the consequence your screens exist to make visible:** on a **cash** trip the driver collects
the whole 8000 at the roadside, so the 240 is not withheld from her — it becomes a **debt she owes
the platform.** It accrues trip by trip and eventually stops her publishing. A driver who is only
ever shown "earnings" and then cannot publish has been told nothing.

> ⚠️ **Nothing here moves money.** No payment methods, no card capture, no payouts, no refunds —
> those wait on a payment provider being chosen (section 7). These two endpoints read what the cash
> path has already recorded.

#### `GET /driver/balance`

| Response field | Meaning |
|---|---|
| `outstandingFeePiastres` | What she owes the platform — fees from cash trips she has already been paid for in full. |
| `debtCapPiastres` | The limit. **Read it; do not hard-code it** — it is a runtime setting staff can move. |
| `remainingBeforeBlockPiastres` | The headroom, so you can draw a bar without doing the arithmetic. |
| `isBlockedFromPublishing` | 🔴 Computed from the **current** cap, not from a stored flag. The flag is a projection a nightly job maintains; the cap is a setting. |
| `existingRunsContinue` | Always `true`, and **say it on the screen.** Being over the cap stops her publishing something NEW; every run already booked goes ahead, because the passengers on it did nothing wrong. "Account blocked" would be both frightening and untrue. |
| `blockReason` | Staff's note, when there is one. |
| `lifetimeEarningsPiastres` · `lastSettledAt` | Her side of the ledger. |

A driver who has never completed a cash trip has no balance row, and this answers **zeros rather
than 404** — "no such driver" is the wrong answer to "what do I owe".

🔒 Hers alone. There is no endpoint at any access level that reads another driver's income or debt.

#### `GET /groups/{group}/statement` — optional `?weekOf=YYYY-MM-DD`

One row per journey for the week, plus totals. **Saturday to Friday** — the platform's day bitmask
starts at Saturday and the reference commute runs Sunday to Thursday, so a Monday-start week would
split every working week across two statements.

🔒 **Who sees which rows differs, and it is the query that differs — not a filter you apply.**

| | The driver sees | A passenger sees |
|---|---|---|
| Rows | every passenger's | only her own |
| `passengerFirstName` | the name | `null` |
| `platformFeePiastres` · `driverKeepsPiastres` | both, per row and in the totals | `null` |

A passenger's fellow riders may be on **different prices** after a change, and a statement that
exposed that would turn a lift to work into a negotiation. The fee is out of the driver's share, so
it is not a passenger's business either.

| Row field | Meaning |
|---|---|
| `bookingId` · `tripDate` · `seats` | Which journey. |
| `pricePiastres` | 🔴 What was **agreed**, read from the booking's snapshot — never recomputed from today's price. A driver who raises her price does not retroactively change what somebody already owed. |
| `paymentType` | `cash` or `online`. |
| `paymentStatus` | `NOT_DUE` · `PENDING` · `PAID` · `FAILED` · `REFUNDED` · `DISPUTED`. Cash becomes `PAID` two hours after attendance is confirmed. |
| `wasNoShow` | Still owed — the seat was held and the car went (decision D18) — but flagged so you can show it differently from an ordinary ride. |

`totals` carries `duePiastres`, `paidPiastres` and `outstandingPiastres` (stated, not left as
subtraction — it is the number the screen is opened to find), plus the driver's `platformFeePiastres`
and `driverKeepsPiastres`.

Cancelled and expired bookings are **absent**, not zero-valued: they are not money, they are things
that did not happen. `404` for a group the caller is not in — and for one that does not exist, so
neither is distinguishable from the other.

---

## 5. The one shape every feature shares

Every other response shape is documented in the chapter that returns it. This one is not, because it
belongs to no single feature: it is how **one member appears to another**, and it comes back from
search, member lists, request reviews, attendance rows and wait timers alike.

It is also where most of the privacy rules land, which is the other reason it is described once —
a second copy is a second chance for one of them to be written down slightly wrong.

### 5.1 `person` — how one member appears to another

Used on match results, member lists, request reviews, attendance rows and wait timers. Three
screens showed the same handful of facts, so they come from one place and cannot drift.

| Field | Meaning |
|---|---|
| `publicFirstName` | 🔒 **The only name you ever receive about somebody else.** There is no endpoint that returns a full name, a phone number, an email or a gender of another member. Gender especially: a women-only commute would otherwise be a way to read somebody's gender off a member list. |
| `trustLevel` | `0`–`4`. How many verification levels are approved. |
| `verifiedLevels[]` | **Which** levels, by name (`government_id`, `selfie`, `organization`, `phone`). A level of 2 does not say which two, so a client given only the number has to guess — and guesses wrong the moment the levels are reordered. **Expired levels are excluded.** |
| `sameOrganisation` | 🔴 **A comparison, never a disclosure.** `true` means you and this person have the same **verified** workplace or university. The employer is never named: a badge naming it would tell every passenger where a driver works, which is most of what somebody needs to wait outside it. Both sides must be verified — an unverified org is a text field somebody typed. |
| `rating` | `null` **until ratings exist (Phase 10)**. Null is the honest answer: a driver who has not been rated has no rating, and 0 would show a new driver a zero-star badge nobody earned. **Do not render 0.** |
| `completedTrips` | Completed journeys in the relevant role. |
| `onTimeRate` | `0`–`100`, or `null` until computed. Same rule: show a dash. 🔴 **For a driver this now measures two things**: that she set off within 10 minutes of the departure **and**, where the day carries a promised arrival, that she arrived within **5 minutes** of it. A day with no promise is judged on departure alone — no promise, no breach. For a passenger it is still `null`: nothing computes passenger punctuality yet, and v3 requires the two be calculated separately. |

## 6. Realtime

Decision D6: **Laravel Reverb** over WebSockets, Pusher protocol.

| | |
|---|---|
| Auth endpoint | `POST /broadcasting/auth` with your **bearer token** (not a session cookie) |
| Channel | `private-trip.{tripSessionId}` — the **session** id from `GET /trips/{trip}`, not the trip id |
| Event | `location.updated` |
| Payload | `{lat, lng, recordedAt, accuracyMeters, speedKmh}` |

🔒 **Authorisation is checked per subscription and only while the run is underway.** A subscription
that outlived the journey would keep a socket open onto positions pushed after everybody got out.
You will be refused if you are not the driver or do not hold a live booking on that day.

The payload is **the position and nothing else** — no passenger list, no names, no booking ids. A
channel payload is the easiest thing in a system to end up logged by a proxy.

**Always implement the polling fallback** (`GET /trips/{trip}/location`). The situation a live map
is for — a bad connection at a bus stop — is the same situation where a socket does not open.

⚠️ **Not yet verified end to end.** Reverb is installed and the server dispatches the event, but
local config is `BROADCAST_CONNECTION=log`; the path is proven by an automated assertion rather
than against a running Reverb instance. Coordinate with us before you build against it.

---

## 7. Not available yet

Nothing below exists. Build the screen shells if you like, but there is no endpoint.

| Area | Screens | Phase | What is missing |
|---|---|---|---|
| **Payments and wallet** | 34, group `payments` tab | **8** | **In progress.** Cash is now recorded (`paymentStatus` → `PAID`, see 4.7) and the driver's fee debt is tracked, with a publishing cap. Still to come: the driver's balance screen, the group's weekly statement, then — once a payment provider is chosen — payment methods, online capture, payouts and refunds. Cancellation fees wait on the cancellation policy. |
| **Driver cancelling one day** | 43, 45, 46 | **9** | A driver cancelling a single day and the backup search that follows. **Blocked on an open decision** — refunds and reliability, section 8 #3. Route deviation itself is now detected and reported (see `deviationDetectedAt`); the ops ALERT it should trigger needs Phase 12/13. |
| **Public trust tier** | badge on 12 and 21 | **10** | 🔴 **Ratings and reviews are both done** — section 4.13. Rating, the double-blind reveal, the anonymous review list on a commute, "what was said about me", reporting an abusive review and the `minRating` search filter are all live, and the `rating` in every public summary carries a number once a rating is revealed. The only piece left is the **public trust tier** (`new` / `trusted` / `highly_trusted`), which needs a product decision on its thresholds. The score behind it is deliberately internal and will never be returned. |
| **Auto-share trips** | 26 | **12** | 🔴 **`autoShareTrips` on an emergency contact is stored and nothing acts on it yet.** See 4.12 — read that before you build the toggle. |
| **Night escort mode** | none | **13** | Not a mobile feature at all, and this corrects an earlier line in this file. It is a **corridor-level night window armed by the ops team** (or automatically, 9pm–5am) with staff monitoring — a dashboard control, not something an app shows or calls. There will be no endpoint for it. |
| **Push delivery** | all | **12** | The inbox (4.14) and trip chat (4.15) are live. Push is wired but **no provider has been chosen yet**, so nothing reaches a phone — poll `GET /notifications` (`meta.unreadCount`) and the open conversation in the meantime. |

---

## 8. Open questions that could change this contract

Do not design around either side of these. Ask before you build the screen.

| # | Question | Affects |
|---|---|---|
| 1 | ~~**Which way does the platform fee go, and what is the rate?**~~ ✅ **Decided 2026-10-06:** deducted from the driver's price, 3% by default, changeable by operations. Screens 12 and 24 need new copy (see 4.7). | 12, 24, all money |
| 2 | ~~**`firstName` + `lastName`, or one `fullName`?**~~ ✅ **Closed 2026-10-16: send either.** The endpoint now accepts `firstName` + `lastName` **or** `fullName`, so build the two-field screen the design draws and keep any existing `fullName` client working. *(This was briefly recorded as "one `fullName`" on the strength of F02's prose; reading the actual prototype markup showed two real inputs, `L_firstName` and `L_lastName`, so the design won and the server now takes both.)* `lastName` is optional — a single name is valid. | 7 |
| 3 | ~~**A single day cancelled by the driver**~~ ✅ **Decided and built 2026-10-06:** `POST /trips/{trip}/cancel` (4.9). | 23, 46 |
| 4 | **"5 min late" as a third declared attendance state.** Screen 36 has the button; the model has `coming`/`away` only. It is also exactly what a wait timer exists to act on. | 36, 41 |
| 5 | **`Seat belts (all seats)`** is not modelled anywhere, and **`Air conditioning`** is per commute, not per vehicle as screen 31 has it. | 31 |
| 6 | **Attendance cut-off**: screen 20 says a fixed 9 PM; the implementation uses two hours before departure. Two different models. | 20, 36 |
| 7 | **The group `calendar` tab** is designed but was never built in the prototype. | 20 |
| 8 | **Role switching** (passenger ⇄ driver) on screen 21 needs a product decision. | 21 |
| 9 | **Waitlist copy**: screen 20 says seats fill "automatically". They do not — the promotion goes to the driver's inbox as a decision, because she decides who rides in her car. **The screen text needs to change.** | 20 |

---

## 9. Change log

Newest first. **This file is updated with every new endpoint and every phase completed**, and
`tests/Feature/MobileApiGuideTest.php` fails the build if it drifts: every endpoint named here must
exist, and every live endpoint must be named here.

| Date | Change |
|---|---|
| 2026-10-23 | 📉 **The Reliability Score exists, so a cancellation finally costs something.** Until now a driver could cancel freely and no number moved: the stars measure the ride, not whether she turns up. `GET /account/reliability` returns **her own** standing — two separate scores, as driver and as passenger — with **every event behind it**, because v5 routes a low score to a monthly review, a suspension and a ban, and nobody should learn their score at the review. Recorded today: a driver cancelling one seat, cancelling a day, or displacing passengers by pausing / replacing her vehicle (one event per displaced passenger); a passenger's no-show, or a cancellation close to departure. A late **switch** counts exactly as a late cancellation — otherwise switching the night before was a way to dodge it. A driver-delay exit costs nobody anything. An approved appeal **reverses** an event: it stops counting and stays readable. ⚠️ **The weights are placeholders pending a product decision** — do not build a UI that implies a threshold. 🔒 No endpoint returns anybody else's, and it is not on public profiles. |
| 2026-10-23 | 🚪 **The three ends of a shared journey.** `POST /trips/{trip}/drop-off` sets **one** passenger down while the run carries on — her booking completes immediately, which is what lets her rate a ride she has finished instead of waiting for somebody else's journey to end, and her stop leaves the live route. `POST /bookings/{booking}/end-early` is the **passenger's safety exit**: no reason required, no driver confirmation, booking becomes the new **`ENDED_EARLY`** status, no fee, and her access to the car's live position ends at once along with any share link she sent. `POST /trips/{trip}/still-driving` pushes back the auto-close by 15 minutes, as often as she needs. ⚠️ A run is **only** closed automatically when nobody is aboard and nobody is still awaiting a decision — a `PENDING` attendance row blocks it, because closing over one would freeze a decision nobody made. |
| 2026-10-20 | ⭐ **Canceled Booking Feedback.** A passenger whose confirmed seat the **driver** cancelled — including by pausing the commute or replacing the vehicle — can now rate her even though no ride happened. Same endpoint; ratings carry a new **`kind`** of `completed_ride` or `canceled_booking`, and the second behaves differently in three ways: **visible immediately** (no second rating to wait for, so do not show it as pending), **editable while visible**, and **excluded from her star average** (v5 displays it distinctly and gives cancellations their own instrument). Public profiles gain a **`canceledBookingFeedback`** block beside `reviews` — keep them separate on screen. A passenger who cancelled her own seat still cannot rate anybody, and nor can the driver on a booking she cancelled: both `422 TRIP_NOT_RATEABLE`. The feedback window runs from the cancellation, not the departure. |
| 2026-10-20 | 🤝 **A published commute's times and stops can now change — with every affected passenger's yes.** Six endpoints under `change-proposals`: the driver sends one combined proposal (`departureTime` · `promisedArrivalTime` · `pickupPoints`, scoped to one morning or to that weekday from a date), each affected confirmed passenger answers `accepted` / `rejected` / `withdrawn`, and **the answer that completes unanimity applies it immediately** — there is no separate Apply step. 🔴 **No partial accept. Silence changes nothing. One refusal ends it. A revision bumps `version` and resets every answer already given** — warn before revising, and re-read before letting somebody answer. A passenger confirmed *after* the proposal went out must also approve. `all_future_on_this_weekday` clears the whole waiting list once applied (`TERMS_CHANGED` + `waitlist_cleared_by_change`), even members that still look compatible — and clears nothing if it is rejected. 🔒 A passenger is never shown how the others answered. `PUT /commutes/{commute}/route` and `/schedule` still answer `409 COMMUTE_NOT_EDITABLE` on a published commute; this is the only path. |
| 2026-10-20 | 🔒 **A day stops changing 16 hours before it departs.** Approving or accepting a pickup-point change on a day inside that window answers `409 COMMUTE_SCHEDULE_LOCKED`; approving or accepting a **seat** whose rider would land the run past the arrival the passengers on board were promised answers `409 BOOKING_BREAKS_FROZEN_PROMISE`, with the projected and promised times in `error.fields`. ⚠️ A locked day nobody has booked is not locked — the rule protects the people who planned around it. A day with no `promisedArrivalTime` is judged on capacity and detour alone. And note what did **not** change: a published commute's route and schedule were already unchangeable, so they are frozen from publish rather than 16 hours out. v5's post-lock *departure change by unanimous approval* is **not built** — it presupposes an editing capability that does not exist here, so it is a feature to build, not a restriction to add. |
| 2026-10-20 | 🤝 **The driver can counter a request's meeting point, and the passenger has to accept it in the app.** Three endpoints: `POST /driver/seat-requests/{seatRequest}/counteroffer` (either point or both, with a note), and `/seat-requests/{seatRequest}/counteroffer/accept` · `/decline` for her answer. 🔴 **Nothing moves until she accepts** — the request sits at `COUNTEROFFERED`, no booking, no seat taken, and only the end the driver actually moved is proposed. Accepting **confirms the seat** and returns the approval, but can still answer `409 SEAT_UNAVAILABLE`: the counteroffer promised a meeting place, never a free seat. Declining closes it as `COUNTEROFFER_DECLINED` — its own status, so the refusal lands on neither person's record as something they did not do. Agreed points from a counteroffer carry `source: driver_counteroffer`. 🔒 **Chat is not consent** — v5 says so in terms; never treat a message thread as the agreement. |
| 2026-10-20 | 🗺️ **The driver's map.** `GET /driver/commute-occurrences/{trip}/navigation-route` returns the ordered stops she actually drives — **2 + 2N** points: her start, each confirmed passenger's two agreed points, her end — with a routed ETA per stop and the `View Route in Google Maps` link. 🔴 **Do not assume the order is `Start → A pickup → A drop-off → B pickup → B drop-off → End`:** stops are ordered by position along her route, so shared journeys interleave; the only invariant is that each passenger's pickup precedes her own drop-off. Render `stops` as given. 🔴 **`googleMapsDirectionsUrl` can be null** when the run has more stops than one Maps link carries — use the overlapping `segments` instead; we will not hand you a link Maps would silently truncate. `GET /driver/seat-requests/{seatRequest}/route-preview` shows what accepting a pending request would do, marked `PREVIEW_NOT_CONFIRMED`, writing nothing. Both are driver-only: the payload is a list of other riders' front doors. |
| 2026-10-19 | 📍 **Every seat request now names a pickup AND a drop-off, and both are required.** `POST /commutes/{commute}/seat-requests` takes `pickup` and `dropoff`, each as either `{suggestionId}` or `{lat, lng, label}`. Neither may be omitted and neither is inferred from the driver's route any more — the drop-off previously did not exist at all and was assumed to be the end of her route. Each comes back with a `source` (`driver_suggestion` / `custom_map`); **show the difference.** A commute advertising no suggested points is still requestable — fall back to the map. Four new refusals: `SEAT_REQUEST_POINTS_SAME`, `SEAT_REQUEST_POINTS_OUT_OF_ORDER`, `SEAT_REQUEST_SUGGESTION_UNKNOWN`, and `PICKUP_DETOUR_TOO_LONG` measured for the **pair together**. Bookings now carry the agreed snapshot, so `dropoffPoint` joins `meetingPoint` — both fuzzed, and both with the label withheld, until the booking is confirmed. |
| 2026-10-19 | ⏸️ **Pausing a commute now cancels its future confirmed bookings, and so does replacing a vehicle.** 🔴 **Both reverse behaviour this file documented.** Pausing used to leave booked days untouched; replacing a car used to keep the bookings and send a `booking_vehicle_changed` notice. Neither holds: a paused commute is not running, and a replacement car is unverified, so those seats were promises we already knew we could not keep. Displaced passengers go onto a preserved waiting list with a **Previous Passenger** badge and are told once each (`commute_paused_seat_cancelled` / `vehicle_replaced_seat_cancelled`). **`booking_vehicle_changed` is gone — remove any handling for it.** Pause needs a confirmation step naming the number of seats it cancels. A run already underway is never touched. |
| 2026-10-19 | 🎟️ **On resume, the driver chooses who gets a seat** — the one exception to the automatic fan-out. Three new endpoints: `GET /driver/commutes/{commute}/waitlist` (cards carrying `isPreviousPassenger`, `commitment`, `hasOtherConfirmedCommute`), `POST /driver/commutes/{commute}/waitlist/offers` (`all: true` or `seatRequestIds`), and `DELETE /driver/seat-offers/{offer}` to take back an **unaccepted** offer. Her choice decides who may race, not who wins; each day is still settled by server transaction order. New notification `seats_offered_by_driver`, one per member for the whole set of days. |
| 2026-10-16 | ⏱️ **A driver`s `onTimeRate` now measures arrival as well as departure, and says how it was computed.** She counts as on time when she sets off within 10 minutes **and**, where the day carries a `promisedArrivalTime`, arrives within **5**. A day with no promise is judged on departure alone — no promise, no breach. New on a public profile`s `asDriver`: **`onTimeMetricVersion`** and **`onTimeSampleSize`**, because two drivers at "88%" under different rules are not comparable and 88% over nine runs is not the claim 88% over four hundred is. 🔴 **And a defect this fixed:** the driver rate shown to passengers was read from a column no production code ever wrote, so a real driver`s computed figure was never displayed — it sat at `null` for ever while this file said "null until computed". It is now the number we actually compute. |
| 2026-10-16 | 🔄 **Switching a seat works, on the offer you already accept.** `POST /seat-offers/{offer}/accept` takes three optional fields — `switchFromBookingId`, `switchReason` (`voluntary` or `driver_delay`) and `joinOldWaitlist`. One transaction, so there is **never** an instant with two confirmed seats and never a way to lose both; if she already holds the target day it answers `409 BOOKING_ALREADY_BOOKED` and nothing moves. `driver_delay` charges **no cancellation fee** and records the old booking as cancelled **by the driver**, so a driver running late does not land on the passenger record. `joinOldWaitlist` rejoins the old list **without that driver approving again** (she already had a seat in that car) and **reserves nothing**. A plain acceptance still sends an empty body. **Not built:** a standalone switch endpoint on the booking itself, and recurring/future-weekday scope — single date only, because a half-built recurring scope would silently release days she still wanted. |
| 2026-10-16 | 🎟️ **A freed seat is now OFFERED to everybody on the waitlist, and the first acceptance wins.** Two new endpoints: `GET /seat-offers` and `POST /seat-offers/{offer}/accept`, which returns the **booking** — no second driver approval, because everybody on the list was already approved when she waitlisted them. **So your screen 20 was right: the waitlist does now fill empty seats automatically.** It previously promoted one person into the driver inbox as a fresh decision, which could leave a seat empty for 48 hours waiting on one reply. ⚠️ **`waitlistPosition` is now an ESTIMATE** — keep showing it, never say it entitles her to the next seat; position only decides who is notified first, and number 2 can win. A loser gets `409 SEAT_OFFER_CLOSED` with `error.fields.status`: show **TAKEN_BY_OTHER** ("somebody was quicker") differently from **CLOSED** ("the seat stopped existing"). |
| 2026-10-16 | 📅 **Each weekday of a commute can now have its own departure, promised arrival, price and capacity** (Feature 03). `PUT /commutes/{commute}/schedule` takes two new optional fields — `promisedArrivalTime` and `perDay`, keyed by the day's bit. **Sending neither behaves exactly as before**, so nothing you have built changes. Each day's occurrences are generated from that day's own values and snapshot them, so a cheaper Thursday produces cheaper Thursdays while the rest of the week is untouched. Re-sending the schedule **replaces** the week rather than merging. ⚠️ A `promisedArrivalTime` is a commitment: arriving later than it counts against the driver's on-time rate outside a five-minute tolerance, so omit it rather than guessing. |
| 2026-10-16 | 🔒 **New: `boardingCode`** — a four-digit number on every booking, for the line your design draws under the car: `Plate ﺱ 4821 · PIN 7391`. It answers **"is this the right car?"**, which the passenger asks at the kerb while comparing the plate. **Both sides see the same number** (otherwise nobody could confirm it): the passenger on `GET /bookings/{booking}`, the driver there and on `GET /trips/{trip}/attendance`. One per journey, so it changes daily. 🔴 **It decides nothing** — `check-in` takes no code and never will. Attendance is the driver's tap (decision D18); the Master Plan rejected a code for that job outright, because "a PIN fails when somebody is running for the car". Do not block boarding, payment or no-show on it: a flat battery must never lock somebody out of a car she has already verified by plate and sight. It appears in no payload other passengers can read. |
| 2026-10-16 | ✅ **New: `GET /driver/verification`** — the five-item checklist the driver card is drawn from (`government_id` · `selfie` · `driving_licence` · `vehicle_registration` · `vehicle_details`), with `completed` / `of`, a per-item `status`, a **per-item `rejectionReason`** so you can say "replace only that document", and one of seven `overall` headlines matching the design's card states. Reachable **without** verification, because it is the screen that explains what is missing. `expiring` keeps the verified badge; `suspended` outranks everything. ⚠️ `completed` can read 4 while `overall` is `verified` — approval does not currently require a selfie, and we have flagged that rather than hiding it. |
| 2026-10-16 | ✅ **`PUT /account/profile/basic` now takes `firstName` + `lastName`, as the design draws it** — or `fullName`, exactly as today. Nothing breaks, so build the two-field screen whenever you like. `lastName` is optional (a single name is a real name). When you send the two fields, `publicFirstName` becomes the **given name** rather than the first token of a joined string — which is the difference between showing "عبد الرحمن" and showing "عبد". |
| 2026-10-16 | ✅ **New: `GET` / `PATCH /account/comfort-preferences`** — the comfort chips you want remembered, **per mode**, so a passenger stops re-ticking them on every search. They are **the same six values a search already accepts** (`nonsmoking` · `quiet` · `ac` · `nofood` · `luggage` · `front`), so a saved list goes straight into `requiredRules` with no translation. `chosen` tells "never answered" apart from "none of them". 🔒 **Saving a default filters nothing** — the chips are a soft `score.comfort`, never an exclusion, and a test holds it. *(An earlier draft of this entry described four fields named `smoking`/`music`/`conversation`/`airConditioning` with `yes`/`no`/`either` values. That shape was replaced before release: it invented a second vocabulary for chips the matcher already understood, and nothing read it. If you built against it, switch to `rules[]`.)* |
| 2026-10-16 | 🚗 **Vehicle replacement works, and it used to be impossible.** An approved driver could not add a vehicle at all — it answered `409 DRIVER_APPLICATION_LOCKED` — so she could not change her car without losing her approval first. Now she adds a replacement, it is reviewed on its own while her current car keeps working, and activating it moves her commutes across. 🔴 `POST /driver/vehicles/{vehicle}/activate` now **refuses an unapproved vehicle** with `422 VEHICLE_NOT_ALLOWED`, and refuses the whole swap with `422` if a live commute sells more seats than the replacement has — nothing is half-applied. Confirmed future bookings are **kept**, and each passenger gets the new `booking_vehicle_changed` notification **naming the car and plate**. |
| 2026-10-16 | ✅ **New: `GET /users/{user}/public-profile`** — another member's profile as a stranger sees it, which is what a passenger opens to check out her driver. Public handle, avatar-or-initials, join month, per-role trips/rating/on-time, and the five most recent reviews. `isVerifiedDriver` is **true only when a reviewer actually approved her**. A suspended account answers the same `404` as an id that never existed. No `area` field, and never a full name, phone, email, date of birth, gender or document. Readable by an unverified passenger on purpose — checking out your driver is the thing we most want you to do. |
| 2026-10-16 | 📌 **Three product answers now fixed, so you can build against them.** (1) **On-time tolerance is 5 minutes** — a pickup counts as on time within 5 minutes of the agreed time. The metric publishes `metricVersion` and the raw counts beside the percentage, so if the tolerance ever changes you can tell that it did. (2) **When a driver replaces her vehicle, confirmed future bookings are KEPT, not cancelled** — every affected passenger is notified, and 🔴 **the notification carries the new vehicle**, because the plate she was shown has changed and that is what she needs before standing on a kerb at 7am. (3) **Trusted contacts stay at 5**, not the 3 in the design docs — nobody loses a contact they already saved. |
| 2026-10-16 | 🟢 **Passenger identity verification is GONE. A passenger now books with a verified phone number and nothing else.** Feature 02 v3: "No passenger verification." 38 endpoints moved from the `verified` tier to `active, profile complete` — seat requests, bookings, pickup points, groups, the live trip, wait timers and the money views. **This only ever removes a 403, so nothing you have built breaks**; if you were sending people to the verification centre before letting them book, you can stop. `VERIFICATION_REQUIRED` is now returned **only** by the driver surface. 🔒 The driver side is unchanged and still needs `government_id` plus admin approval plus a verified vehicle — the person carrying strangers is checked, the person riding is vouched for by the driver who agreed to carry her. Still build screens 15 and 30: verification drives the home banner, the trust level a driver sees when deciding on a request, and her optional trust filters. |
| 2026-10-16 | 📖 **Reorganised feature by feature. No contract change — nothing you call behaves differently.** 4.1 to 4.16 are now one chapter per feature, and each stands on its own: the response shapes that used to sit together in section 5 have moved into the chapter that returns them, so you no longer read two places a few hundred lines apart to understand one call. Every chapter starts with a card naming its screens and what else it needs. `person` stays in **5** because it belongs to no single feature. **Section numbers did not change** — any link or note you have to 4.9, 6, 7 or 8 still resolves; only references to the old 5.2–5.10 were repointed to the chapters that now own those shapes. |
| 2026-10-13 | ✅ **Additive — choosing a role is now its own step, and `capabilities` tells you what an account may do.** Three new endpoints: `POST /account/initial-role`, `GET /account/onboarding-state`, `PATCH /account/preferences`. `registeredRole` on `PUT /account/profile/basic` is **now optional** — keep sending it and you still go straight to `HOME`, exactly as today; omit it and you get the new `nextStep: ROLE_SELECTION`. **Nothing you have built breaks.** New on `user`: `capabilities[]` and `initialRoleSelected` — drive the UI from `capabilities`, because `registeredRole` is now history and can legitimately disagree with it. 🔴 `ROLE_SELECTION` is returned by the profile endpoint **only**; a cold start with no role answers `BASIC_PROFILE` by design (Feature 01 §5), so re-submitting the profile is the way forward and not a loop. |
| 2026-10-13 | 🔴 **BREAKING — the local PIN is gone, and `nextStep` changed with it.** Feature 01 v3 §10 removes the PIN, biometric login and account switching from the MVP, so screens 2, 3 and 4 go too. **`nextStep` now has four values: `PHONE_OTP` · `RESTRICTED_ACCOUNT` · `BASIC_PROFILE` · `HOME`.** The old `CREATE_PIN` and `LOCAL_SECURITY_SETUP_OR_HOME` existed only to serve the PIN; `COMPLETE_PROFILE` is renamed `BASIC_PROFILE` and `ACCOUNT_SUSPENDED` is renamed `RESTRICTED_ACCOUNT` (the **error code** `ACCOUNT_SUSPENDED` is unchanged). Also gone: `pinLength` on auth responses, `hasLocalPin` on the device payload and request, and `purpose: "pin_reset"` (now `422`). `hasLocalPin` sent by a client is **ignored, not refused**, so you can remove it from the app on your own schedule. `biometricEnabled` survives as a display-only preference. |
| 2026-10-07 | **Fixed: `GET /search/commutes` 500 "Latitude out of range"** (your bug report). The seeded commutes carried a malformed route; they are repaired, and a commute whose stored route cannot be read is now **skipped** instead of failing the whole search. **A `500` no longer carries internal exception text** in `error.message` — it is the generic localised sentence. **Home `topMatches` now fills:** saving a request (`POST /commute-demands`) matches it against commutes already running, not only against ones published later (no `match_found` notice for those — the person has just seen them). Test accounts were re-seated on upcoming trips, so `nextJourney` is populated again. |
| 2026-10-06 | **Money — 2 endpoints** (`GET /driver/balance`, `GET /groups/{group}/statement`). Section 4.16. 🔴 **The fee comes out of the driver at 3% and the passenger pays the seat price** — settled, and it closes the contradiction this file carried: an 8000 seat means the passenger owes 8000 and the driver keeps 7760. On a cash trip the fee becomes a **debt the driver owes**, which eventually stops her publishing. Nothing here moves money; capture, payouts and refunds still wait on a provider. |
| 2026-10-06 | **Correction:** the new English summary said 116 endpoints against a real 124 — the header was right and the summary was not. Fixed, and a test now checks every endpoint count in the prose rather than only the one in the header, which is how the two disagreed. |
| 2026-10-06 | **This file is now entirely in English.** The Arabic summary became an English one with the same content; no section was dropped. Two stale claims fixed while translating: the summary still listed ratings as not served, and section 7 still said the profile review list and review reporting were to come — all three have been live since 2026-10-02. |
| 2026-10-06 | **Added to 2.1: the local PIN, concretely** — client-side guidance, explicitly not a contract. Store the refresh token encrypted under a key DERIVED from the PIN rather than storing the PIN or a hash of it, so there is nothing on the device to run guesses against. Plus the states usually missed: `pinLength` comes from the server, a dead refresh token must not be reported as a wrong PIN, and a reinstall deliberately starts without a PIN. Nothing was removed. |
| 2026-10-06 | **Correction:** `user.profileStatus` is `NOT_STARTED` · `BASIC_COMPLETE` — this file wrongly said `PHONE_ONLY`; the API never changed (4.2). `accountStatus` also lists `PENDING_DELETION`. The basic-profile request now lists every allowed value and the `YYYY-MM-DD` date format (4.2). |
| 2026-10-06 | **Phase 8 starts — cash is now recorded.** A cash booking's `paymentStatus` turns `PAID` about two hours after the driver confirms the passenger travelled, and `DISPUTED` when the passenger contests the record (4.7). New refusal on `POST /commutes/{commute}/publish` and `/resume`: `DRIVER_DEBT_LIMIT_REACHED` (403) when a driver owes more than the limit in platform fees from cash trips; existing rides continue. No new endpoints. |
| 2026-10-06 | **New: `POST /trips/{trip}/cancel` — "Cancel today" (screen 23, section 4.9).** A driver calls off one day before it starts; every passenger on it is told (new notification type `trip_day_cancelled`), bookings end as `CANCELLED_BY_DRIVER`, nothing is charged. |
| 2026-10-06 | **Two product decisions.** (1) **The platform fee is deducted from the driver's price**, 3% by default and changeable by operations — screens 12 and 24 need new copy (4.7). (2) A driver may cancel a single day — `POST /trips/{trip}/cancel`. |
| 2026-10-06 | **Trip chat now refuses phone numbers and emails** (4.15): `POST /bookings/{booking}/messages` answers `422 CHAT_CONTACT_INFO_NOT_ALLOWED` and stores nothing. `containsContactInfo` stays in the shape and is always `false` for new messages. |
| 2026-10-05 | **Five more notification types** (4.14): `booking_cancelled`, `match_found` (your saved request matched), `rating_due` / `rating_due_riders` when a run completes, and one `rating_reminder` on the last day of the rating window. No endpoint changes. `autoShareTrips` is still inactive — it needs an SMS/WhatsApp provider, not the in-app inbox (4.12). |
| 2026-10-05 | **New: section 4.15 — trip chat.** Three endpoints: read a booking's conversation, send, report a message. Open from 24h before departure to 2h after the run; a blocked pair gets the same `CHAT_NOT_OPEN` as a closed window. Numbers and emails are allowed but flagged (`containsContactInfo`) — warn before acting on them. |
| 2026-10-05 | **New: section 4.14 — notifications (screen 22).** Four endpoints: the inbox with `meta.unreadCount`, mark-read, and the preference switches. Every message is already in the member's language. **Push is wired but no provider is configured yet**, so poll the inbox for now. Safety switches cannot be turned off, and a silent SOS never pushes. |
| 2026-10-05 | **Screen 35 is buildable in full.** Staff can now put an account on hold from the dashboard, and `GET /auth/me` returns `user.suspension` (`caseNumber`, `reasonCode`, `suspendedAt`, `reviewDueAt`) while it lasts; `suspensionReason` is now a ready-to-show sentence in the request's language. The `ACCOUNT_SUSPENDED` refusal carries the same four values in `error.fields`. Also: a driver on hold disappears from search until the hold is lifted. |
| 2026-10-05 | **No new endpoints — two existing fields now actually fill.** The operations dashboard can pick up an SOS and work a report, so `respondedAt` on an SOS stops being permanently `null`, and `GET /incidents/{incident}` now returns `RESOLVED`/`CLOSED` with a `resolution` the safety team wrote **to the reporter**. Read the `resolution` row in the incidents section: it is a message, not a label. |
| 2026-10-03 | 🔴 **Correction — `nextStep`.** This file listed `COMPLETE_PROFILE`, ~~VERIFY_IDENTITY~~ and ~~HOME~~; only the first is real. There are four values (`ACCOUNT_SUSPENDED`, `CREATE_PIN`, `COMPLETE_PROFILE`, `LOCAL_SECURITY_SETUP_OR_HOME`) and **verification is not one of them** — it is a per-endpoint gate, not a sign-up step. If you built a branch on VERIFY_IDENTITY or HOME, it never fires. Section 2.1 has the precedence order; a test now pins the list against the enum in both directions. |
| 2026-10-03 | **New: section 2.1 — the whole cycle, request by request.** Every call in order from sign-in to rating, both roles, with the refusals worth rendering properly and the seeded staging accounts to run it against. Nothing was removed; it sits after the build order. |
| 2026-10-02 | **Reviews and the rating filter** — 3 endpoints (`GET /commutes/{commute}/reviews`, `GET /ratings/about-me`, `POST /ratings/{rating}/report`) plus `minRating` on search. 🔒 **Reviews carry no reviewer and no exact date** — read 4.13 for why, it changes what your UI can show. A report flags for a human and takes nothing down. |
| 2026-10-02 | **Ratings** — 4 endpoints. Section 4.13. 🔴 **Double-blind:** nothing in any payload says whether the other person has rated, the rating window closes when the reveal happens, and an edit is refused the moment the rating becomes visible. Side effect you get for free: `rating` in the public summary (5.1) starts carrying numbers on search, match details, group members and the driver's request review. |
| 2026-10-02 | **Two corrections to this file, both mine.** (1) `autoShareTrips` was described as already sharing every trip. It is stored and acted on by nothing — section 4.12 now says why it needs Phase 12 and what to put on the screen meanwhile. (2) Escort mode was described as a contact watching a journey. Reading the sources, it is a **corridor night window armed by the ops team** — a dashboard control with no mobile surface and no endpoint coming. |
| 2026-10-02 | **Evidence on a report** — 2 endpoints (`POST`/`GET /incidents/{incident}/evidence`). Section 4.12. 🔴 **Images only** (video and audio are refused, with the reason given) and **nothing can be un-attached** — confirm before uploading. No path, URL or hash is ever returned, so keep your own local copy if the screen needs a thumbnail. |
| 2026-10-02 | **Share Live Trip** — 3 endpoints (`POST /trips/{trip}/live-share`, `GET /safety/live-shares`, `DELETE /safety/live-shares/{share}`) plus the public page `GET /s/{token}`, which is **not** in the OpenAPI document and is not yours to call. Section 4.12. 🔴 **`url` and `token` are returned once and are unrecoverable** — read that subsection before you write the share button. |
| 2026-09-29 | **Safety Centre** — 12 endpoints: SOS (and its cancel), trusted contacts, reports, blocking. Section 4.12. All in the **signed-in** tier: no verification gate, no complete profile. Screens 16, 26, 32 and 44 move from ⛔ to ⚠️/✅. |
| 2026-09-29 | **Route deviation** detected and reported on the trip session — `deviationDetectedAt` and `deviationDistanceMeters` on `GET /trips/{trip}` (screen 39). No new endpoint. |
| 2026-09-28 | **First version.** 92 endpoints. Phases 0–7 complete. Phase 9 (trip lifecycle) through its fourth slice: start/advance/complete, attendance with the dispute window, wait timers, live location with the Reverb channel. |
