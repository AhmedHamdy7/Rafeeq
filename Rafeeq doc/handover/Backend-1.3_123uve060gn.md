<!-- task: 123uve060gn · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: one seat per passenger per time slot (R-3, BOOK-17)**

**What exists**
- Built in W4 (PR #20, `34a91d7`, `SameTimeBookings`). Documented in `da9654d` (branch `claude/backend-followups-db6d1f`).
- A passenger can't hold two seats whose times overlap, on any commutes. A 07:05 to work plus a 17:00 home on the same date is fine.
- This is enforced on driver approval, on accepting a waiting-list offer, and on the nightly recurring extension.
- Refusal: **`409 BOOKING_ALREADY_BOOKED`** with `error.fields.overlapsBookingId`. That id is her own booking, so you can name it to her; it is never shown to the driver.
- A switch is the exception: the old seat is given up in the same call.
- At request time this is not a refusal: an overlapping request becomes `kind: waitlist_application`.
- When one driver confirms her, her other pending requests for that time turn into waiting-list applications.
- Guide §4.7: "One seat per passenger per time slot" and the approve refusals.
- Tests: `SeatRequestFlowTest` and `WaitlistPromotionTest` (approval and offer paths).

**What the app must do**
- Passenger: on `BOOKING_ALREADY_BOOKED`, say which of her bookings clashes (`overlapsBookingId`).
- Driver: on approve, show "she's no longer free at that time".

**Open:** nothing.
