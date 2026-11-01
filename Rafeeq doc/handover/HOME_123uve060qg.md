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
