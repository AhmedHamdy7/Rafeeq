<!-- task: 123uve060t4 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-10 Match count per demand

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `8bf31f5`. Guide 2026-11-01 (186 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

`matchCount` on every demand (`GET` / `POST` / `PATCH /commute-demands`):

```json
"matchCount": 3
```

It counts the **different commutes** matched to this demand that are **still published and run in the next 7 days** ("this week"). One query for the whole list.

### Next: @ahmed Ibrahim (Flutter)
"3 matching commutes this week" and "Suggestions · 3 this week" from `matchCount`.

Open (product, Omar): "this week" means the next 7 days, not the calendar week. Say if Sat → Fri is wanted instead.
