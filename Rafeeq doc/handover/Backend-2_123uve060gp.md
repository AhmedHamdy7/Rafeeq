<!-- task: 123uve060gp · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: the 21:00 booking deadline is removed (R-5, N2)**

**Decision:** removed, as the partners decided. Nothing in the business docs keeps it:
- requests never expire (R-4);
- the 16-hour lock is what protects a booked morning;
- a car can even be asked for a seat while it is moving.

It only refused people seats that were still free. (The other "9 PM" in the decisions file, Q-019, is the attendance check-in, already 2h before departure. It is unrelated.)

**What changed** (commit `da9654d`, branch `claude/backend-followups-db6d1f`)
- Migration `2026_11_01_130000` **drops `scheduled_trips.booking_deadline_at`**, so all ~30 generated days per commute are fixed in one step. `rafeeq.commute.booking_deadline_hour` is removed from the config.
- A day takes requests (and approvals) **right up to its departure**. Search shows it until then too.
- 🛑 **`bookingDeadlineAt` is removed** from the scheduled-trip shape.
- `BOOKING_DEADLINE_PASSED` keeps its name because the app already handles it. It now only means **"that day has already left"** (422). The guide no longer mentions a 21:00 cut-off.
- Guide: §2.1, §4.7 (request refusals, trip shape) and the change log.
- Tests: `SeatRequestFlowTest` ("takes and approves a request right up to the departure"), `SearchHardFiltersTest` ("still shows tomorrow morning late the night before") and `ScheduledTripGenerationTest` ("generates days with no booking deadline").

**What the app must do**
- Stop reading `bookingDeadlineAt`.
- Remove any "book before 9 PM" copy or greying-out.
- Keep handling `BOOKING_DEADLINE_PASSED` as "this day has left".

**Open:** nothing on the backend. It is no longer an admin setting because there is no deadline at all.
