# Patrol run gaps: backend answers

**Date:** 2026-11-10 · **Answers:** `docs/testing/backend-gaps-2026-11-10.md` (mobile repo) · **Full contract:** `RAFEEQ_MOBILE_API_GUIDE.md`, change log entry of 2026-11-10

B1 to B10 are done on the backend. B11 (test environment) is not done yet. The changes are live on `rafeeq-production` after the next deploy; we will tell you when.

| # | Status | What you need to do |
|---|---|---|
| B1 | ✅ Done | Nothing. The bell badge works once deployed. |
| B2 | ✅ Done | Nothing if you follow `nextStep`. |
| B3 | ✅ Done, stricter than asked | Handle two `409` codes. |
| B4 | ✅ Done | Read `vehicle.plateNumber` (not `plate`). |
| B5 | ✅ Done: refused | Handle `409 COMMUTE_TRIP_IN_PROGRESS` on Delete **and** Pause. |
| B6 | ✅ Done | Read `tripDate`; stop matching against `upcomingTrips[]`. |
| B7 | ✅ Done | Use `GET /seat-requests/{id}`. |
| B8 | ✅ Done | Nothing; `1` / `0` still works. |
| B9 | ✅ Done | Nothing; `HH:MM` now works. |
| B10 | ✅ Documented | Nothing. See the table below. |
| B11 | ⏳ Not done | — |

---

## B1 · `unreadNotifications` on `GET /driver/home`

Added at the top level, the same number as `GET /notifications` → `meta.unreadCount` and the same meaning as on
`GET /home`. It is also on the empty payload of an account that never applied to drive.

```json
{ "data": { "greetingName": "Ahmed", "...": "...", "unreadNotifications": 208 } }
```

## B2 · `ROLE_SELECTION` once the profile is saved

When `profileComplete` is `true` and no role is chosen, **every** launch call now answers `nextStep: ROLE_SELECTION`:
`POST /auth/otp/verify`, `POST /auth/session/refresh`, `GET /auth/me` and `GET /account/onboarding-state`. This follows
AUTH-02 ("resume at Role if the profile was saved"). An unfinished profile still answers `BASIC_PROFILE`.

The full order is now: `PUT /account/profile/basic` → `ROLE_SELECTION` → `POST /account/initial-role` → `HOME`.
Reopening the app in the middle lands on Role.

## B3 · No-show needs the arrival and the end of the grace

Stricter than the ask, as agreed: `POST /trips/{trip}/no-show` is refused unless the driver **marked arrival** for that
booking (`POST /trips/{trip}/wait-timers`, within 150 m of her pickup) **and** her 5 minutes have ended. The grace runs
5 minutes from the later of the arrival and her pickup time (TRIP-11, TRIP-16). Nothing changes on a refusal.

| Refusal | When | Fields |
|---|---|---|
| `409 NO_ARRIVAL_MARKED` | No arrival was ever marked for this booking. | — |
| `409 NO_SHOW_TOO_EARLY` | The grace is still running. | `error.fields.expiresAt[0]`: ISO time the grace ends |

```json
{
  "error": {
    "code": "NO_SHOW_TOO_EARLY",
    "message": "It is too early to mark a no-show. Wait until the 5-minute grace ends.",
    "fields": { "expiresAt": ["2026-11-10T05:56:00+00:00"] }
  }
}
```

`expiresAt` is the same value as `expiresAt` on `GET /trips/{trip}/wait-timers`. If GPS will not let the driver mark
arrival, that goes to support or an incident, not to a no-show.

The older refusals still come first: a run not under way, or a passenger already checked in, answers
`409 ATTENDANCE_NOT_CONFIRMABLE` as before.

**For your Patrol scenarios:** mark arrival before testing No-Show (TD-10). A no-show right after arrival now answers
`NO_SHOW_TOO_EARLY`, which is the expected result.

## B4 · The car on a confirmed booking

`GET /bookings/{id}` now returns `vehicle`, the same shape as `nextJourney.vehicle` on passenger Home:

```json
"vehicle": {
  "make": "Kia",
  "model": "Sportage",
  "colour": "Silver",
  "seats": 4,
  "plateNumber": "ﺱ ﺹ ﻁ 4821"
}
```

