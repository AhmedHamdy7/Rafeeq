<!-- task: 123uve060wy · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend answer: HISTORY-10 (B-10) is done

**Branch:** `backend/history` · **Commit:** `d8d6549` · **New endpoint (183 total on main)** · **Merged to main in** `daf7141`

### What was built
**`POST /incidents/{incident}/appeal`** `{ "reason": "10–1000 chars" }` → `201` with the report, now **`status: "APPEALED"`**.

Rules (D-0021, enforced on the server):
- Only a **decided** report (`RESOLVED` / `CLOSED`).
- **48 hours from the decision** (`resolvedAt`). This uses the same setting as every other appeal.
- **One appeal, then a second only with new evidence, then final.** "New" is checked by the server: more files attached than when the first appeal was made. For the 2nd appeal, upload with `POST /incidents/{incident}/evidence` first. That endpoint now accepts files on a decided report while it can still be appealed.
- An appealed report goes back to the safety team's queue, **unassigned**, with a fresh deadline. When staff decide again it returns to `RESOLVED`/`CLOSED` with a new `resolution` and a new 48 hours.

Refusals are `422 VALIDATION_FAILED`, with `error.fields.appeal[0]` one of:
- `NOT_DECIDED`
- `NO_APPEALS_LEFT`
- `APPEAL_WINDOW_CLOSED`
- `NEW_EVIDENCE_REQUIRED`

`404` for somebody else's report.

**Every report** (POST, list, show) now carries:
```json
"appeal": { "count": 1, "appealableUntil": "2026-11-03T10:00:00+00:00", "needsNewEvidence": true }
```
- `appealableUntil`: show Appeal until then; `null` = not appealable now.
- `needsNewEvidence`: ask for a new photo before the 2nd appeal.

The admin dashboard shows "Appealed", the appeal round and the reason on the case.

Tests: `tests/Feature/Safety/TripReportsTest.php` (window, both rounds, new-evidence rule, final, 404, queue). Guide §4.12.

### What the app should change next
- Show Appeal while `appeal.appealableUntil != null`. If `needsNewEvidence`, route to the evidence upload first.
- Map `APPEALED` to "under review (appealed)".
- Map the four refusal codes to copy.

### Still open
- Who reviews an appeal: it goes back to the queue unassigned, so anyone (including the first reviewer) can take it. If ops want "never the same reviewer", that's an ops rule (**Omar**).
- The person a report is *about* never sees the report. They appeal the fee or warning that resulted, on those endpoints.
