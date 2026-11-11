# Patrol findings of 2026-11-11: answers

**Date:** 2026-11-11 · **Answers:** `docs/blockers/backend-findings-2026-11-11.md` (mobile repo) · **Full contract:** `RAFEEQ_MOBILE_API_GUIDE.md`, change log entry "Fixes from the Patrol run (B-1 → B-11)"

All 11 findings are **Fixed**. None is "works as designed" and none is "later".

The fixes are on the backend branch `fix/patrol-findings-2026-11-11` and are **not deployed yet**. We will tell you
when they are on Railway. Run the scenarios again after that.

| # | Status | What the app needs to do |
|---|---|---|
| B-1 | ✅ Fixed | Nothing. Show `NO_SHOW` in History as its own ending. |
| B-2 | ✅ Fixed | Nothing. |
| B-3 | ✅ Fixed | Nothing. |
| B-4 | ✅ Fixed | Optional: read the new `endedAt` on live shares. |
| B-5 | ✅ Fixed | Nothing. |
| B-6 | ✅ Fixed | Use `POST /trips/{trip}/cancel-active` from `PREPARING`. |
| B-7 | ✅ Fixed | Nothing. |
| B-8 | ✅ Fixed | Expect 99, not 100, in PY-7 (the ledger rounds down). |
| B-9 | ✅ Fixed | Handle `422 COMMUTE_OVERLAPS` on publish. |
| B-10 | ✅ Fixed | Read `nextJourney.sameCancellation` for the card wording. |
| B-11 | ✅ Fixed | Nothing. `rating_due_riders` now also has `riderCount`. |
| T3 | ⏳ Staff | Staff set the InstaPay payee on the staging dashboard. No code change. |

---

## B-1 · A no-showed booking ends as `NO_SHOW`

**Cause:** completing the run set every confirmed booking to `COMPLETED`, including the ones the driver had marked
as a no-show.

**Now:**
- When the run completes, a booking whose attendance is `PASSENGER_NO_SHOW` ends **`NO_SHOW`**.
- When the run is called off (`cancel-active`), a booking already marked no-show also ends **`NO_SHOW`**. It is not
  counted in `meta.cancelled` or `meta.endedEarly`.
- `GET /my-bookings?scope=past` returns it with `status: "NO_SHOW"`.
- `POST /bookings/{booking}/rating` refuses it with **`422 TRIP_NOT_RATEABLE`** (it was already refused for
  `NO_SHOW`; the status just never reached `NO_SHOW` before).
- She no longer gets `rating_due` for that booking, and it no longer counts as a completed trip in her stats.

**Your check works as written:** the 4 steps → `GET /bookings/{booking}` → `status: "NO_SHOW"`.

## B-2 · `meetingPoint` after an approved pickup change

**Cause:** approving wrote the point but set the **label to `null`**, so the app showed "Your meeting point" with no
place name. Also, days created later for her recurring seat (the daily job that adds new days) still used the old
point.

**Now:**
- After the approve, every upcoming confirmed booking of hers on that commute has
  `meetingPoint: { lat, lng, label, isExact: true }` with **the label she sent**.
- Her approved seat request is updated too, so new days added later use the new point and label.
- The driver's views read the same booking columns, so they show the same point and label.

⚠️ We could not reproduce `meetingPoint: null` itself in our tests: the point was always written. If MR-22 still
shows `null` after the deploy, please send us the booking id and we will look at that row.

**Your check works as written:** approve → `GET /bookings/{booking}` → `meetingPoint.label` equals the label she
sent.

## B-3 · "Ride Again Regularly" after a finished trial

**Cause:** an approved trial request stayed `APPROVED` for ever, and `APPROVED` counts as an open request.

**Now:**
- A trial request whose booking has ended (completed, no-show, cancelled, ended early, expired) no longer counts
  as open.
- When she sends the recurring request, the finished trial request is closed with status **`ENDED`**.
- The recurring request is accepted: `201`, `status: "PENDING"`, and it reaches the driver's inbox.
- While the trial day is still ahead, a second request is still refused with `409 BOOKING_ALREADY_REQUESTED`.

`GET /commutes/{commute}` → `canRequest` follows the same rule.

## B-4 · Live-share links end with the ride

**Now:**
- **Her** links end at her **drop-off**, and at every other end of her ride: end early, exit without fee, no-show,
  her own cancellation, and the run being cancelled.
- **The driver's** links end when the **run** completes or is cancelled. Completing the run also ends any of hers
  that were still open.
- After that, `GET /safety/live-shares?tripSessionId=…` answers `isActive: false` with **`endedAt`** set, and the
  public link page shows the "no longer available" page.

New field on each live share:

| Field | Meaning |
|---|---|
| `endedAt` | When the link stopped working (the ride ended, it was revoked, or it expired). `null` while it works. |

Documented in guide 4.12.

## B-5 · The driver can call the passenger he is about to pick up

**Cause:** "next pickup" was read from the day's itinerary, and the itinerary was only built when the driver opened
navigation. Before that, every call answered `not_next_pickup`.