- `vehicle` is `null` until the seat is `CONFIRMED` (BOOK-12). It is also set on `COMPLETED`, `ENDED_EARLY` and
  `EXITED_DUE_TO_DRIVER_DELAY`, and `null` on a cancelled or pending seat.
- The field is **`plateNumber`**, not `plate`, to match Home.
- `colour` is a string, translated by `Accept-Language`, as on Home.
- It is on the single read only (`GET /bookings/{id}`), not on `GET /my-bookings` rows.

## B5 · Delete or Pause during a live run: refused

The business rules say Delete works like Pause (COM-35), and Pause is not allowed during a live trip (COM-24). So both
`DELETE /commutes/{id}` and `POST /commutes/{id}/pause` now refuse while one of the commute's runs is `PREPARING`,
`EN_ROUTE` or `IN_PROGRESS`:

```json
{
  "error": {
    "code": "COMMUTE_TRIP_IN_PROGRESS",
    "message": "A trip on this commute is in progress. End it with Cancel Active Trip first.",
    "fields": { "tripId": ["01jc..."] }
  }
}
```

Status `409`. Nothing changes. The driver ends the run with `POST /trips/{trip}/cancel-active` first. Hide Pause and
Delete during a live run. Your test reset already cancels the active trip first, so it keeps working.

## B6 · `tripDate` on every booking

Every booking row now carries `tripDate` (`YYYY-MM-DD`, the Cairo date of the day), next to `tripId`. That includes
`bookings[]` from `POST /driver/seat-requests/{id}/approve`, `GET /my-bookings`, `GET /bookings/{id}` and every other
response that returns a booking.

```json
{ "id": "01jc...", "tripId": "01jb...", "tripDate": "2026-11-11", "status": "CONFIRMED", "...": "..." }
```

Use it to pick today's booking.

## B7 · `GET /seat-requests/{id}`

New. It returns one of the caller's own requests (passenger side), in **exactly** the same shape as a row of
`GET /seat-requests`. Anybody else's request, or an unknown id, answers `404 NOT_FOUND`. Use it to open a request from
a notification.

## B8 · Booleans in the search query

`GET /search/commutes` (note the path: `/search/commutes`, not `/commutes/search`) now accepts `true` / `false` (any
case) as well as `1` / `0` for `womenOnly` and `wantsReturnTrip`. Keep sending `1` / `0` if you like; both work.

## B9 · Time format on the schedule

`PUT /commutes/{id}/schedule` now accepts `HH:MM` as well as `HH:MM:SS` for `departureTime` and `promisedArrivalTime`,
at the top level and inside `perDay`. `12:00` is stored as `12:00:00`. Responses still return `HH:MM:SS`.

## B10 · `kind` on a seat request

There are two values only. A request on a full day answers `201` with `status: PENDING` and
`kind: waitlist_application`. It is not a refusal.

| `kind` | Means | Driver's yes | Driver's no |
|---|---|---|---|
| `seat_request` | A seat on a day with room. | `POST /driver/seat-requests/{id}/approve` (seats her) | `.../reject`, or `.../waitlist` to park her |
| `waitlist_application` | The day is full, or she already rides with somebody else at that time. | `POST /driver/seat-requests/{id}/waitlist` (puts her on the list) | `.../reject` |

- A `seat_request` can turn into a `waitlist_application` while it waits, never back. Re-read `kind`; don't cache it.
- `approve` on a `waitlist_application` for a full day answers `SEAT_UNAVAILABLE`.
- The driver is told with `waitlist_application_received` instead of `seat_requested`.
- On her side, say "Asked to join the waiting list", not "Seat requested".

This is in section 4.7 of the guide.

## B11 · Test environment

Not done in this round. Staging with OTP `123456` for test numbers only, one-call verification approval, account
cleanup and moving the clock are a separate piece of work. We will send a separate note when it is ready.

---

## New error codes

| Code | Status | Endpoint |
|---|---|---|
| `NO_ARRIVAL_MARKED` | 409 | `POST /trips/{trip}/no-show` |
| `NO_SHOW_TOO_EARLY` | 409 | `POST /trips/{trip}/no-show` |
| `COMMUTE_TRIP_IN_PROGRESS` | 409 | `DELETE /commutes/{id}`, `POST /commutes/{id}/pause` |

All three have English and Arabic messages in `error.message`, following `Accept-Language`.
