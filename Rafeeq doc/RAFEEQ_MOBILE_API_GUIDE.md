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

**Last updated:** 2026-10-03 · **116 endpoints live** · Phases 0–7 complete, Phase 9 in progress

---

## ملخص بالعربي

### إيه الجاهز دلوقتي

**١١٦ endpoint شغّالين ومختبَرين** (1,373 اختبار كلهم خضرا). يعني من الـ٤٧ شاشة:

| | عدد | التفاصيل |
|---|---|---|
| ✅ **جاهزة بالكامل** | ~٢٦ شاشة | الدخول والتسجيل كله · التوثيق · السائق والعربيات · نشر الرحلة · البحث والمطابقة · طلب المقعد · المجموعة · الرحلة الحيّة · **مركز الأمان** (SOS · جهات الطوارئ · البلاغات · الحظر · مشاركة الرحلة) |
| ⚠️ **جاهزة وناقصها حقل أو حقلين** | ~٨ شاشات | مكتوب تحت بالظبط الناقص إيه في كل واحدة |
| ⛔ **مش جاهزة** | ~١١ شاشة | التقييمات · الإشعارات · المدفوعات · إلغاء السائقة ليوم واحد — كل واحدة مكتوب جنبها المرحلة. **الأمان بقى جاهز** (SOS · جهات الطوارئ · البلاغات · الحظر · مشاركة الرحلة المباشرة) |
| 🚫 **مش محتاجة API** | ٤ شاشات | حالات جهاز أو نص ثابت |

### ابدأ منين

الترتيب المقترح تحت في **Build order**، وملخصه: الدخول (5→6→7→8) ← التوثيق (15، 30) ← الصفحة الرئيسية (9) ← البحث (10، 11، 12) ← طلب المقعد (13، 14) ← الرحلات والمجموعة (19، 20) ← بعدين السائق (23، 24، 25، 31، 28، 29) ← وآخر حاجة الرحلة الحيّة (17، 36، 40، 41).

### حاجات مهمة تعرفها قبل ما تبدأ

1. **كل رد في نفس الشكل** (`success` · `data` · `meta`؟). الأخطاء برضو شكل واحد فيه `error.code` — اعمل `switch` على الـ `code` مش على النص.
2. **الفلوس كلها قروش** (`*Piastres`) — أعداد صحيحة، مش كسور. 8000 = 80 ج.م. عمرك ما تقسم على 100 وتخزّن النتيجة.
3. **الأوقات UTC بصيغة ISO 8601**، ومعاها الوقت المحلي لما يكون مهم للعرض. أي حاجة اسمها `*Local` هي ساعة حيطة للعرض بس — عمرك ما تحسب بيها.
4. **الأيام bitmask**: السبت 1 · الأحد 2 · الاتنين 4 · التلات 8 · الأربع 16 · الخميس 32 · الجمعة 64. "الأحد للخميس" = 62.
5. **الخصوصية مبنية في الـ API مش في الشاشة.** التليفون والاسم الكامل والجنس **عمرهم ما يرجعوا** لشخص تاني. ونقطة الالتقاء بترجع **مضبّبة** لحد ما الحجز يتأكد. مش محتاج تخفي حاجة في الواجهة — إحنا مش بنبعتها من الأصل.
6. **كل List بتكبر مع الاستخدام معمولة paginate.** استخدم `meta.hasMore` مش عدد الصفوف.
7. **`null` معناها "مش معروف" مش صفر.** تقييم `null` = الشخص ما اتقيّمش لسه، **ماتعرضهاش صفر نجوم**.

### الفايل ده بيتحدث

مع **كل endpoint جديد وكل مرحلة تخلص**. وفيه اختبار (`tests/Feature/MobileApiGuideTest.php`) بيتأكد إن كل endpoint مذكور هنا موجود فعلًا، وإن مفيش endpoint شغّال مش مذكور — يعني الفايل ده **مايقدرش يتعفّن**. آخر تحديث مكتوب فوق، وسجل التغييرات في آخر الفايل.

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

**Three access tiers.** A 403 is not always a bug:

| Tier | Means | Reachable with |
|---|---|---|
| public | no credential | OTP + refresh only |
| signed-in | valid token, account may be suspended or profile half-finished | `auth/me`, logout, devices, consents, verification centre |
| active | not suspended, profile complete | everything else |

