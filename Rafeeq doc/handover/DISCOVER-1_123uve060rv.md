<!-- task: 123uve060rv · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-1 Commute detail a passenger can read

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (186 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

New: **`GET /commutes/{commute}/public`** (active, profile complete; no verification). Same eligibility as search; not published or not hers to see → `404`. Guide §4.6.

```json
{
  "commuteId": "01J…", "status": "PUBLISHED", "audience": "women_only", "womenOnly": true,
  "seatPricePiastres": 8500, "allowsCustomPickup": true,
  "maxPointDistanceMetres": 1500, "maxDetourMinutes": 10, "maxDetourKm": 5.0,
  "schedule": { "daysMask": 42, "departureTime": "07:00:00", "timezone": "Africa/Cairo", "startDate": "…", "endDate": "…" },
  "route": { "start": { "label": "Nasr City", "lat": 30.07, "lng": 31.34 }, "end": { "label": "Smart Village", "lat": 30.07, "lng": 31.02 },
             "distanceKm": 27.0, "durationMinutes": 52, "polyline": "…", "isTrimmed": true },
  "pickups":  [{ "id": "01J…", "label": "Rehab Gate 2", "lat": 30.06, "lng": 31.49, "estimatedTime": "07:05:00" }],
  "dropoffs": [{ "id": "01J…", "label": "Smart Village B6 gate", "lat": 30.07, "lng": 31.02, "estimatedTime": "08:10:00" }],
  "upcomingTrips": [{ "id": "01J…", "tripDate": "2026-11-03", "departureLocal": "2026-11-03 07:00:00", "promisedArrivalLocal": "2026-11-03 08:10:00",
                      "seatsAvailable": 1, "seatPricePiastres": 8500, "myStatus": { "kind": "none", "seatRequestId": null, "bookingId": null }, "overlapsBooking": null }],
  "rules": { "nonsmoking": true }, "vehicle": { "make": "…", "model": "…", "colour": "…", "tier": "standard", "hasAc": true },
  "driver": { "publicFirstName": "Nour", "rating": 4.9, "ratingCount": 210, "completedTrips": 120 }
}
```

- **Route (Q2 option D):** trimmed about 1 km at each end, ends named by area only, until she holds a **confirmed** booking on this commute. After that: the full line and `isTrimmed: false`.
- `pickups[].id` / `dropoffs[].id` is the `suggestionId` the seat request takes. Empty arrays = B07.

### Next: @ahmed Ibrahim (Flutter)
1. Open the detail (B01 → B07, F3P-21 → 23) and the request screen (B02, F11) from this call, not `GET /commutes/{commute}` (that one stays driver-only).
2. Draw `route.polyline` with Start/End from `route.start.label` / `route.end.label`; "27 km · about 52 min" from `distanceKm` / `durationMinutes`.
3. F11 "Seats now" from `upcomingTrips[]`; "Nour drives Sun · Tue · Thu only" from `schedule.daysMask`.
