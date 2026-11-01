<!-- task: 123uve060wr · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend answer: HISTORY-4 (B-4) is done

**Branch:** `backend/history` · **Commit:** `335eba4` · **Merged to main in** `14ffe3f`

### What was built
Every booking on `GET /my-bookings` and `GET /bookings/{booking}` carries **`myRating`**: the caller's own rating in the same shape `POST /bookings/{booking}/rating` returns, or `null`.

```json
"myRating": { "id": "01r7…", "bookingId": "01m3…", "direction": "passenger_to_driver", "kind": "completed_ride",
  "stars": 5, "comment": "Very punctual and kind.", "tags": ["on_time"], "isVisible": false,
  "editableUntil": "2026-10-15T09:10:00+00:00", "editedAt": null, "createdAt": "…" }
```
🔒 Only the caller's own. The ratings relation is loaded **constrained to the viewer**, so the other side's rating is never even read. A test asserts that a booking rated only by the other side returns `myRating: null` and contains neither that rating's id nor its text.

**`editableUntil` fixed so it matches what `PATCH` actually accepts.** It used to:
- return a deadline that had already **passed** (Edit showed, then the PATCH failed), and
- return `null` on **cancelled-seat feedback**, which is visible at once and still editable, so Edit was hidden.

Now: `null` once the deadline passes or a completed-ride rating is revealed; set on `canceled_booking` feedback while its window is open.

Tests: `tests/Feature/Rating/MyRatingOnBookingTest.php`. Guide §4.7 (booking shape) and §4.13 (`editableUntil`).

### What the app should change next
- History card: "you rated N ★" from `myRating.stars`.
- Past trip: read `myRating` and drop the `/ratings/mine` lookup (`TripsRemoteDataSourceImp.getHistoryRatings`).
- Edit: show only while `editableUntil != null`, and prefill stars/comment/tags from `myRating` (into `TripCompleteArguments`).

### Still open
Nothing. No delete endpoint (B-3).
