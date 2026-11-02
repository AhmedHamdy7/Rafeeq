<!-- task: 123uve060qg · assign: Omar Aly (94558637) · status: backend answered - check -->

**HOME is answered on the backend.** Merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`). Everything is additive; no existing field changed meaning. One new endpoint: `POST /matches/{match}/opened` (182 endpoints).

- 📝 HOME-1: done, **one decision for you**. A completed-but-unrated ride shows all of its own day, and after that only when nothing is upcoming (literal "until rated" would hide tomorrow's pickup for up to a week).
- ✅ HOME-2: `phase`. Arrived, on board and exited were already recorded (wait timer, attendance check-in, booking status).
- ✅ HOME-3: `daysMask`, `pickup` (with her pickup time), `dropoff` (promised arrival). `meetingPoint` kept.
- ✅ HOME-4: `live`; poll every 30 s while it's non-null.
- ✅ HOME-5: `canLeaveWithoutFee` → the existing `POST /bookings/{booking}/exit-without-fee`.
- ✅ HOME-6: `endedAt` plus ≤ 2 `alternatives`.
- ✅ HOME-7: `completedAt` plus `canRate`.
- ✅ HOME-8: `demandAlert`; the app must call the new `POST /matches/{match}/opened`.
- ✅ HOME-9: `demandTitle`, `seatsAvailable`, `driver.completedTrips`, `lastSearchedAt`.
- ✅ HOME-10: `unreadNotifications`.

Tests: new `tests/Feature/Http/HomeJourneyStatesTest.php` (16) plus the existing Home, wait-timer, delay-exit, alternatives, guide, OpenAPI and screen-map suites, all green. The full suite was not run.

---

**Follow-up: gaps against the business specs** (branch `fix/home-gaps`)

- TRIP-05 / D-0020: Leave Without Fee now opens **5 min after departure** when the run hasn't started (was: at departure). New reason `late_for_pickup`: the run started but the driver hasn't arrived **5 min after her pickup time**. Both show `phase: driver_delayed`; `live` stays filled when the run has started.
- TRIP-01: the card shows **confirmed** journeys only; a `PENDING` request is no longer on it.
- TRIP-26: `nextJourney.checkIn {groupId, answer, cutoffAt, canAnswer}` → `POST /groups/{group}/attendance`.
- TRIP-02 / TRIP-29 on `GET /driver/home`: `laterThisWeek[]`, `notices[]` (`seat_opened` / `passenger_switched`, last 24 h), `nextRun.leaveBy {at, local, lateWarning}`.
- Car type: `vehicle.make` + `model` + `colour` already cover "Kia Sportage · Silver"; nothing added.
- HOME-1 "until she has seen it" is now real: the card keeps an ended or completed-unrated journey until the app calls `POST /bookings/{booking}/seen` (no body, harmless to repeat), for up to 7 days. `nextJourney.isSeen`, `booking.outcomeSeenAt`. `409 BOOKING_NOT_ENDED` on a journey that hasn't ended. Call it once the ended card has been on screen.
