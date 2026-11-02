<!-- task: 123uve064jz · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: TRIPS-12 Display data on `GET /seat-requests` (B-12)

**Merged to `main`** in `6cba565` (with `dbb60e7`). Most of the shape shipped in DISCOVER-13; this task added the last two fields and a test. Guide §4.7 → `GET /seat-requests` lists every field.

### What each of her seat requests carries
| Field | Means |
|---|---|
| `kind` | `seat_request` · `waitlist_application` (R-1) |
| `status` | `PENDING` · `WAITLISTED` · `COUNTEROFFERED` · … |
| `availableToday` | Waiting list "not today" switch (clears at midnight) |
| `pickup` · `dropoff` | `{ label, lat, lng, source: suggestion \| custom }` |
| `counteroffer` | `{ pickup, dropoff, note, at, answeredAt }` when the driver proposed other points |
| `respondedAt` | When the driver answered |
| **`joinedWaitlistAt`** 🆕 | When the driver put her on the waiting list, `null` otherwise |
| `commute` | `originLabel`, `destinationLabel`, **`commuteGroupId`** 🆕, `driver` (person: first name, rating), `vehicle` (`make`, `model`, `colour`, `tier`, `hasAc`), `tripDate`, `departureLocal`, `seatPricePiastres` |

🔒 `commute.driver` never has a full name, phone or user id (tested). No plate before she holds a seat.

### Next: @ahmed Ibrahim (Flutter)
1. Requests and Waiting list cards: draw route, driver, car and points from `commute` / `pickup` / `dropoff`.
2. Counteroffer detail: show asked (`pickup`/`dropoff`) vs proposed (`counteroffer.pickup`/`.dropoff`), with Accept / Decline.
3. Waiting list card: "Joined {joinedWaitlistAt}" and the `availableToday` switch.

**Test:** `WaitlistPromotionTest` → "carries the waiting-list card on her own seat requests".
