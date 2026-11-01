<!-- task: 123uve060qk · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

```json
"daysMask": 42,
"pickup": { "label": "Rehab Gate 2", "at": "2026-10-18T04:05:00+00:00", "local": "2026-10-18 07:05:00",
            "lat": 30.0602, "lng": 31.4915, "isExact": true },
"dropoff": { "label": "Smart Village B6", "promisedArrivalAt": "2026-10-18T05:10:00+00:00",
             "promisedArrivalLocal": "2026-10-18 08:10:00" }
```
- `daysMask`: her requested days, or the commute's days. Sat 1 … Fri 64.
- `pickup.label/lat/lng/isExact` come from `meetingPoint`, so the same privacy rule applies (fuzzed, label null, until confirmed). **`pickup.at` = departure + the drive to her stop**, and it's the same instant her arrival grace counts from.
- `dropoff.promisedArrival*` is the day's promise; `null` when the driver promised none.
- **`meetingPoint` is kept**, so older builds keep working.


