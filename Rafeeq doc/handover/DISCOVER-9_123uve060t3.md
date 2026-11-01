<!-- task: 123uve060t3 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-9 Demands: place labels + preferences

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

`POST` / `PATCH /commute-demands` now take and return each end's `label` + `placeId`, plus `womenOnly`, `rules[]` and `tier` (the search's own fields). **The alerts a demand sends now honour them**; before, they were ignored. An end without a label gets the nearest catalogue place within 500 m.

```json
"origin": { "lat": 30.0624, "lng": 31.3614, "label": "New Cairo", "placeId": "01J…" },
"womenOnly": true, "rules": ["nonsmoking", "quiet"], "tier": null
```

`rules` takes the same keys as the search. A/C is not a rule key; use `tier` / `vehicle.hasAc` on the results.

### Next: @ahmed Ibrahim (Flutter)
F3P-36/37: send the preferences, review "Preferences · Non-smoking". F3P-38/44 and Home's "Matches 'New Cairo → Smart Village'" read the labels.