`ACCOUNT_SUSPENDED` and `ACCOUNT_PROFILE_INCOMPLETE` are **403, deliberately not 401** — the
session is fine, so do not throw it away and restart at the phone screen.

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
| 3 | 2, 3, 4 — PIN, accounts, forgot PIN | Local storage plus refresh. The PIN never leaves the device. |
| 4 | 15, 30 — verification centre, identity capture | `verified:government_id` gates seat requests and the whole driver flow. Build it before anything that books. |
| 5 | 9 — home | One call. Gives you a real screen early and exercises the envelope, the person shape and the verification banner. |
| 6 | 10, 11, 12 — discover, filters, match details | The search engine. Read 5.3 first; the score breakdown is designed to be shown. |
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
that works and one that guesses.** There are exactly four values, and the server picks one in this
order of precedence:

| `nextStep` | Show | Why it outranks what follows |
|---|---|---|
| `ACCOUNT_SUSPENDED` | Screen 35 | A suspended account must never be routed home, whatever else is true. |
| `CREATE_PIN` | Screen 2's setup | This **device** has no local PIN. A reinstall is asked again rather than inheriting the old installation's trust. |
| `COMPLETE_PROFILE` | Screen 7, then 8 | The profile is unfinished, so it is resumed — never skipped. |
| `LOCAL_SECURITY_SETUP_OR_HOME` | Screen 9 | Nothing is outstanding. |

Do not infer the destination from which fields happen to be null, and do not add a fifth case of
your own: `GET /auth/me` returns the same field and is reachable **while suspended**, which is how
the app recovers if it ever loses its place.

> ⚠️ **Identity verification is not in this list, on purpose.** It is not a step in sign-up — it is a
> gate on specific endpoints, which answer `403 VERIFICATION_REQUIRED` naming the levels they want.
> So a person reaches home and browses first, and only meets verification when they try to ask for a
> seat. Drive that screen from `GET /account/verifications` and from the home banner, never from
> `nextStep`.

```
3  PUT /account/profile/basic      { fullName, gender, registeredRole, dateOfBirth }
   → 200   profileStatus becomes BASIC_COMPLETE
```

Until that call succeeds most endpoints answer `403 ACCOUNT_PROFILE_INCOMPLETE`. The seeded accounts
are already past this step.

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
point is deliberately fuzzed — see 1.9 and 5.2. Do not treat the coarse point as a bug.

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
| 2 | Login (PIN) | `POST /auth/session/refresh` | ✅ **The PIN is local to the device by design** — the server never sees its value, only `device.hasLocalPin`. This screen is a local unlock plus a token refresh. |
| 3 | Use another account | — | ✅ The account list and its tokens are local. `UNIQUE(user_id, device_public_id)` allows two accounts on one device. |
| 4 | Forgot PIN | `POST /auth/otp/request` with `purpose=pin_reset` → `/auth/otp/verify` → `PATCH /account/devices/current/security` | ✅ |
| 5 | Phone | `POST /auth/otp/request` | ✅ |
| 6 | OTP | `POST /auth/otp/verify` | ✅ Drive the countdown from `resendAvailableInSeconds`, and on 429 from `Retry-After`. |
| 7 | Basic profile | `PUT /account/profile/basic` | ⚠️ **Your screen has `firstName` and `lastName`; the endpoint takes one `fullName`.** Also your screen collects workplace/university in the same step, where we have a separate verification endpoint. Needs a product decision — section 8. |
| 8 | Role | same call (`registeredRole`) | ✅ |

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
| 23 | **Driver home** | **`GET /driver/home`** | ✅ One call: today's run, countdown, attendance counts, seats open, what to collect and what she keeps, approved passengers with their meeting points, pending requests with the full count, and her stats. A passenger who is not a driver gets an **empty** payload, not a 404 — the role switch lives on this screen. **Missing: "Cancel today"** (no endpoint cancels a single day — section 8). |
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
| 22 | Notifications | — | ⛔ Phase 12. |
| 26 | **Privacy & blocked** | `GET`/`POST /safety/blocked-users` · `DELETE .../{user}` | ✅ Blocking works in **both directions** from the next search. 🔒 Nothing tells the blocked person, and the list is one-directional — who I blocked, never who blocked me. Privacy toggles themselves are yours/local. |
| 27 | Help & legal | `GET /account/consents` for versions | ⚠️ Static content is yours; the consent versions in force come from the API. |
| 35 | Account restricted | code `ACCOUNT_SUSPENDED` (403) | ⚠️ The code exists and the screen can be built on it. **The case number and "expected update within 24h" are Phase 13, not 11** — see below. |

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
| `PUT /account/profile/basic` | 4.2 Account, devices, consents |
| `GET /account/stats` | 4.2 Account, devices, consents |
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
| `GET /driver/seat-requests` | 4.7 Seat requests and bookings |
| `POST /driver/seat-requests/{seatRequest}/approve` | 4.7 Seat requests and bookings |
| `POST /driver/seat-requests/{seatRequest}/reject` | 4.7 Seat requests and bookings |
| `POST /driver/seat-requests/{seatRequest}/waitlist` | 4.7 Seat requests and bookings |
| `GET /my-bookings` | 4.7 Seat requests and bookings |
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
| `POST /trips/{trip}/check-in` | 4.9 Live trip |
| `POST /trips/{trip}/complete` | 4.9 Live trip |
| `GET /trips/{trip}/location` | 4.9 Live trip |
| `POST /trips/{trip}/location` | 4.9 Live trip |
| `POST /trips/{trip}/no-show` | 4.9 Live trip |
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

