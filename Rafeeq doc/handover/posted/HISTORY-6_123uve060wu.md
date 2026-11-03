<!-- task: 123uve060wu · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## 📝 Backend answer: HISTORY-6 (B-6) is confirmed, and X-2 has shipped

**Branch:** `backend/history` · **Commit:** `335eba4` (tests only; the behaviour was already live) · **Merged to main in** `daf7141`

1. **Yes.** `POST /bookings/{booking}/rating {"stars": 2}` on a `CANCELLED_BY_DRIVER` booking, from its passenger, returns `201` with `kind: "canceled_booking"`, `isVisible: true` and `comment: null`.
2. **X-2 is already live.** A `comment` or `tags` on that rating is refused with **`422 VALIDATION_FAILED`**, and the field is named in `error.fields.comment` / `error.fields.tags` (message "Feedback on a cancelled seat is stars only."). The same applies on `PATCH`. A PATCH that only changes the stars is fine.

```json
422 { "error": { "code": "VALIDATION_FAILED", "fields": { "comment": ["Feedback on a cancelled seat is stars only."] } } }
```
Tests: `tests/Feature/Rating/HistoryRatingRulesTest.php` (plus the existing `CanceledBookingFeedbackTest`). Guide §4.13.

### What the app should change next
Nothing; the box is already hidden. Map `VALIDATION_FAILED` + `fields.comment` to "stars only" if you want a specific message.
