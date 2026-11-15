# Answers: testing calls the app still needs (2026-11-15)

Answers to `BACKEND_TEST_API_NEEDS.md`. Everything below reaches staging with the next deploy (it needs the new migration `testing_faults`). The full reference is in the guide, section 4.17.

All new calls are **staging only** (`404` when `RAFEEQ_TESTING_API` is off or on production) and need the normal bearer token, except `otp/expire` and `otp/unlock`, which need none.

**One correction to your file.** Refusals use the normal envelope, which nests the code:

```json
{ "success": false, "error": { "code": "…", "message": "…", "fields": null } }
```

| # | Answer |
|---|---|
| T1 | ✅ Built: `POST/GET/DELETE /testing/faults` |
| T2 | ✅ Built: `POST /testing/otp/expire`, `POST /testing/otp/unlock` (no token, test numbers only) |
| T3 | ✅ Built: `POST /testing/session/revoke` |
| T4 | ✅ Built: `POST /testing/reset` |
| T5 | ✅ Built, named `POST /testing/seed/past-bookings` (not `seed/history`), and `POST /testing/seat-requests/{request}/age` |
| T6 | ✅ Built: `POST /testing/debt/seed`, `POST /testing/credits` |
| T7 | ✅ Built, with one change: an *unknown* cancel reason can't be made (see T7). H-4 stays a widget test |
| T8 | ✅ Built: `PUT/DELETE /testing/content/{key}` |
| T9 | ✅ Built: `POST /testing/trips/{trip}/location/age` |
| T10 | Not a bug. The messages are sent by a queued job a few seconds after `EN_ROUTE`; the test read too early. Poll |
| T11 | ✅ Both: T1 supports `status: 429` with `Retry-After`, and `POST /testing/rate-limit` is a shortcut for it |
| T12 | ✅ Built: `POST /testing/push` |
| Old failures | None still failing on the server. Four were Patrol-side and are already fixed in your commits; see the table at the end |

**Test numbers** (for T2 and T4): `+20 10 2000 xxxx` and `+20 10 555x xxxx`, i.e. numbers starting with `+20102000` or `+2010555`. On the server: `RAFEEQ_TESTING_PHONE_PREFIXES`.

---

## T1 · Make a request slow or fail

```
POST /testing/faults
{ "path": "/notifications", "method": "GET", "mode": "error", "status": 500, "count": 1 }
```

`201`:

```json
{
  "success": true,
  "data": {
    "id": "01M7F9FMQEKN406VSWPEGYD2WZ",
    "path": "/notifications",
    "method": "GET",
    "mode": "error",
    "delayMs": null,
    "status": 500,
    "code": null,
    "retryAfterSeconds": null,
    "phone": null,
    "remaining": 1,
    "expiresAt": "2026-11-15T09:49:14+00:00"
  }
}
```

The next `GET /notifications` from the same token answers `500`:

```json
{ "success": false, "error": { "code": "SERVER_ERROR", "message": "Something went wrong. Please try again later.", "fields": null } }
```

A delay rule: `{ "path": "/bookings", "mode": "delay", "delayMs": 4000, "count": 2 }`. Then the real answer, 4 seconds late.

`GET /testing/faults` → `data: [ …open rules, same shape… ]`. `DELETE /testing/faults` → `{ "cleared": 2 }`.

- Matches **only requests with the caller's token**. Another account (or a person on staging) is never touched.
- `path` is a prefix after `/api/v1`, on whole segments: `/bookings` matches `/bookings/01J…/cancel`, not `/bookingsx`. `method` optional.
- `delayMs` 500–15000. `status` one of `404, 409, 422, 429, 500, 503`. `code` optional (`^[A-Z][A-Z0-9_]+$`); left out, it is the status's own code (`NOT_FOUND`, `CONFLICT`, `VALIDATION_FAILED`, `TOO_MANY_REQUESTS`, `SERVER_ERROR`, `SERVICE_UNAVAILABLE`).
- `count` 1–10 (default 1). Each rule also ends by itself 5 minutes after it was made.
- Every faked answer carries the header `X-Testing-Fault: <rule id>`, so a log can tell a fake failure from a real one.
- `/testing/*` and `/auth/*` are never matched, except `/auth/otp/request` and `/auth/otp/verify` named exactly. Those calls carry no token, so the rule matches by **phone**: the caller's own number, or `phone` in the body when it is a test number.

