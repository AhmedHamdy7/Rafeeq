# Test APIs for the full-flow Patrol test: answers

**Date:** 2026-11-11 · **Answers:** `docs/blockers/backend-test-apis-2026-11-11.md` (mobile repo) · **Full contract:** `RAFEEQ_MOBILE_API_GUIDE.md`, section 4.17 (now **220 endpoints**)

T1, T2 and T4–T9 are built. T3 needs no new call: we set the InstaPay details on the dashboard. The changes are on the
backend branch `feat/testing-calls-t1-t9` and are **not deployed yet**. We will tell you when they are live.

Same rules as the first three calls:

- Every call answers **`404`** unless the server has `RAFEEQ_TESTING_API=true` and is not production.
- Each call acts only on the caller's own data. The one exception is T8, which runs a job for the whole server, as
  the real schedule does.

| # | Status | Call |
|---|---|---|
| T1 | ✅ Done | `POST /testing/debt/payments/{payment}/confirm` · `/reject` |
| T2 | ✅ Done | `POST /testing/trips/{trip}/wait-timers/expire` |
| T3 | ✅ Dashboard | No call. We enter the InstaPay details under **Settings → Debt** on the test server. |
| T4 | ✅ Done | `POST /testing/debt/deadline` · `POST /testing/debt/age` |
| T5 | ✅ Done | `POST /testing/driver/suspend` · `/reinstate` |
| T6 | ✅ Done | `POST /testing/driver/documents/{key}/expiry` |
| T7 | ✅ Done | `POST /testing/bookings/{booking}/age` |
| T8 | ✅ Done | `POST /testing/jobs/{name}/run` |
| T9 | ✅ Done | `POST /testing/attendance` |

---

## T1 · Confirm or reject a payment

- **Caller:** whoever sent the payment (`POST /account/debt/payments`), driver or passenger.
- **Confirm:** no body.
- **Reject:** optional `{ "note": "No transfer found." }`.
- **Answer:** the payment, the same shape as `GET /account/debt` → `payments[]`. The new `status` is `confirmed` or
  `rejected`, and `decidedAt` is set. (Your ask said `resolvedAt`; the field is `decidedAt`, as on the debt screen.)
- **Effect:** exactly what staff's decision does. On confirm, the debt goes down and the push
  `debt_payment_confirmed` is sent. On reject, nothing changes and `debt_payment_rejected` is sent.
- **Refusals:**
  - `409 DEBT_PAYMENT_NOT_PENDING` once the payment is decided;
  - `404` for someone else's payment.

## T2 · End a wait now

- **Caller:** the driver of the run.
- **Body:** `{ "bookingId": "…" }`.
- **Answer:** the timer, the same shape as `GET /trips/{trip}/wait-timers`. `expiresAt` is one second ago, so
  "past the wait time" shows at once, and `POST /trips/{trip}/no-show` is accepted.
- **Refusal:** `404` when that booking has no running timer. Mark arrival first.

## T3 · InstaPay payee

No call is needed. We enter the payee once under **Settings → Debt** on the test server, and it stays there. Then
`GET /account/debt` → `instapay` is filled and the Pay card shows. We will tell you when it is set.

## T4 · Debt deadline and fee age

**`POST /testing/debt/deadline`**, body `{ "side": "driver" }` (`driver` is the default).

It moves the caller's debt lines on that side **one week back**, then runs the weekly debt sweep, exactly as the
Friday 23:59 does.

| Calls | What you see |
|---|---|
| 1 | The Friday has passed. `overduePiastres` > 0, the **+10% late fee** is added once, and publish/start answer `DRIVER_COMMISSION_OVERDUE`. |
| 2 | The second Friday has passed. **Driving is suspended** (`suspendedAt` is set), and the commutes are paused with `pausedReason: commission_unpaid`. |

**`POST /testing/debt/age`**, body `{ "days": 15, "side": "passenger" }` (`passenger` is the default).

It moves the caller's debt lines `days` back, then runs the sweep. With 15 days, a passenger's booking is suspended
(`RIDING_SUSPENDED_FOR_DEBT`).

**Answer (both):** that side as in `GET /account/debt` (`owedPiastres`, `overduePiastres`, `lateFeePiastres`,
`dueAt`, `suspendedAt`, …).

Paying the debt (T1 confirm) lifts the suspension the normal way.

## T5 · Suspend and reinstate

