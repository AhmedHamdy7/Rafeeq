<!-- task: 123uve060t5 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-11 GET /matches: full match-result shape

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

Each `/matches` row keeps every field it had and adds the search result's shape: `tripId`, `tripDate`, `departureAt`, `departureLocal`, `promisedArrivalAt/Local`, `daysMask`, `seatsAvailable`, `womenOnly`, `meetingPoint`, `dropOffPoint`, `rules`, `otherDays[]`, `myStatus`, `overlapsBooking`. `driver` is now the full `person` (+ `ratingCount`, `completedTrips`); `vehicle` adds `colour`, `tier`, `hasAc`.

- The day is the commute's soonest upcoming day on the **demand's** weekdays; the points are the commute's stops nearest the demand's origin and destination. All `null` (and `otherDays: []`) when no such day exists right now.
- `score` stays the integer score at match time (no breakdown on /matches).
- HOME's `POST /matches/{match}/opened` returns the same resource, so it carries these too.

### Next: @ahmed Ibrahim (Flutter)
Home rows "Omar · 08:00 · EGP 70 · 2 seats left" from `departureLocal`, `seatPricePiastres`, `seatsAvailable`; tap → `GET /commutes/{commuteId}/public`.