One route is deliberately **not** in this index and **not** in the OpenAPI document: `/s/{token}`,
the page a trusted contact opens. It is not under `/v1`, it takes no auth token, and it returns HTML
rather than the envelope — a web page for a person, not an endpoint for the app. You never call it;
you hand the URL the server gives you to the operating system's share sheet. See 4.12.

### 4.1 Auth and session

#### `POST /auth/otp/request` — public

Send a code. Also the entry point for a PIN reset.

**Request:** `phone` (any Egyptian format; Arabic-Indic digits are normalised), `purpose`
(`authentication` default, or `pin_reset`), `device.publicId` (a stable id you generate and keep),
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
| `nextStep` | **The screen to go to next**, decided by the server. Exactly four values: `ACCOUNT_SUSPENDED`, `CREATE_PIN`, `COMPLETE_PROFILE`, `LOCAL_SECURITY_SETUP_OR_HOME` — in that order of precedence, explained in 2.1. Follow it rather than deciding yourself; it accounts for suspension, a device with no PIN, and half-finished profiles you cannot see. Verification is **not** one of them — it is a per-endpoint gate, not a sign-up step. |
| `pinLength` | How many digits your local PIN entry should take (currently 4). Configurable server-side; do not hard-code. |
| `session` | See 5.2. |
| `user` | See 5.5. |
| `device` | See 5.6. |

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

### 4.2 Account, devices, consents

#### `GET /account/devices` — signed-in, paginated

An array of `device` (5.6). `isCurrent` marks the one you are calling from.

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

**Request:** `fullName` (required), `publicFirstName`, `gender` (required), `registeredRole`
(required), `dateOfBirth`, `preferredLanguage`, `orgType`, `organizationId`.

Returns `user`. See screen 7 above: the split-name question is open.

#### `GET /account/stats` — signed-in

The caller's **own** numbers only. See 5.7.

---

### 4.3 Verification

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

### 4.4 Driver application and vehicles

All of these need `verified:government_id` except `eligibility`.

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

Makes this the active vehicle and deactivates the others, in one transaction.

#### `POST /driver/vehicles/{vehicle}/documents` — verified

**Request:** `type` (registration, insurance…), `file`, `expiresAt`.

---

### 4.5 Commutes (driver)

#### `GET /commutes` · `GET /commutes/{commute}` — verified

The caller's own commutes. Full shape in 5.8.

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

#### `POST /commutes/{commute}/pause` · `/resume` — verified

Pausing stops it being found and stops new days being generated. **Days already booked are
untouched** — a driver who pauses for a week and resumes finds her group intact. Resume re-checks
the licence and vehicle and catches up the missed days.

#### `DELETE /commutes/{commute}` — verified

Archives. Nothing is deleted: history stays, and only future days nobody has taken are cancelled.

---

### 4.6 Search, demands, saved searches

#### `GET /search/commutes` — active, profile complete

