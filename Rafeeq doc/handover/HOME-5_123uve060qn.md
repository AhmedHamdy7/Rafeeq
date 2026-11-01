<!-- task: 123uve060qn · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

- `nextJourney.canLeaveWithoutFee: bool`. It's the same server check the endpoint makes before it acts, so the button is never refused when this is `true`. Also `true` mid-run if the car has passed her stop without collecting her.
- **Endpoint (already existed):** `POST /v1/bookings/{booking}/exit-without-fee`. No body is required (optional `reason`). Returns the booking (`status: EXITED_DUE_TO_DRIVER_DELAY`, `cancellationFeePiastres: 0`).
  - `409 BOOKING_NOT_CANCELLABLE` with `fields.driverDelay: ["NOT_DELAYED"]` when she's not entitled yet; with `fields.status` when the booking isn't CONFIRMED.
  - `404` if it's not her booking.
- After it, `/home` shows the same journey with `phase: "exited"` (HOME-6).
- **Find Alternative** → `GET /bookings/{booking}/alternatives` (works while she's still CONFIRMED).