Refusals:

```json
// 422: /testing/* or /auth/*
{ "success": false, "error": { "code": "VALIDATION_FAILED", "message": "The given data was invalid.",
  "fields": { "path": ["/testing/* and /auth/* cannot be matched, except /auth/otp/request and /auth/otp/verify named exactly."] } } }
```

- `422 VALIDATION_FAILED`: bad `mode`, `status`, `delayMs` or `count`.
- `409 FAULT_LIMIT_REACHED`: 10 open rules already.
- `403 TEST_NUMBER_ONLY`: `phone` on an OTP rule is someone else's real number.

**Not covered:** a phone that is really offline. That is still a device state.

## T11 · A rate-limit answer on demand

```
POST /testing/rate-limit   { "path": "/auth/otp/request", "retryAfterSeconds": 30, "count": 1 }
```

`201`, the same rule shape as T1:

```json
{
  "success": true,
  "data": {
    "id": "01M7F9FMRHEMFCCQ5HWK7ZTEFV",
    "path": "/auth/otp/request",
    "method": null,
    "mode": "error",
    "delayMs": null,
    "status": 429,
    "code": "TOO_MANY_REQUESTS",
    "retryAfterSeconds": 30,
    "phone": "+201020001001",
    "remaining": 1,
    "expiresAt": "2026-11-15T09:49:14+00:00"
  }
}
```

The next `POST /auth/otp/request` for `+201020001001` answers `429` with the header `Retry-After: 30`:

```json
{ "success": false, "error": { "code": "TOO_MANY_REQUESTS", "message": "Too many requests. Please try again later.", "fields": null } }
```

Same as `POST /testing/faults` with `mode: "error", status: 429`. Use whichever reads better.

## T2 · OTP: expire the code, lift the lock

No token. Test numbers only.

```
POST /testing/otp/expire   { "phone": "+201055501234" }
```

```json
{ "success": true, "data": { "phone": "+201055501234", "challengeId": "01m7f9fmrx4pffae0x3ej15xsw", "expired": true, "expiresAt": "2026-11-15T09:44:13+00:00" } }
```

Verifying that code now answers `401 AUTH_OTP_EXPIRED`. With no code open:

```json
{ "success": false, "error": { "code": "OTP_NOT_REQUESTED", "message": "No code is open for this number. Request one first.", "fields": null } }
```

```
POST /testing/otp/unlock   { "phone": "+201055501234" }
```

```json
{ "success": true, "data": { "phone": "+201055501234", "attempts": 0, "lockedUntil": null, "resendAvailableAt": null } }
```

Clears the wrong-attempt count, the 5-minute lock and the resend wait. A real number:

```json
{ "success": false, "error": { "code": "TEST_NUMBER_ONLY", "message": "This testing call only works on a test phone number.", "fields": null } }
```

`phone` accepts any form the OTP request accepts (`010…`, `+2010…`).

## T3 · Revoke the caller's session

```
POST /testing/session/revoke   { "refresh": true }
```

```json
{ "success": true, "data": { "revoked": 1, "sessionId": "01m7f9fnbwwmg0afahrc0dy2aw", "refreshRevoked": true } }
```

The next call with that access token:

```json
// 401
{ "success": false, "error": { "code": "UNAUTHENTICATED", "message": "Authentication is required.", "fields": null } }
```

- `refresh: true` (default): `POST /auth/session/refresh` with the old refresh token also answers `401`. This is S-5 and Trips X-2.
- `refresh: false`: only the access token ends, so the app's silent renewal works. `refreshRevoked: false`.

## T4 · Reset the caller's own data

