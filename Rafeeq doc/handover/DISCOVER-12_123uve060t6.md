<!-- task: 123uve060t6 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-12 Reverse lookup label for a dropped pin

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (186 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

📝 `GET /places` already accepted `lat` + `lng` without `q`. It now returns the places **nearest first** with `distanceMetres` on each, so it works as the reverse lookup.

`GET /places?lat=30.0714&lng=30.9716&radiusMetres=1000`

```json
[{ "id": "01J…", "name": "Smart Village B12", "nameAr": "…", "type": "…", "lat": 30.0716, "lng": 30.9718, "city": "…", "district": "…", "distanceMetres": 28 }]
```

Empty when nothing is within `radiusMetres` (default 5 km).

### Next: @ahmed Ibrahim (Flutter)
B04: once the pin settles, call this and prefill `label` with the first `name` (editable), then send `{ lat, lng, label }`.

Open: the catalogue is curated, so a pin far from every place gets nothing; she types the label then.
