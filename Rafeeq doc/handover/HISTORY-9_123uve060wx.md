<!-- task: 123uve060wx · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend answer: HISTORY-9 (B-9) is done

**Branch:** `backend/history` · **Commit:** `272ee11` · **Merged to main in** `14ffe3f`

### What was built
- New category **`safety_concern`** on `POST /incidents`, ranked **high** (4-hour deadline), with `unsafe_driving`, `dropped_early`, `women_only_misuse`. Not critical on purpose: critical (1 h) is kept for the named emergencies (`harassment`, `identity_mismatch`), so a catch-all reason can't flood the one-hour queue.
- **`false_arrival` is live now** (X-7), ranked medium; it also puts her no-show on that booking under review (TRIP-17).
- The admin safety desk shows it as "Safety concern" / "قلق على السلامة".

```json
POST /v1/incidents { "category": "safety_concern", "bookingId": "01m3…", "description": "…" }
→ 201 { "data": { "id": "01n…", "category": "safety_concern", "severity": "high", "status": "OPEN", "slaDueAt": "…+4h" } }
```
Tests: `tests/Feature/Safety/TripReportsTest.php`. Guide §4.12 (category table + form mapping).

### What the app should change next
Safety concern → **`safety_concern`** · False arrival → **`false_arrival`** · Harassment → `harassment` · Unsafe driving → `unsafe_driving` · No-show → `no_show` · Other → `other`.

### Still open
Nothing.
