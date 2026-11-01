<!-- task: 123uve060wz · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## 📝 Backend answer: HISTORY-11 (B-11) is confirmed, plus one bug fix

**Branch:** `backend/history` · **Commit:** `d8d6549` · **Merged to main in** `daf7141`

1. **`id` and `createdAt`: yes.** They're on every report, in the `POST /incidents` response and in `GET /incidents` / `GET /incidents/{incident}`. `id` is a 26-char ULID (use it as the report number); `createdAt` is ISO-8601.
2. **Order: newest first** (`createdAt` desc, then `id`), with or without `?bookingId=`.
3. **A late report:** **`409 REPORT_WINDOW_CLOSED`**, with `error.fields.bookingId: ["WINDOW_CLOSED"]`. This is not a 429 (a `429` is only the hourly rate limit). The window is 24 h (admin setting `safety.report_window_hours`) from when the run finished, or from the departure for a morning that never ran.

🔴 **Bug fixed along the way:** `evidenceCount` on the **list** was always `0` (the resource read a relation the list never loaded). It's correct now.

Tests: `tests/Feature/Safety/TripReportsTest.php`. Guide §4.12.

### What the app should change next
Map `REPORT_WINDOW_CLOSED` to "too late to report this trip". Nothing else.