**Query:** `origin[lat,lng]`, `destination[lat,lng]`, `daysMask`, `arrivalWindowStart`,
`arrivalWindowEnd` (`HH:MM:SS`), `maxWalkMinutes` (0–45), `maxDetourMinutes` (0–45),
`seatsNeeded`, `audiencePreference`, `budgetMonthlyPiastres`, `flexibilityMinutes` (0–60),
`wantsReturnTrip`, `rules[]`.

**Verification is NOT required here** — searching is how somebody decides whether Rafeeq is worth
verifying for. What they are *eligible* to see is decided inside the query from their own account:
a women-only commute is excluded for a non-woman before anything is scored, so you will never
receive a result they cannot then request.

Returns an array of match results — see 5.3, which explains the score breakdown.

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

### 4.7 Seat requests and bookings

#### `POST /commutes/{commute}/seat-requests` — verified

**Request:** `commitment` (`trial`/`recurring`), `scheduledTripId` (**required for a trial**),
`requestedDaysMask` (**required for recurring**), `seats` (1–4), `meetingPreference`
(`gate`/`street`/`landmark`/`custom`), `customPickupPlaceId`, `introMessage` (max 500),
`paymentType` (`cash`/`online`), `agreedToRules` (**must be true**).

`agreedToRules` is mandatory and the moment is stored, not a flag — in a dispute what matters is
when they agreed. Expected refusals: `SEAT_UNAVAILABLE`, `BOOKING_DEADLINE_PASSED` (bookings close
at 21:00 the night before), `BOOKING_ALREADY_REQUESTED` (one open request per commute),
`BOOKING_OWN_COMMUTE`, `BOOKING_RECURRING_DAYS_NOT_OFFERED`.

#### `GET /seat-requests` — verified, paginated

The caller's own requests. See 5.4 for the shape.

#### `DELETE /seat-requests/{seatRequest}` — verified

Withdraws it, freeing the one-open-request slot.

#### `GET /driver/seat-requests` — verified, paginated

The driver's inbox, **oldest first** — the person who has waited longest is the one whose answer
is most overdue, and a request expires after 48 hours. Each carries a `passenger` (5.1).

#### `POST /driver/seat-requests/{seatRequest}/approve` — verified

One call handles both a trial and a recurring membership.

| Field | Meaning |
|---|---|
| `seatRequest` | The request, now `APPROVED`. |
| `membership` | The group member row created or upgraded. |
| `bookings[]` | **A trial produces one; a recurring membership produces one per committed day.** Do not assume a single booking. |
| `seatedDays` | How many days were successfully booked. |
| `skippedDays` | Days that could not be booked because they were already full. **Show these** — a member who thinks they have five days and has four will find out on the wrong morning. |

#### `POST /driver/seat-requests/{seatRequest}/reject` — verified

**Request:** `responseNote` (optional).

#### `POST /driver/seat-requests/{seatRequest}/waitlist` — verified

Moves the request to the waitlist and sets `waitlistPosition`. When a seat frees up the promotion
goes to **the driver's inbox as a decision**, not straight to a booking — the driver decides who
rides in her car. (Your screen 20 says "waitlist fills empty seats automatically"; that text needs
to change. Section 8.)

#### `GET /my-bookings` — verified, paginated

The caller's seats, newest first. See 5.9.

#### `GET /driver/bookings` — verified, paginated

Every seat the caller has approved. The largest list in the API.

#### `GET /bookings/{booking}` — verified

Visible to its passenger or its driver; **404 for anybody else**, because a 403 would confirm the
id exists.

#### `PATCH /bookings/{booking}/cancel` — verified

**Request:** `reason` (optional).

Releases the seat under the same lock that takes it. Which side cancelled is read from **who the
caller is**, never from the request — a passenger must not be able to record their own
cancellation as the driver's, which would move the blame for a missed ride.

**No fee is charged.** The cancellation policy is still an open question and a guessed fee would
take money from a real person on an assumption. `cancellationFeePiastres` is always `0` today.

#### `POST /bookings/{booking}/dispute` — verified

**Request:** `reason` (10–255 characters, free text).

"That's not right" on the trip receipt. See 4.9 — this is the safeguard that makes the driver's
attendance decision acceptable, and it is the passenger's only move.

---

### 4.8 Pickup points

