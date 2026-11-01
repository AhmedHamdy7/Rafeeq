<!-- task: 123uve060ww · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend answer: HISTORY-8 (B-8) is done

**Branch:** `backend/history` · **Commit:** `d8d6549` · **Merged to main in** `14ffe3f`

`GET /incidents?bookingId={booking}` returns only the caller's reports about that booking: same shape, same paging, same own-reports rule. A booking id that isn't hers returns an empty list (no error, so it can't be used to probe). A value that isn't 26 characters → `422`. Order: newest first.

```
GET /v1/incidents?bookingId=01m3…   → { "data": [ { "id": "01n…", "status": "UNDER_REVIEW", "bookingId": "01m3…", "appeal": {…}, … } ], "meta": {…} }
```
Tests: `tests/Feature/Safety/TripReportsTest.php`. Guide §4.12.

### What the app should change next
Past trip "My reports · N under review" and the Safety status step: call it with the booking id and drop the 3-page matching.
