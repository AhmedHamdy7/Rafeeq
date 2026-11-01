<!-- task: 123uve060gh · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: women-only commutes visible to everybody, plus `403 SEAT_REQUEST_WOMEN_ONLY` (C-11, BOOK-31)**

**What exists**
- Built in W4 (PR #20, `34a91d7`). Documented in `da9654d` (branch `claude/backend-followups-db6d1f`). The guide had never named the code.
- Search shows women-only commutes to everyone, flagged **`womenOnly: true`**. There is also a `womenOnly` filter on `GET /search/commutes`.
- A man who asks for a seat gets **`403 SEAT_REQUEST_WOMEN_ONLY`**. `POST /commutes/{commute}/pickup-preview` refuses him the same way.
- Guide: §2.1 (B. Passenger) and the §4.7 refusals.
- Tests: `SearchHardFiltersTest` ("shows a women-only commute to a man, flagged") and `SeatRequestFlowTest` ("refuses a man a seat on a women-only commute, with its own code").

**What the app must do**
- Show the women-only badge.
- Hide or disable "Request seat" for anyone who isn't a woman, and still handle the 403.

**Open:** nothing.

**Merged to main in `f9cc8cc`.**
