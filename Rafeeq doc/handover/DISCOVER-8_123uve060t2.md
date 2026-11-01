<!-- task: 123uve060t2 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-8 Recent searches: place labels + delete one

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

- Send optional `origin[label]`, `origin[placeId]`, `destination[label]`, `destination[placeId]` with `GET /search/commutes`. They change nothing in the search and are stored on the recent search. Without a label, the nearest catalogue place within 500 m names the end, else it stays `null`.
- New: **`DELETE /me/recent-searches/{search}`** → `{ "deleted": true }`; somebody else's id → `404`. Clear All (`DELETE /me/recent-searches`) is unchanged.

```json
{ "id": "01J…",
  "criteria": { "origin": { "lat": 30.0624, "lng": 31.3614, "label": "New Cairo", "placeId": "01J…" },
                "destination": { "lat": 30.0718, "lng": 31.0177, "label": "Smart Village", "placeId": null },
                "daysMask": 2, "arrivalWindowStart": "07:30:00", "arrivalWindowEnd": "08:30:00" },
  "searchedAt": "…", "firstSearchedAt": "…" }
```

### Next: @ahmed Ibrahim (Flutter)
1. When she picks from `GET /places`, send the place's `name` and `id` as `label` / `placeId`.
2. Row "New Cairo → Smart Village" from `criteria.origin.label` → `criteria.destination.label`; a tap still sends `criteria` back unchanged.
3. Swipe to delete → `DELETE /me/recent-searches/{id}`.
