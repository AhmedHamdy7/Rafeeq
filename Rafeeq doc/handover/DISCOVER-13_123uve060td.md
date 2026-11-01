<!-- task: 123uve060td · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-13 Seat-request shape: points, counteroffer, commute summary

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (186 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

The full shape is now in guide §4.7 (including `COUNTEROFFERED`, `COUNTEROFFER_DECLINED`, `kind`, `pickup`, `dropoff`, `counteroffer`). New, **in the passenger's own view only** (`GET /seat-requests`, create, withdraw, counteroffer answers):

```json
"pickup":  { "label": "Fifth Settlement · 90th St", "lat": 30.02, "lng": 31.47, "source": "custom_map", "suggestionId": null,
             "estimatedAt": "…", "estimatedLocal": "2026-11-03 07:35:00" },
"dropoff": { "label": "Smart Village B6", "lat": 30.07, "lng": 31.02, "source": "driver_suggestion", "suggestionId": "01J…",
             "estimatedArrivalAt": "…", "estimatedArrivalLocal": "2026-11-03 08:25:00", "promisedArrivalLocal": "2026-11-03 08:30:00" },
"counteroffer": { "pickup": null, "dropoff": { "label": "Smart Village B6 gate", "lat": 30.07, "lng": 31.02 }, "note": "…", "at": "…", "answeredAt": null },
"commute": { "originLabel": "Fifth Settlement", "destinationLabel": "Smart Village B6", "tripId": "01J…", "tripDate": "2026-11-03",
             "departureLocal": "2026-11-03 07:30:00", "seatPricePiastres": 7500,
             "driver": { "publicFirstName": "Karim", "rating": 4.6, "ratingCount": 41 },
             "vehicle": { "make": "Hyundai", "model": "Elantra", "colour": "Silver", "tier": "standard", "hasAc": true } }
```

The day is the one she asked for, or (recurring) the commute's next day on her weekdays. The driver's inbox gets no summary, and `estimated*` is null there.

### Next: @ahmed Ibrahim (Flutter)
F3P-51 and B09 from these fields ("You asked … · Nour suggests …" = `pickup` / `dropoff` against `counteroffer`).