```
POST /testing/reset   { "keep": ["verification", "contacts"] }     // optional, default ["verification"]
```

```json
{
  "success": true,
  "data": {
    "commutes": 1,
    "trips": 22,
    "seatRequests": 2,
    "bookings": 1,
    "waitlist": 0,
    "offers": 0,
    "demands": 0,
    "proposals": 0,
    "pickupChanges": 0,
    "groups": 2,
    "vehicles": 0,
    "contacts": 0,
    "liveShares": 0,
    "incidents": 0,
    "conversations": 0,
    "notifications": 3,
    "debtLines": 2,
    "reliabilityEvents": 0,
    "warnings": 0,
    "faults": 0,
    "kept": ["verification"]
  }
}
```

- Rows are **deleted**, not cancelled, so `BOOKING_ALREADY_REQUESTED`, `SEAT_UNAVAILABLE` and `VEHICLE_LIMIT_REACHED` from left-overs go away.
- Removes the caller's commutes and all their days, seat requests, bookings, waiting-list places, offers, demands, change proposals, pickup changes, group places, chats, live shares, reports she filed, her open fault rules, and (unless kept) spare cars, contacts, notifications, debt, reliability events and warnings.
- Others' seats on the caller's commutes go with them: no fee, no reliability event, **no push**.
- The caller's seats on someone else's run are freed on that run (its `seatsTaken` goes down).
- Always kept: the account, profile, role, active mode and the **active car**.
- `keep` may name `verification`, `vehicles`, `contacts`, `notifications`, `debt`, `reliability`. Leaving out `verification` removes it.
- Lifts a debt suspension once the debt is gone.
- `409 TRIP_IN_PROGRESS` only when a run under way has a real (not test-number) member aboard with the caller:

```json
{ "success": false, "error": { "code": "TRIP_IN_PROGRESS", "message": "A run with a real member aboard is under way, so it cannot be reset now.", "fields": { "tripId": ["01J…"] } } }
```

Test-number members on a run under way don't block it; that run is removed too.

## T5 · Many rows, and an old request

```
POST /testing/seed/past-bookings   { "count": 25, "ending": "COMPLETED" }
```

Named `past-bookings` rather than `seed/history`: a server test refuses any route named `history`, `trail` or `locations`, so no route can ever hand out a GPS trail.

```json
{ "success": true, "data": { "bookingIds": ["01m7f9fmt9f4343x3w7ex66b15", "01m7f9fmtcevaqb9nb86p8r1dq", "…"], "count": 25 } }
```

- `count` 1–60, one booking per day back from yesterday, 07:30 Cairo.
- `ending` one of `COMPLETED`, `CANCELLED_BY_PASSENGER`, `CANCELLED_BY_DRIVER`, `NO_SHOW`, `EXPIRED`, `ENDED_EARLY`, `EXITED_DUE_TO_DRIVER_DELAY`. Left out, they rotate through all seven, so `count: 7` gives one of each (H-2).
- Each call makes a new **archived** commute (never in Discover), Tahrir Square → Fifth Settlement, 45 EGP, driven by the shared test driver `+201020009999` ("Mona"). That is the real `otherPartyUserId`.
- `paymentStatus`: `PAID` for completed and ended early, `WAIVED` for exited, `NOT_DUE` for the rest.
- No reliability events, no debt, no pushes. They read back through `GET /bookings/{booking}` and the history list like real ones.

```
POST /testing/seat-requests/{request}/age   { "days": 8 }
```

```json
{ "success": true, "data": { "id": "01m7f9fmph330jan4960rtw0sb", "status": "REJECTED", "createdAt": "2026-11-07T09:44:14+00:00", "decidedAt": "2026-11-07T09:44:14+00:00", "expiresAt": null } }
```

- The caller's own request as a passenger. Every time on it moves back `days` (1–60).
- A pending request that this takes past its expiry is expired on the spot, and `status` comes back `EXPIRED`.
- A pending one it would not expire:

