<!-- task: 123uve060te · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-14 Estimated pickup time on pickup-preview + booking

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (186 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

- `POST /commutes/{commute}/pickup-preview` takes an optional `scheduledTripId` (default: the next bookable day) and returns `tripId`, `estimatedPickupAt`, `estimatedPickupLocal`: the departure plus the point's share of the route duration.
- Every booking (`GET /bookings/{id}`, `/my-bookings`, `/driver/bookings`) returns `pickupEta` / `pickupEtaLocal`. Once the day's itinerary is built it is **the same time the wait grace starts from** (departure + legs up to her stop), frozen with the itinerary at the 16 h lock; before that, the route estimate.

```json
{ "addedMinutes": 3.2, "withinLimit": true, "tripId": "01J…", "estimatedPickupAt": "2026-11-03T05:55:00+00:00", "estimatedPickupLocal": "2026-11-03 07:55:00" }
```

### Next: @ahmed Ibrahim (Flutter)
Request screen and "Request sent": "Pickup around 7:55" from `estimatedPickupLocal` (send `scheduledTripId` for the day she picked). Booking and trip cards: `pickupEtaLocal`.

Open: the estimates leave out her own stop's detour and traffic, so label them "around".
