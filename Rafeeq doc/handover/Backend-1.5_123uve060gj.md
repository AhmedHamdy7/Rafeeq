<!-- task: 123uve060gj · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: deleting a commute cancels every upcoming trip, booked days too (C-8, COM-35)**

**What exists**
- Built in W4 (PR #20, `34a91d7`); fees added in W3 (PR #23). The guide is fixed in `da9654d` (branch `claude/backend-followups-db6d1f`). §4.5 still said "only days nobody has taken".
- `DELETE /commutes/{commute}` does the following:
  - Every upcoming day is cancelled, and each confirmed seat becomes `CANCELLED_BY_DRIVER`.
  - Each passenger gets one **`commute_deleted_seat_cancelled`** push (`data.commuteId`, `data.bookingId`) and is **not** put on any waiting list.
  - Open requests and waiting-list places close as **`COMMUTE_ARCHIVED`**.
  - A run already under way is left alone.
- Driver fee by notice per confirmed seat: 16h+: 0 · 10–16h: 10% · under 10h: 25%. Preview it with `GET /commutes/{commute}/cancel-preview?action=delete`.
- Guide: §4.5 `DELETE /commutes/{commute}` and the §4.14 table.
- Tests: `DeleteCancelsBookingsTest` and `DriverFeesTest`.

**What the app must do**
- Driver: show the delete preview (affected seats and fee) before confirming.
- Passenger: route `commute_deleted_seat_cancelled`, and show `COMMUTE_ARCHIVED` on her requests.

**Open:** nothing.