```json
{ "success": false, "error": { "code": "REQUEST_STILL_PENDING", "message": "This request is still waiting for an answer, and moving it back that far would not end it.", "fields": { "expiresAt": ["…"] } } }
```

## T6 · Debt and credit without driving a trip

```
POST /testing/debt/seed
{ "side": "passenger", "kind": "no_show_fee", "amountPiastres": 2750 }
```

```json
{
  "success": true,
  "data": {
    "lineIds": ["01m7f9fmvrp327dztq60rn149y"],
    "side": "passenger",
    "owedPiastres": 2750,
    "balancePiastres": 2750,
    "overduePiastres": 0,
    "lateFeePiastres": 0,
    "dueAt": null,
    "blocked": false,
    "suspended": false,
    "suspendedAt": null,
    "oldestUnpaidAt": "2026-11-15T09:44:14+00:00",
    "openDisputes": [],
    "suspensionOnHold": false
  }
}
```

After `lineIds`, the fields are the same as that side in `GET /account/debt`.

Driver side, two disputed lines:

```
{ "side": "driver", "kind": "commission", "amountPiastres": 11000, "count": 2, "disputed": true }
```

```json
{
  "success": true,
  "data": {
    "lineIds": ["01m7f9fmwnbs29a40cjjnhjshz", "01m7f9fmwwhcnfa6t8hk5nk07r"],
    "side": "driver",
    "owedPiastres": 22000,
    "balancePiastres": 22000,
    "overduePiastres": 0,
    "lateFeePiastres": 0,
    "dueAt": "2026-11-20T21:59:00+00:00",
    "blocked": false,
    "suspended": false,
    "suspendedAt": null,
    "oldestUnpaidAt": "2026-11-14T09:44:14+00:00",
    "openDisputes": [
      { "type": "fee_appeal", "id": "01m7f9fmwq4e7gmtcqjsvg4ae8", "bookingId": null },
      { "type": "fee_appeal", "id": "01m7f9fmwy7t25v0jk079enpaq", "bookingId": null }
    ],
    "suspensionOnHold": false
  }
}
```

- `kind`: `commission` (driver side only, else `422`), `no_show_fee`, `late_cancel_fee`, `late_fee`. `amountPiastres` 100–500000. `count` 1–10, one per day back (the last one today).
- The lines are real ledger lines: on the statement, in `GET /account/debt`, on the next ride's fee line, in the sweep. A passenger line can suspend her exactly as a real one would.
- `bookingId` must be the caller's upcoming booking (`404`). The passenger's whole balance still rides on her **next** ride, as in real life (Trips U-4).
- `disputed: true` opens a dispute on each line, so it never suspends.
- Overdue, +10 % and suspension: follow with the existing `debt/age` and `debt/deadline`.
- `side: driver` for an account that isn't an approved driver:

```json
{ "success": false, "error": { "code": "DRIVER_NOT_APPROVED", "message": "This account is not an approved driver yet.", "fields": null } }
```

```
POST /testing/credits   { "amountPiastres": 5000, "reason": "driver_cancelled" }
```

```json
{ "success": true, "data": { "creditId": "01m7f9fmxdngbp2mfkbd82zpek", "side": "passenger", "creditPiastres": 2250, "balancePiastres": -2250 } }
```

- Here she owed 2750, so a 5000 credit paid that and left 2250.
- `balancePiastres` is **signed**, like everywhere else in the API: a credit is negative. `creditPiastres` is the positive amount left.
- `side` optional, `passenger` by default. `reason` optional (`snake_case`).
- A passenger credit shows on her next ride as a negative fee line (Trips U-5).

## T7 · Odd values on one booking

```
POST /testing/bookings/{booking}/fields
{ "paymentStatus": "WAIVED", "cancellationReason": "day_cancelled", "cancelledNote": "…", "pickupLabel": null, "dropoffLabel": null }
```

`200` with the booking exactly as `GET /bookings/{booking}` returns it (shortened here):