`POST /testing/driver/suspend` and `POST /testing/driver/reinstate`, body
`{ "scope": "account" | "vehicle", "reason": "…", "vehicleId": "…" }`.

- **`account`:** suspends or reinstates the caller's account, as staff do from Members. Commutes are paused with
  `account_suspended`, and Driver Home shows the suspended card with the `reason`.
- **`vehicle`:** suspends or reinstates the car named by `vehicleId`, or the active car when it is left out. The
  driver gets `vehicle_suspended` / `vehicle_reinstated`.
- `reason` is optional on both. A default text is used when it is left out.
- **Answer:** `{ accountStatus, vehicle: { id, verificationStatus } }`.
- **Refusals:** `409 MEMBER_NOT_ACTIVE` (already suspended) and `409 MEMBER_NOT_SUSPENDED` (not suspended).
- The calls work while the account is suspended, so reinstate always works.

## T6 · Document expiry

`POST /testing/driver/documents/{key}/expiry`, body `{ "expiresAt": "YYYY-MM-DD", "vehicleId": "…" }`.

- **`key`:** `licence` or `vehicle_registration`. Anything else answers `404`.
- It sets the date, then runs that document's two daily checks ("expiring soon" and "expired").
- **A date in the next few weeks:** "Licence expires soon" / the registration warning, with its push.
- **A past date:** the licence is stopped (status `EXPIRED_DOCUMENTS`, renewal required, commutes paused with
  `licence_expired`), or the car is suspended for an expired registration.
- **Answer:** `{ key, expiresAt }`.

## T7 · Age a booking

`POST /testing/bookings/{booking}/age`, body `{ "days": 8 }` (1–60).

- **Caller:** the passenger of the booking, or its driver.
- **The run must not be under way**, otherwise `409 TRIP_ALREADY_STARTED`.
- **What moves:** it moves that day **`days` back**:
  - the departure and promised arrival;
  - the run's start and end;
  - a cancellation;
  - the attendance times.

  Then it reveals the ratings that are due.
- **Use it for:**
  - rating after 8 days, refused with `409 RATING_WINDOW_CLOSED`;
  - a one-sided rating revealed after 7 days;
  - the 24-hour report and dispute windows (1 day).
- **Answer:** `{ bookingId, tripId, departureAt }`.

The date of the day (`tripDate`) doesn't move, so it doesn't clash with the commute's other days. Read
`departureAt` for the time.

## T8 · Run a job now

`POST /testing/jobs/{name}/run`, no body. It answers `{ job, command, exitCode, output }`. It runs for the whole
server, as the schedule does.

| `name` | What it does |
|---|---|
| `generate-trips` | Adds the next days of every commute (next week's seats) |
| `roll-memberships` | Seats recurring members on the new days, and closes days that passed |
| `match-demands` | Matches saved demands against the published commutes |
| `expire-demands` | Expires old demands |
| `expire-requests` | Closes expired requests and offers the car has passed |
| `reveal-ratings` | Reveals ratings whose window ended |
| `rating-reminders` · `trip-reminders` | Sends the reminders |
| `auto-finish-trips` · `sweep-delays` | Finishes forgotten runs and runs the lateness ladder |
| `settle-cash` · `sweep-debts` | Settles cash seats and runs the weekly debt sweep |
| `warn-licences` · `stop-licences` · `warn-registrations` · `stop-registrations` | The document expiry checks |

Any other name answers `404`.

**End of a paused seat:** no job is needed. A paused seat counts as paused only until its end date. Pause with
`toDate` = today (`POST /groups/{group}/absences`), then run `roll-memberships`, and the next day seats her again.

## T9 · Seed past offences

`POST /testing/attendance`, body `{ "kind": "no_show" | "late_boarding" | "late_cancel", "count": 2 }` (1–10).

- It adds `count` past offences of that kind to the caller's history, one per day before today. They are close
  enough together to count on the same fee ladder.
- **Answer:** `{ kind, standing, nextRung }`. `nextRung` is the rung the **next real** offence lands on.
- **Example:** seed `no_show` × 1, then a real no-show is the 2nd one, with its fee and warning. Seed
  `late_boarding` × 2, then a real late boarding is the 3rd.
- Seeded offences are only history. They charge no fee and send no push.

---

## Also fixed today

A location point stamped in the **same second** as the newest point was ignored. Now it moves the car again. Only
strictly older points are kept off the map. This also fixes two tests that were failing on `main`.
