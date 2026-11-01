<!-- task: 123uve060qj · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

**Built:** `nextJourney.phase`, computed on the server:
`scheduled` (also used for a PENDING booking) · `driver_en_route` · `driver_arrived` · `on_board` · `driver_delayed` · `cancelled_by_driver` · `expired` · `exited` · `completed`.
`status` and `trip.status` are unchanged.

**How each one is recorded (your question):** these already existed; nothing new was invented.
- **Driver arrived:** the driver's GPS-checked "I've arrived" for her (`POST /trips/{trip}/wait-timers` or status `AT_PICKUP`) → `trip_wait_timers.arrived_at`.
- **On board:** her check-in (boarding code or the driver's check-in) → `attendance.checked_in_at`, until she is dropped off.
- **Exited without a fee:** `POST /bookings/{booking}/exit-without-fee` → booking `EXITED_DUE_TO_DRIVER_DELAY`, fee 0.
- **Expired:** the delay sweep writes the same booking status with reason `trip_expired` and sets the trip to `EXPIRED`. The server reports this as `expired`, not `exited`, so the card never says "you left" for a morning nobody came.

```json
"nextJourney": { "status": "CONFIRMED", "phase": "driver_en_route", "trip": { "status": "EN_ROUTE" } }
```

**App:** switch the card on `phase`.


