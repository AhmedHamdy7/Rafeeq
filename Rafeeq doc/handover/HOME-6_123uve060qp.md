<!-- task: 123uve060qp · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

```json
"endedAt": "2026-10-18T04:12:00+00:00",
"cancellationFeePiastres": 0,
"alternatives": [ { "commuteId": "01k9…", "tripId": "01k9…", "departureAt": "…", "departureLocal": "2026-10-18 07:30:00",
  "originLabel": "Fifth Settlement", "destinationLabel": "Smart Village B12", "seatsAvailable": 2,
  "driver": { "publicFirstName": "Karim" } } ]
```
- Filled **only** on `cancelled_by_driver` / `expired` / `exited`; otherwise `endedAt: null`, `alternatives: []`.
- The alternatives are the first 2 of `GET /bookings/{booking}/alternatives` (same engine and ranking), cached 5 minutes because Home is polled. Nothing is booked. An empty list when nothing fits.
- Note: the search only returns runs that haven't departed, so on a morning that's already late this is often a later run or tomorrow's.


