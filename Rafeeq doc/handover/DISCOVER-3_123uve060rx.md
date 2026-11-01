<!-- task: 123uve060rx · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-3 Weekdays + local time on other days

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

- `daysMask` on every result = the days the commute **runs** (bitmask §1.8), not `tripDate` + `otherDays`.
- Each `otherDays[]` item now has `departureLocal` (plus `promisedArrivalAt/Local`, `myStatus`, `overlapsBooking`).

```json
"daysMask": 42,
"otherDays": [{ "id": "01J…", "tripDate": "2026-11-05", "departureAt": "…", "departureLocal": "2026-11-05 07:15:00", "seatsAvailable": 2 }]
```

### Next: @ahmed Ibrahim (Flutter)
First card line "Sun · Tue · Thu" from `daysMask`; date chips "Tue 20 · 07:15" from `otherDays[].departureLocal` (display only).
