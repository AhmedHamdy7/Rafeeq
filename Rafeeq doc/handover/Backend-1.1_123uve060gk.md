<!-- task: 123uve060gk · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: waiting-list applications approved by the driver (R-1, BOOK-18)**

**What exists**
- Built in W4 (PR #20, `34a91d7`). Documented, with the rest added, in `da9654d` on branch `claude/backend-followups-db6d1f`.
- **Full-day request response:** `POST /commutes/{commute}/seat-requests` returns **201**, `status: PENDING`, **`kind: waitlist_application`**. A recurring request is an application only when *every* upcoming day it asks for is full. It is the same when she already holds an overlapping seat on another commute. A `seat_request` can turn into an application while it waits, never the other way.
- **`GET /driver/seat-requests`**: each row has `kind` (`seat_request` / `waitlist_application`) and **`hasOtherConfirmedCommute`** (a plain true/false). The list is **newest first**.
- **Approve call for an application:** `POST /driver/seat-requests/{seatRequest}/waitlist` puts her on his list (`WAITLISTED`, `waitlistPosition`, `expiresAt: null`). Decline is `/reject`. Don't offer `/approve` on an application.
- **Driver notification (new):** `waitlist_application_received`, with `data.seatRequestId` and `data.kind`. It replaces `seat_requested` for applications.
- **Cap 10 is now an admin setting:** `booking.max_waitlist_size` under Settings → "Booking and waiting list". Past the cap: **`409 BOOKING_WAITLIST_FULL`**. The code is `BOOKING_WAITLIST_FULL`, not `WAITLIST_FULL`.
- Guide: §4.7 ("A full day… a waiting-list application", the driver inbox, `/waitlist`, the seat-request shape) and §4.14.
- Tests: `SeatRequestFlowTest`, `WaitlistPromotionTest` (including the admin-setting cap) and `NotificationsTest`.

**What the app must do**
- Passenger: when `kind = waitlist_application`, show "Asked to join the waiting list" instead of "Seat requested". Re-read `kind`; don't cache it.
- Driver inbox: choose the buttons by `kind`. Show the `hasOtherConfirmedCommute` hint. Route the `waitlist_application_received` push to the request card. Handle `BOOKING_WAITLIST_FULL`, and don't hard-code 10.

**Open:** nothing.

**Merged to main in `f9cc8cc`.**