#### `POST /commutes/{commute}/pickup-preview` — verified

**Request:** `lat`, `lng`, `label`.

What a proposed point would cost the driver, **without creating anything**. Use it to warn before
the person commits.

| Field | Meaning |
|---|---|
| `addedMinutes` / `addedKm` | What THIS point adds. Computed by us from the route, never taken from the requester. |
| `runTotalMinutes` | The detour for the **whole run** including every already-approved pickup. This is the figure the limit is checked against. |
| `maxDetourMinutes` | The driver's own limit. |
| `withinLimit` | Whether a request would be accepted. Show the consequence before they ask. |

#### `POST /seat-requests/{seatRequest}/pickup-request` — verified

For a **new joiner**, before their seat is approved.

#### `POST /groups/{group}/pickup-request` — verified

For an **existing member** — "could you collect me somewhere else from now on". Use this one once
the seat is approved; the seat-request route refuses with `SEAT_REQUEST_NOT_PENDING`.

**Request (both):** `lat`, `lng`, `label`.

Refusals: `PICKUP_NOT_ON_COMMUTE` (too far off the route), `PICKUP_DETOUR_TOO_LONG` (over the
driver's limit for the whole run), `PICKUP_ALREADY_REQUESTED`. A commute with
`allowsCustomPickup: false` refuses all of them.

#### `GET /pickup-requests` · `GET /driver/pickup-requests` — verified, paginated

| Field | Meaning |
|---|---|
| `status` | `PENDING` · `APPROVED` · `SUGGESTED_ALTERNATIVE` · `REJECTED`. |
| `proposedPoint` | `{lat, lng, isExact}`. 🔒 **Fuzzed until approved** — the driver sees the neighbourhood while deciding and the exact point once she has agreed to go there. |
| `addedMinutes` / `addedKm` | Our measurement. |
| `runTotalMinutes` / `maxDetourMinutes` | The cumulative figure and the limit, so the driver decides with the whole picture. |
| `effectiveFrom` | `next_trip` today. |
| `alternativePlaceId` | Set when the driver countered with a different place. |

#### `POST /driver/pickup-requests/{pickupRequest}/approve` · `/suggest-alternative` · `/reject` — verified

The three buttons on screen 29. `suggest-alternative` takes `placeId` and **moves nothing until
the passenger accepts**. Approving writes the agreed point onto the upcoming bookings, so the
meeting point and the decision cannot disagree.

#### `POST /pickup-requests/{pickupRequest}/accept-alternative` — verified

The passenger accepting the driver's counter-offer.

---

### 4.9 Live trip

The driver drives the run; a passenger on it may watch. Both halves are in this section because
they are two sides of the same state.

#### `POST /trips/{trip}/start` — verified, driver only

"Start Today's Commute". Re-checks **everything**: account approved, licence valid, vehicle
approved and active, the day not cancelled. Each was true when the commute was published and may
have stopped being true since.

May only be called within **90 minutes before departure** (`TRIP_TOO_EARLY_TO_START`, 422, with
`departureAt` and `windowMinutes` in `error.fields`). **There is no lower bound** — a run whose
departure has passed is exactly the one a late driver needs to start.

Returns the trip session (5.10) and opens a `pending` attendance row per confirmed seat.

#### `POST /trips/{trip}/status` — verified, driver only

**Request:** `status` — one of `EN_ROUTE`, `AT_PICKUP`, `IN_PROGRESS`.

The sequence: `PREPARING → EN_ROUTE ⇄ AT_PICKUP → IN_PROGRESS → COMPLETED`.

A multi-pickup morning **cycles between `EN_ROUTE` and `AT_PICKUP`** and becomes `IN_PROGRESS`
once, on the last leg — so `IN_PROGRESS → AT_PICKUP` is refused. `IN_PROGRESS` cannot be cancelled
either: once people are in the car the run finishes or becomes an emergency.

`TRIP_INVALID_TRANSITION` (409) carries `currentStatus` and `allowed[]` in `error.fields` —
**render those**, because a driver who taps the wrong button needs to be told what to do, not that
the transition was invalid.

#### `POST /trips/{trip}/complete` — verified, driver only

Closes the session, completes the bookings, updates the counters, and clears the live position.
**No money moves** — there is a two-hour delay before collection so a driver who tapped the wrong
name can notice (and Phase 8 is not built).

#### `GET /trips/{trip}` — verified, driver or anybody on the run

The trip session (5.10). `TRIP_NOT_STARTED` (409) if the run has not begun — a real answer rather
than an empty session.

#### `GET /trips/{trip}/attendance` — verified, **driver only**

Who is aboard. Not offered to passengers: who was marked absent this morning is not something to
hand a fellow passenger.

#### `POST /trips/{trip}/check-in` — verified, driver only

**Request:** `bookingId`, `lat`, `lng` (both optional, both-or-neither).

"Sara arrived". **Decision D18: the driver confirms.** There is no field for the status — whether
this reads as `PRESENT` or `LATE` is decided by whether a wait timer was running, so the driver is
never asked to judge somebody's punctuality.

`lat`/`lng` are **corroboration only**. A confirmation that required a good GPS fix would fail in
a basement car park, and the driver would learn to check people in from the street to make it
work. Sending nothing is completely normal.

#### `POST /trips/{trip}/no-show` — verified, driver only

**Request:** `bookingId`.

Its own endpoint rather than a status flag, because it is a different act with a different
consequence: one puts somebody in a car, the other puts a mark on their record.

**An unconfirmed passenger is NOT a no-show.** If the driver never taps anything, the row stays
`PENDING` at completion — her silence is not an absence, and nobody carries a mark for something
she did not do.

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

#### `POST /trips/{trip}/wait-timers` — verified, driver only

**Request:** `bookingId`. There is no `graceSeconds` field: how long the platform asks a driver to
wait is policy, not something the app decides.

#### `POST /trips/{trip}/wait-timers/{timer}/extend` — verified, driver only

The "+2 min" button. **Allowed even after the grace has expired** — a driver who sees somebody
running for the car at 5:10 should be able to give them two more minutes.

#### `GET /trips/{trip}/wait-timers` — verified, driver only

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

#### `POST /trips/{trip}/location` — verified, driver only

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

#### `GET /trips/{trip}/location` — verified, driver or a **live** booking

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

### 4.10 Groups

#### `GET /groups` — verified, paginated · `GET /groups/{group}` — verified

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

#### `GET /groups/{group}/members` — verified, paginated

| Field | Meaning |
|---|---|
| `role` | `driver` · `member` · `trial`. |
| `status` | `active` · `notice_given` · `left` · `removed`. |
| `committedDaysMask` | Which days this member travels. |
| `joinedAt` / `leftAt` | |
| `leavesOn` | The date their notice completes, when `notice_given`. |
| `person` | See 5.1. |

#### `POST /groups/{group}/leave` — verified

Gives notice. The member stays until the notice period completes — a driver who planned her month
around four passengers should not discover on Sunday night that one is gone. A **driver cannot
leave her own group** (`GROUP_DRIVER_CANNOT_LEAVE`, 403); she pauses or archives the commute.

#### `GET` / `POST /groups/{group}/attendance` — verified, paginated

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

#### `GET` / `POST` / `DELETE /groups/{group}/absences/{absence}` — verified, paginated

Planned absence — "I'm away next week".

**POST request:** `fromDate`, `toDate`, `reason`, `releasesSeat`.

| Field | Meaning |
|---|---|
| `fromDate` / `toDate` | Inclusive. |
| `releasesSeat` | Whether the seat goes back to the pool for those days. |
| `person` | See 5.1. |

`GROUP_ABSENCE_OVERLAPS` (409) and `GROUP_ABSENCE_TOO_LONG` (422).

---

### 4.11 Home aggregates

Both are **one call each** and **not paginated** — see 1.3 for why.

#### `GET /home` — signed-in

Screen 9. Signed in and nothing more, deliberately: this screen TELLS a person what is still
missing, so gating it behind a complete profile would hide the instructions behind the requirement
they explain.

| Field | Meaning |
|---|---|
| `greetingName` | Their public first name. |
| `verification` | `{level, of, percentage, isComplete}` — the same numbers as the Verification Centre, so the banner cannot disagree with the screen behind it. |
| `nextJourney` | The soonest journey that has not finished, or **`null`**. Contains the full booking (5.9) plus `originLabel`, `destinationLabel`, `departureAt`, `departureLocal`, `departsInMinutes`, `isToday`, `attendance`, `isTrial`, `driver` (5.1), `vehicle`. |
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
| `pendingRequests.items[]` | The first 5, oldest first, each a seat request (5.4) with its `passenger`. |
| `stats.onTimeRate` | `null` until it has been computed from completed runs. **Show a dash, not 0%** — a 0% badge on somebody's first morning is a number nobody earned. |
| `stats.completedTrips` | |
| `stats.avgDetourMinutes` | Averaged over approved custom pickups. `0` is true and means nobody has one; `null` means she has no commutes at all. |

### 4.12 Safety (screens 16, 26, 32, 44)

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
| `respondedAt` | When a human first picked it up. `null` until then. **Show this** — it is what tells the person somebody is actually looking. |
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
> Nobody will, until the notification channel exists.
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
| `resolution` / `resolvedAt` | Once decided. |
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

### 4.13 Ratings (screen 37)

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
| `isVisible` | Whether anybody else can see it yet. 🔴 **`false` says nothing about whether they have rated** — it is also false when nobody has. |
| `editableUntil` | Until when you may change it, or `null` once you cannot. Null covers both reasons — the clock ran out, or it became visible — because your behaviour is the same either way and distinguishing them would hint at theirs. |
| `editedAt` | Set if it was changed. |

Errors: `TRIP_NOT_RATEABLE` (422 — the journey was cancelled or has not happened;
`error.fields.bookingStatus` says which) · `RATING_WINDOW_CLOSED` (409) · `RATING_ALREADY_SUBMITTED`
(409) · `404` for a journey the caller was not on.

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

## 5. Shared shapes

These appear inside many responses. Each is described once here.

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
| `onTimeRate` | `0`–`100`, or `null` until computed. Same rule: show a dash. |

### 5.2 `session`

| Field | Meaning |
|---|---|
| `accessToken` | Bearer token, 15 minutes. |
| `refreshToken` | 60 days, **single use**. |
| `accessExpiresAt` / `refreshExpiresAt` | UTC instants. Refresh a little before the first rather than waiting for a 401. |

### 5.3 Match result (search)

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

### 5.4 Seat request

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

### 5.5 `user`

| Field | Meaning |
|---|---|
| `id` | |
| `phone` / `phoneVerified` | 🔒 **Your own only.** Never present for anybody else. |
| `fullName` / `publicFirstName` | Same rule. |
| `profilePhotoPath` | Storage path; ask us for the display URL flow before wiring it. |
| `dateOfBirth`, `email` | Own only. |
| `accountStatus` | `ACTIVE` · `SUSPENDED` · `DELETED`. |
| `suspensionReason` | Set when suspended. Show it — this is screen 35. |
| `profileStatus` | `PHONE_ONLY` · `BASIC_COMPLETE`. **Until `BASIC_COMPLETE` the `active` tier refuses most endpoints.** |
| `registeredRole` | `passenger` · `driver` · `both`. |
| `orgType` / `organizationId` | Their claimed workplace or university. |
| `preferredLanguage` | `ar` · `en`. |
| `trustLevel` | `0`–`4`, recomputed from the rows rather than incremented. |
| `outstandingConsents[]` | Which consents are stale at the current version. Non-empty means show the acceptance screen. |

### 5.6 `device`

| Field | Meaning |
|---|---|
| `id`, `platform`, `model`, `osVersion`, `appVersion` | As registered. |
| `isTrusted` | |
| `hasLocalPin` | 🔒 A **flag**. The server never sees the PIN's value. |
| `biometricEnabled` | |
| `lastSeenAt`, `revokedAt` | |
| `isCurrent` | The device you are calling from. Do not offer "revoke" on it without a confirmation. |

### 5.7 `stats` (`GET /account/stats`)

| Field | Meaning |
|---|---|
| `completedTripsAsPassenger` / `completedTripsAsDriver` | Counts. |
| `ratingAsPassenger` / `ratingAsDriver` | `null` until Phase 10. |
| `onTimeRate` / `cancellationRate` | `0`–`100`, or `null` when not yet computed. |
| `noShowCount` | |
| `computedAt` | When these were last recalculated. `null` means never. |

🔴 **Every rate is `null` rather than `0` when it has not been computed.** Rendering 0% would tell
a brand-new member they are unreliable.

### 5.8 Commute offer

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
| `upcomingTrips[]` | The next generated days (5.9). |

### 5.9 Scheduled trip and booking

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
| `price.totalPiastres` | What the seat costs. |
| `price.platformFeePiastres` | The platform's share. |
| `price.driverAmountPiastres` | What reaches the driver. |
| `paymentType` | `cash` · `online`. |
| `paymentStatus` | `NOT_DUE` (cash is settled in the car) · `PENDING` · `PAID` · `FAILED` · `REFUNDED` · `DISPUTED`. |
| `meetingPoint` | `{lat, lng, isExact}`, or **`null` for the standard point** — which is the run's origin. |
| `cancelledAt`, `cancelledReason`, `cancellationFeePiastres` | The fee is always `0` today. |
| `trip` | The scheduled trip above, when loaded. |

🔒 **All three money figures are frozen at approval and never recomputed.** A driver raising her
price, or the platform changing its percentage, moves nothing here. And the meeting point is
**fuzzed to ~110m until the booking is confirmed** — a pickup point is often somebody's front
door, and a pending or cancelled booking has not earned it.

⚠️ **The fee direction is an open question** (section 8). Today `totalPiastres = platformFee +
driverAmount`, i.e. the fee is deducted from the driver's price. Your screens 12 and 24 show it
added on top of the passenger instead. **Do not hard-code either reading** — render the three
figures you are given.

### 5.10 Trip session

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
| **Payments and wallet** | 34, group `payments` tab | **8** | Payment methods, online capture, driver balance and payouts, refunds, cancellation fees. `paymentStatus` exists and stays `NOT_DUE` for cash. **Blocked on the fee-direction decision, section 8.** |
| **Driver cancelling one day** | 43, 45, 46 | **9** | A driver cancelling a single day and the backup search that follows. **Blocked on an open decision** — refunds and reliability, section 8 #3. Route deviation itself is now detected and reported (see `deviationDetectedAt`); the ops ALERT it should trigger needs Phase 12/13. |
| **Reviews on a profile, trust tier** | reviews on 12, `minRating` filter on 11, history on 19 | **10** | 🔴 **Rating itself is done** — section 4.13 — and the `rating` in every public summary now carries a number once a rating is revealed. Still to come: the list of **other people's** visible reviews on a profile, reporting an abusive review, and the public trust tier (`new` / `trusted` / `highly_trusted`). The underlying score is deliberately internal and will never be returned. |
| **Auto-share trips** | 26 | **12** | 🔴 **`autoShareTrips` on an emergency contact is stored and nothing acts on it yet.** See 4.12 — read that before you build the toggle. |
| **Case number on a restricted account** | 35 | **13** | `ACCOUNT_SUSPENDED` carries no `error.fields` today, so there is no case reference and no "expected update" time. **Nothing in the platform suspends an account yet** — only an admin can, and that is the Phase 13 dashboard, which is also where the case reference and the response deadline would be written. Build the screen on the code alone and leave room for two strings. (This row said Phase 11 in earlier versions of this file. It was wrong: the data has no source until the act that creates it exists.) |
| **Night escort mode** | none | **13** | Not a mobile feature at all, and this corrects an earlier line in this file. It is a **corridor-level night window armed by the ops team** (or automatically, 9pm–5am) with staff monitoring — a dashboard control, not something an app shows or calls. There will be no endpoint for it. |
| **Notifications and chat** | 22 | **12** | Push, in-app notifications, preferences, trip chat. **Everything today is pull-only** — no server-initiated message of any kind reaches the app. Plan for polling in the interim, and tell us what you need first. |

---

## 8. Open questions that could change this contract

Do not design around either side of these. Ask before you build the screen.

| # | Question | Affects |
|---|---|---|
| 1 | **Which way does the platform fee go, and what is the rate?** Today: deducted from the driver's price at 3%. Your screens 12 and 24: added on top of the passenger at 10%. The Bible's own worked example agrees with your screens (a passenger paying 88 for an 80 seat), which contradicts its own prose. Must be settled before Phase 8. | 12, 24, all money |
| 2 | **`firstName` + `lastName`, or one `fullName`?** Screen 7 splits them; the endpoint takes one. | 7 |
| 3 | **A single day cancelled by the driver** — refund? counts against her reliability? No endpoint until this is answered. | 23, 46 |
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