```json
{
  "success": true,
  "data": {
    "id": "01m7f9fmtwhfe61p52v7pby77v",
    "status": "CANCELLED_BY_DRIVER",
    "paymentStatus": "WAIVED",
    "cancelledAt": "2026-11-14T02:30:00+00:00",
    "cancelledReason": "day_cancelled",
    "meetingPoint": { "lat": 30.045, "lng": 31.236, "label": null, "…": "…" },
    "…": "…"
  }
}
```

- Only the keys sent change. The caller must be the passenger.
- **What changed from your ask:** `cancelledReason` in every booking is one of the server's own codes (or `null`); the server never sends an unknown one. So the call takes any **real** code (`422` otherwise), and an unknown code can't be put on the phone. H-4 (unknown reason reads as a plain sentence) stays a widget test.
- `cancellationReason` and `cancelledNote` need a cancelled booking:

```json
{ "success": false, "error": { "code": "BOOKING_NOT_CANCELLED", "message": "This booking is not cancelled, so it has no cancellation reason to change.", "fields": { "status": ["COMPLETED"] } } }
```

- Labels only show on a confirmed booking, as always. For B-3 use an upcoming confirmed booking with `pickupLabel: null`.

## T8 · Write or remove a content page

```
PUT /testing/content/support_contact
{ "en": "WhatsApp: +20 10 0000 0000\nsupport@example.com", "ar": "واتساب: ‎+20 10 0000 0000" }
```

`200`, the page exactly as `GET /content/support_contact` returns it:

```json
{
  "success": true,
  "data": {
    "key": "support_contact",
    "version": 1,
    "ar": "واتساب: ‎+20 10 0000 0000",
    "en": "WhatsApp: +20 10 0000 0000\nsupport@example.com",
    "updatedAt": "2026-11-15T09:44:14+00:00"
  }
}
```

```
DELETE /testing/content/support_contact
```

```json
{ "success": true, "data": { "key": "support_contact", "removed": true, "restoredStaffVersion": false } }
```

- Keys: `support_contact` and `guidelines` (`community_guidelines` works as a name for it). Anything else is `404`.
- `DELETE` puts back what staff had before the test's first `PUT`. When staff had written nothing, the page is gone and `GET` answers `404` (`removed: true`). When staff had written one, their text comes back (`removed: false, restoredStaffVersion: true`).
- The one call that is not limited to the caller: the page is shared. Run TP-22's two branches one after the other, not in parallel with another test that reads it.

## T9 · Make the car's position old

```
POST /testing/trips/{trip}/location/age   { "minutes": 3 }
```

```json
{
  "success": true,
  "data": {
    "tripId": "01m7f9fmb7ep9zh1a2yxvfatr8",
    "recordedAt": "2026-11-15T09:41:14+00:00",
    "ageSeconds": 180,
    "isStale": true,
    "ignoringNewPointsUntil": "2026-11-15T09:45:14+00:00"
  }
}
```

- Caller: the driver of a started run, or a confirmed passenger on it. `minutes` 1–30.
- `GET /trips/{trip}/location` then has `lastLocationAt` 3 minutes old. The driver's new points are **ignored for 60 seconds**, so the simulator's GPS doesn't undo it.
- `isStale` is `ageSeconds` ≥ the 2-minute GPS silence rule.
- Before the run starts:

```json
{ "success": false, "error": { "code": "TRIP_NOT_STARTED", "message": "This run has not started yet.", "fields": { "tripStatus": ["SCHEDULED"] } } }
```

## T10 · Auto-share messages came back empty

**Not a bug, and deployed.** The messages are sent by a **queued job**. The job is queued at the moment the run goes from `PREPARING` to `EN_ROUTE`, and the staging worker picks it up a few seconds later. TP-23 read `GET /testing/safety/auto-share/messages` right after the `EN_ROUTE` answer, before the worker had run. We reproduced the same empty answer you got; once the worker ran, there was one `SENT` row.

Change the test: after `EN_ROUTE`, poll `GET /testing/safety/auto-share/messages` every second for up to 15 seconds until `data` is not empty. Do the same for `GET /safety/live-shares` with `source: "AUTO"`. Check-in is not needed; a confirmed booking is enough.

