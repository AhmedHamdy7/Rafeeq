<!-- task: 123uve060wt · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend answer: HISTORY-5 (B-5) is done, and it fixed a real bug

**Branch:** `backend/history` · **Commit:** `335eba4` · **Merged to main in** `14ffe3f`

### What was built
`PATCH /ratings/{rating}` now has its own request, and **a field left out is unchanged**:

| Body | Result |
|---|---|
| `"comment": null` (or `""`) | Comment cleared (stored `null`) |
| `comment` left out | Unchanged |
| `"comment": "text"` | Replaced (≤ 1000) |
| `"tags": []` (or `null`) | Tags cleared; left out = unchanged |
| `stars` left out | Unchanged |

🔴 **The bug:** PATCH shared the submit request, so `stars` was **required** on every edit, and a **left-out comment was wiped** (and a left-out `tags` cleared). If the app ever edited only the stars, the comment was being deleted.

On `canceled_booking` feedback a comment or tags are still refused (`422 VALIDATION_FAILED`); clearing is fine.

```
PATCH /v1/ratings/01r7…  { "comment": null }
→ 200 { "data": { "stars": 5, "comment": null, … } }
```
Tests: `tests/Feature/Rating/HistoryRatingRulesTest.php`. Guide §4.13.

### What the app should change next
- When she empties the comment box on edit, send `"comment": null`.
- You may now send only the fields that changed.

### Still open
Nothing.
