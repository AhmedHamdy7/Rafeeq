<!-- task: 123uve060gm · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: requests never expire (R-4, BOOK-36)**

**What exists**
- Built in W4 (PR #20, `34a91d7`). The guide is fixed in `da9654d` (branch `claude/backend-followups-db6d1f`). It still said "expires after 48 hours".
- The 48-hour expiry is gone everywhere, including waiting-list places and counteroffers. `expiresAt` is `null`.
- The only countdown left is a request on a car **already moving**: `expiresAt` 3 minutes out.
- A one-day request whose day has passed closes as **`CLOSED_DATE_PASSED`**.
- Guide §4.7: driver inbox, counteroffer table and seat-request shape, with the full status list.
- Tests: `SeatRequestFlowTest` ("never expires a request nobody has answered yet", "closes a one-day request once its day has passed").

**What the app must do**
- Remove every request timer or "expires in" label, except on the during-ride request sheet.
- Handle the new statuses: `CLOSED_DATE_PASSED`, `COMMUTE_ARCHIVED`, `COUNTEROFFERED`, `COUNTEROFFER_DECLINED`, `TERMS_CHANGED`.

**Open:** nothing.

**Merged to main in `f9cc8cc`.**
