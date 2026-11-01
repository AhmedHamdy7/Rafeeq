<!-- task: 123uve060rw · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-2 Promised arrival + drop-off label on results

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

On every `GET /search/commutes` result (and `/matches` rows):

```json
"promisedArrivalAt": "2026-11-03T06:10:00+00:00",
"promisedArrivalLocal": "2026-11-03 08:10:00",
"originLabel": "Rehab Gate 2", "destinationLabel": "Smart Village B6",
"dropOffPoint": { "lat": 30.07, "lng": 31.02, "address": "Smart Village B6", "walkMinutes": 3,
                  "estimatedArrivalAt": "…", "estimatedArrivalLocal": "2026-11-03 08:05:00" }
```

- `promisedArrival*` is the driver's **promise at the end of his route** that day; `null` when none was promised.
- `dropOffPoint` is the commute's drop-off point or destination nearest her searched destination, shaped like `meetingPoint`. `estimatedArrival*` is an estimate along the route.

### Next: @ahmed Ibrahim (Flutter)
Card: "07:00 → arrive 08:10" from `departureLocal` → `promisedArrivalLocal`; route line `meetingPoint.address` → `dropOffPoint.address`. The detail's "Promised arrival" uses the same field.
