# Patrol findings of 2026-11-11: answers

**Date:** 2026-11-11 · **Answers:** `docs/blockers/backend-findings-2026-11-11.md` (mobile repo) · **Full contract:** `RAFEEQ_MOBILE_API_GUIDE.md`, change log entry "Fixes from the Patrol run (B-1 → B-11)"

B-1 → B-12 are **Fixed**, deployed and checked by you (PRs #56 and #57). B-13's first part and the `500` on
completion are deployed (PR #58). **B-13's second part** is on the backend branch `fix/debt-suspension-held-b13` and
is **not deployed yet**.

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
| B-12 | ✅ Fixed | Nothing for the move. Read `skippedDays[].code` to see why a day was skipped. |
| B-13 | ✅ Fixed (second part) | Read `openDisputes` / `suspensionOnHold` on `GET /account/debt`. Resolve or avoid stale disputes on test accounts. |
| — | 🐞 Found and fixed | `POST /trips/{trip}/complete` answered `500` on staging when the run had 2+ confirmed passengers. |
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

## B-12 · Moving a later run onto today

**Cause:** a commute has one run per day (a unique index on the commute and the date). Moving the 18 Nov run onto
today put it on the date today's run already held, and the database refused it. The call didn't catch that, so it
answered `500`. It worked before only when the commute had no run today.

**Now:** `POST /testing/trips/{trip}/departure` checks the target day first:

| Run already on that day | What happens |
|---|---|
| none | As before. |
| `SCHEDULED` | The two **swap days**. Today's run moves to the moved run's old day (18 Nov), at its own time, with its bookings. |
| finished (`COMPLETED`, `CANCELLED`, `EXPIRED`) | It moves back to the nearest free earlier day. It stays finished. |
| underway (`PREPARING`, `EN_ROUTE`, `IN_PROGRESS`) | **`409 TRIP_DAY_TAKEN`** with `fields.tripId`, `tripDate`, `status`. Finish or cancel it first. |

The answer gains `swappedTrip`: `{ tripId, departureAt }` of the run that moved out of the way, or `null`.

So your step 3 now answers `200` with the new `departureAt`, and her booking on the 18 Nov run comes with it.
Documented in guide 4.17.

### Why a recurring approval skipped today as `unavailable`

It is **not** the 12-hour lock. A day is skipped as:

| `reason` | Why |
|---|---|
| `full` | No seat left. Goes on her waiting list. |
| `breaks_arrival` | Inside the 12-hour lock, her points would make the driver miss the arrival promised to riders already on that day (`BOOKING_BREAKS_FROZEN_PROMISE`). Goes on her waiting list. |
| `unavailable` | She already holds a seat on that run, or she rides another commute at the same time (`BOOKING_ALREADY_BOOKED`), or the run has already left (`BOOKING_DEADLINE_PASSED`). |

We can't tell which one it was from the report. The usual case in the tests is that she **already holds a seat that
day**: a trial on today's run, or a seat on another commute at the same time. Her existing seat stands, so the
recurring approval skips that day.

To make this visible, each skipped day now has **`code`**, the exact refusal:

```json
"skippedDays": [{ "date": "2026-11-11", "tripDate": "2026-11-11", "reason": "unavailable", "code": "BOOKING_ALREADY_BOOKED" }]
```

If the next run shows a different `code`, send it to us with the trip id.

## B-13 · A passenger's unpaid fee and the 14-day suspension

**The rule (PAY-17):** she is suspended when a fee stays unpaid for **14 days without riding**, or when she owes
**over 200 EGP**. The 14 days run from **the day the fee was charged**, also while it rides on an upcoming booking as
a fee line. Riding is how it gets paid: the line is settled at her drop-off and the debt clears. If she doesn't ride
in those 14 days, the fee ages. Suspension then cancels every upcoming seat, and new requests answer
`RIDING_SUSPENDED_FOR_DEBT`. A fee line on a booking doesn't stop the clock.

**What we checked:** PM-4 passes on our side, including after B-7. A passenger with a no-show fee riding on her next
booking, aged 15 days with `POST /testing/debt/age {"days": 15, "side": "passenger"}`, is `suspended: true`, her
booking is cancelled, and a new request answers `RIDING_SUSPENDED_FOR_DEBT`. We tried the real no-show flow, a
recurring rider and a plain fee: all suspend. B-7 doesn't change the ledger, only which booking shows the line, so
your guess isn't the cause.

**What we couldn't explain:** your step 3 showed `owedPiastres: 12500` with `oldestUnpaidAt: null`. That can only
happen when the ledger lines on Karim's account add up to less than his balance. We can't see the staging data
from here.

**What we changed:**
- `oldestUnpaidAt` can no longer be `null` while she owes money. If the lines don't add up to the balance, the
  clock starts at her oldest unpaid fee. So the ageing works even on an account like Karim's.
- 🐞 **A separate bug we found while testing this:** `POST /trips/{trip}/complete` answered **`500`** on staging when
  the run had **two or more** confirmed passengers (the server ran in strict mode and refused to load each passenger
  one by one). Runs with one passenger were not affected, which is why your chapters passed. Fixed.

**After the deploy:** re-run PM-4. If `oldestUnpaidAt` is still `null` or she isn't suspended, please send us Karim's
`GET /account/debt/statements` (passenger side) and we'll check his lines.

### B-13 · Re-check after PR #58: what still held the suspension

Thanks for Karim's statements. They add up: 12500 owed from the fee of 20:29, so the ledger was fine, and
`oldestUnpaidAt` aged as it should (PR #58 is live). The 14 days and the 200 EGP limit were both reached. What stopped
the suspension is **PAY-19: no debt suspension while a dispute is open.**

Two problems with how that worked:

1. **It counted disputes that aren't about this debt.** It counted every open attendance dispute on a booking where
   he was the passenger **or the driver**. So if a passenger contested a no-show on a run he drove, his own
   **passenger** debt could never be suspended. Our new test shows exactly this. **Fixed:** each side now only counts
   disputes about that side's debt.
2. **Nothing told you.** **New** on both sides of `GET /account/debt`:

   | Field | Meaning |
   |---|---|
   | `openDisputes` | `[{type, id, bookingId}]`: disputes still waiting for staff about this side's debt. `type` is `attendance` (a contested no-show, `id` = the booking), `warning` (a disputed warning) or `fee_appeal` (an appeal against a fee). |
   | `suspensionOnHold` | `true` when she would be suspended now but an open dispute holds it. |

Karim's test account has a long history of no-shows from earlier runs. If one of **his own** disputes is still open
(for example from a "contest the no-show" or "report incorrect arrival" scenario), the suspension is held, and that
is the rule working. After the deploy:

- Re-run PM-3 and PM-4 and read `passenger.openDisputes`.
- If it is empty, `suspended` should be `true`.
- If it lists his own disputes, they are leftovers from earlier scenarios. Use an account with no open dispute for
  PM-3/PM-4, or ask us to resolve them on staging (staff decide disputes on the dashboard).

---

## Still open from the testing calls

- **T3 · InstaPay payee:** this is a setting on the staging dashboard, not code. We will ask staff to set a test payee
  in **Settings → Debt** on staging, and we will tell you when `GET /account/debt` answers `instapay` there.

## Questions

We have not answered `questions-2026-11-11.md` here. It is answered separately.
