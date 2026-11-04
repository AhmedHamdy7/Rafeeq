# Driver Home: answers for mobile

> **Replies to:** `driver_home_backend_gaps.md` · **Date:** 2026-11-04 · **Branch:** `fix/home-driver-gaps`

All new fields are in Mobile API Guide §4.4 and §4.11. Behaviour changes are DH-1, DH-13 and DH-16, plus the trip cancellations under "No field 1". New notifications `driver_licence_expired` and `driver_suspended` are in §4.14.

## What changed

| # | Result |
|---|---|
| DH-1 | Done. A late run stays on `nextRun`, today only, until the sweep expires it. |
| DH-2 | Done. `nextRun.startDeadlines`. |
| DH-3 | Done. Top-level `expiredRun`, from a new `expired_at` column. |
| DH-4 | Done. `promisedArrivalAt` / `Local`. `null` when none was promised; no estimate is offered. |
| DH-5 | Done. `startedAt` / `Local`. |
| DH-6 | Done. `waitlistCount` on `nextRun` and `laterThisWeek[]`. |
| DH-7 | Done. `waitlist.count`. |
| DH-8 | Done. `nextRun.phase`. |
| DH-9 | Done. `rejoinedWaitlist` on every notice. |
| DH-10 | Done. `canStart`, `startOpensAt` / `Local`. |
| DH-11 | Done. Top-level `verification`. It also has the `actionItem` `status`, so you can tell rejected from expired. |
| DH-12 | Done. `suspensionReason` on both endpoints. |
| DH-13 | Done. A reviewer can reject just the registration or the car details, with a reason on that item. Rejecting the whole application still gives `actionItem.key: "application"`. |
| DH-14 | Not added. Use the full label with an ellipsis. |
| DH-15 | Fixed. See below. |
| DH-16 | Done. A complete draft stays `in_progress` (PROF-15 has no other status for it). When `GET /driver/application` answers `missing: []`, send the driver to submit. |
| DH-17 | Done. See below. |

## Your questions

| Question | Answer |
|---|---|
| DH-2: free exit at +5 or +10? | **+5** (D-0020, TRIP-03). +10 records the violation. The +10 title is copy; please reword it. |
| DH-3: how long does `expiredRun` stay? | **Until the end of that local day** (D-0032). No extra endpoint. |
| DH-3: how does History read an expired day? | `GET /driver/bookings`: the booking's `trip.status` is `EXPIRED`. A day nobody booked has no booking, so it is not listed. |
| DH-5: `status` at a pickup? | **`EN_ROUTE`.** |
| DH-7: one person on two days? | **Counts once.** |
| DH-8: the soon window? | **30 minutes**, a server setting (D-0032). Use `phase`. |
| DH-9: is a switch also a `seat_opened`? | **No, one event and one notice** (D-0032). Draw both cards from it with its date if the design needs both. The design's Sun and Tue cards are two separate events: Tue is its own `seat_opened`. |
| DH-9: are seats offered automatically? | **Yes** (D-0019). §8 question 9 was out of date and is corrected. |
| DH-11: a whole account suspended? | The `user.suspension` banner with Appeal (D-0018). `GET /driver/home` still answers. `canStart` is `false`. `verification.overall` describes the driver profile, so it may still read `verified`. |
| DH-13: `rejectionReason`, fragment or sentence? | **The reviewer's own words.** Show it as its own sentence. |
| DH-13: `renewal_required` for a car paper? | `actionItem.key` says which item expired. The car-paper copy is still needed from design. |
| DH-15: old data or a bug? | **Both.** Seeded and older accounts never got capability rows; that is backfilled. Adding driving also did not restore a missing `PASSENGER`; that is fixed. |
| DH-15: ID and selfie counted without the driver capability? | **Intended.** They are the same documents on the same account. |
| DH-16: automatic submit? | **No, the driver submits** (PROF-16). |
| DH-17: does the admin always send a date? | **No, it never does.** The typed date is now copied at approval, and existing approved licences are backfilled. |
| No field 1: can a driver who can't drive have runs? | **No, those days get cancelled.** An expired licence (COM-26, a daily job) and a driver suspension (PROF-27) pause every published commute and cancel upcoming seats with no fee. `pausedReason` is `licence_expired` or `driver_suspended`. Until the daily job runs, `nextRun` may still show a run, so keep ignoring it in `suspended` and `renewal_required`. `canStart` is `false` there. |
| No field 3: `Level X of 5`? | **Show the API's number.** It counts approved items only. |

## Decisions still with the product owner

- DH-10: at T−2 h 10, is Start disabled or active with the window explained on tap? The API supports both.
- No field 2: "No commutes yet" on a suspended driver with paused commutes.
- D-0032 (the soon window, the expired note, one notice per switch) still needs the other partners' approval, so these three may change.

## Test accounts

Dev OTP, on staging once this is deployed. To create them or reset their times:

```bash
php artisan demo:driver-home-states
```

Departures are set relative to the moment you run it, so run it again just before testing `F3D-04` and `F3D-05`.

| Phone | State |
|---|---|
| +201055557001 | `F3D-01` scheduled, +130 min |
| +201055557002 | `F3D-02` starting soon, +25 min |
| +201055557003 | `F3D-03` started |
| +201055557004 | `F3D-04` not started, +1 min |
| +201055557005 | `F3D-05` late, +10 min |
| +201055557006 | `F3D-06` expired today, next commute later |
| +201055557007 | `F3D-07` passenger cancelled |
| +201055557008 | `F3D-08` switched and rejoined the list |
| +201055557009 | `F3D-08` switched, did not rejoin |
| +201055557011 | `under_review` |
| +201055557012 | `action_required` |
| +201055557013 | `expiring` |
| +201055557014 | `renewal_required` |
| +201055557015 | `suspended` |