**Now:** `GET /bookings/{booking}/call` builds the itinerary itself if it is missing. From `EN_ROUTE` (and
`AT_PICKUP`) the earliest pickup not yet reached answers `200` with the number, **even before navigation is opened
and before any location point is sent**. Other passengers still get `409 CALL_NOT_AVAILABLE`,
`reason: "not_next_pickup"`.

## B-6 · A started run in `PREPARING` can be called off

We chose **`POST /trips/{trip}/cancel-active`**, because TRIP-22 says a started trip is ended only through Cancel
Active Trip.

**Now:** `cancel-active` accepts a run in **`PREPARING`**, with the same body (`reason`, `note`), the same rules, the
same fees and the same passenger outcomes as from `EN_ROUTE` with nobody aboard:
- `200`, the run is `CANCELLED`;
- every passenger is `CANCELLED_BY_DRIVER` and gets `active_trip_cancelled`;
- `meta.cancelled` counts them.

`POST /trips/{trip}/cancel` ("cancel today") still refuses once the run has started. Only `cancel-active` answers
`TRIP_NOT_CANCELLABLE` now for a run that is already finished or cancelled.

## B-7 · A no-show fee shows as a fee line

**Cause:** the fee line was only recalculated when her debt changed. A booking made **after** the fee (as in PM-2)
never got the line.

**Now:** the line is also recalculated when one of her bookings is **made** or **cancelled** (by her, by the driver,
or by "cancel today"). Her whole unpaid balance sits on her **next confirmed ride that has not left**; every other
booking shows `0`.

So after step 1, `GET /my-bookings?scope=upcoming` → the next booking has `feeLinePiastres: 3750` and a
`feeLineReason`. The reason is the code of her newest fee: `no_show`, `late_cancel` or `late_boarding` (or `credit`
for a negative line). It is `no_show`, not `no_show_fee`.

## B-8 · The first missed Friday adds the late fee

**Cause:** a problem in the test call, not in the product rule. `POST /testing/debt/deadline` moves the debt lines
back a week, but an older late fee from an **earlier run in the same week** kept its old key. The sweep found that
key and skipped the new fee.

**Now:** the test call moves the late-fee keys together with their weeks. After one call:
- `lateFeePiastres` = 10 % of that week's unpaid commission, **rounded down to the piastre**. For PY-7 (997) that is
  **99**, not 100;
- it shows on the statement as a `late_fee` line;
- a second call adds nothing more to that week. It moves past the second Friday instead (driving suspended).

Please change the PY-7 expectation to `lateFeePiastres == floor(overduePiastres × 10 %)`.

## B-9 · Overlapping commutes are refused

**Cause:** the COM-36 rule was not checked when publishing. It wasn't a regression in this code: the check did not
exist here.

**Now:** `POST /commutes/{id}/publish` refuses a commute that clashes with another of the driver's **published or
paused** commutes. A clash is the same weekday, overlapping dates, and less than **30 minutes** between the end
(promised arrival) of one and the departure of the other.

```json
{ "error": { "code": "COMMUTE_OVERLAPS", "message": "This commute overlaps another of your commutes. Leave at least 30 minutes between them.",
  "fields": { "commuteId": ["01J…"], "weekday": ["WEDNESDAY"], "minGapMinutes": ["30"] } } }
```

`422`. The commute stays a `DRAFT`. Show which commute and which day clash (`commuteId`, `weekday`). The message is
in English and Arabic.

## B-10 · One card per cancellation on Home

**Now:**
- When the driver cancels several of her days in **one action** (for example, deleting the commute),
  `nextJourney` ② shows **one** ended journey: the **first** of those days.
- That card has a new field:

  | Field | Meaning |
  |---|---|
  | `nextJourney.sameCancellation` | `null` for a single day. Otherwise `{ "count": 12, "firstDate": "2026-11-13", "lastDate": "2026-12-02" }`. |

- `POST /bookings/{booking}/seen` on that card marks **all** the days of that cancellation as seen. The next
  `GET /home` moves on to her next confirmed commute.

The wording is the designer's call. `count`, `firstDate` and `lastDate` should be enough for "Omar cancelled 12
days (13 Nov – 2 Dec)".

Days count as one cancellation when the same driver cancelled them on the same commute, for the same reason, within
two minutes of each other.

## B-11 · `rating_due_riders` reaches the driver

**Cause:** the driver was only asked when someone was still aboard when the run completed. In N-8 the passenger
had already been dropped off (her booking was already `COMPLETED`), so the driver got nothing.

**Now:** when the run completes, the driver gets **one** `rating_due_riders` if anybody rode (completed or ended
early), whether they were dropped off before or at completion.

| `type` | Who | `data` |
|---|---|---|
| `rating_due_riders` | Driver | `tripId`, `riderCount` (new) |

A run where nobody rode (for example, everybody was a no-show) sends nothing to the driver.

---

## Still open from the testing calls

- **T3 · InstaPay payee:** this is a setting on the staging dashboard, not code. We will ask staff to set a test payee
  in **Settings → Debt** on staging, and we will tell you when `GET /account/debt` answers `instapay` there.

## Questions

We have not answered `questions-2026-11-11.md` here. It is answered separately.