If it is still empty after 15 seconds, tell us: that means the staging worker is down.

One gap, which we're telling you about but not changing: switching auto-share on **after** the run has left sends nothing for that run. It only applies from the next run.

`FLUTTER_AUTO_SHARE_2026_11_15_ANSWERS.md` step 3 now says this.

## T12 · A push with no inbox row

```
POST /testing/push   { "type": "pickup_change_requested", "data": { "bookingId": "01J…" } }
```

```json
{ "success": true, "data": { "type": "pickup_change_requested", "sent": 1, "tokens": 1 } }
```

- `202`. A push to the caller's own devices, and **no** inbox row (`GET /notifications` doesn't show it).
- Any notification type. Ids in `data` must be the caller's own, else `422`.
- `tokens` is how many of her devices have a push token. On a simulator that is usually `0` (`sent: 0`). Android emulators get it through FCM.

---

## Existing calls that failed in the last recorded runs

None is failing on the server now. We matched each one against `conductor.log` and reproduced it.

| Call | What it was | What to do |
|---|---|---|
| `POST /testing/trips/{trip}/departure` → `500` | One run on 2026-11-11 20:48–20:54. It moved next week's run onto a date today's run already held. Fixed that evening (B-12): now a scheduled run is swapped, a finished one is moved back to a free day, and a run under way answers `409 TRIP_DAY_TAKEN`. Your `TripRun` also now uses day +1…+6. We ran +30, −6 and −21 again: all `200`. | Re-run. |
| `POST /testing/driver/documents/vehicle_registration/expiry` → `404` | MG-16 had made the **spare** car active, and that car never had registration papers (approving a car doesn't need them). Fixed on your side (back to the first car for MG-17…19). The `404` now says why: `fields: { vehicleId: ["01J…"], missing: ["registration"] }`. | Pass a `vehicleId` whose car has `registration_front`/`registration_back` uploaded. |
| "I've paid" `POST /account/debt/payments` → `422` | Knock-on from the `500` above: PY-1 failed, so nothing was owed and PY-4/PY-5 sent `amountPiastres: 0`. The rule is `amountPiastres` integer ≥ 100 (piastres). | Body `{ "side": "driver", "amountPiastres": 11000, "instapayReference": "…" }` (4–64 chars), `receiptImage` optional. With T6 you can now seed the debt first. Skip PY-4/PY-5 when `owed` is 0. |
| Passenger rating → `405` | `bookingId` was empty on the passenger's phone, so it posted to `/bookings//rating`; nginx merged the slashes into `/bookings/rating`, which only exists as `GET`. Fixed on your side (duet 9 sets `bookingId` on the passenger side). | `POST /bookings/{booking}/rating`. Editing is `PATCH /ratings/{rating}`. |
| Check-in after "I've arrived" → `409 ATTENDANCE_NOT_CONFIRMABLE` | The run was still `PREPARING`: `wait-timers` was called right after `/start`. Fixed on your side (N-7 now moves to `EN_ROUTE`). | Order: `start` → `status EN_ROUTE` → `location` (a fresh point) → `wait-timers {bookingId, lat, lng}` near her pickup → `check-in` → `status IN_PROGRESS` → `drop-off` → `complete`. |

## What changed for the app

- 17 new staging-only routes, listed above. Guide section 4.17.
- New error codes: `TEST_NUMBER_ONLY` (403), `DRIVER_NOT_APPROVED` (403), `OTP_NOT_REQUESTED` (409), `FAULT_LIMIT_REACHED` (409), `TRIP_IN_PROGRESS` (409), `REQUEST_STILL_PENDING` (409), `BOOKING_NOT_CANCELLED` (409). Only the testing calls raise them.
- `POST /testing/driver/documents/vehicle_registration/expiry`'s `404` now carries `fields.vehicleId` and `fields.missing`.

Nothing outside `/testing/*` changed shape.
