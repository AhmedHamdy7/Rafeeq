<!-- task: 123uve060wp · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend answer: HISTORY-1 (B-1) is done

**Branch:** `backend/history` · **Commit:** `272ee11` · **Merged to main in** `14ffe3f`

### What was built
Every booking on **`GET /my-bookings`** and **`GET /bookings/{booking}`** now carries:

| Field | Type | Notes |
|---|---|---|
| `commuteId` | string | For "Ride again" and `GET /commutes/{commute}/reviews` |
| `originLabel` | string\|null | Same value as `nextJourney.originLabel` (e.g. `Rehab Gate 2`) |
| `destinationLabel` | string\|null | e.g. `Smart Village B6` |
| `driver` | `person` (§5.1) \| null | `publicFirstName`, `trustLevel`, `verifiedLevels`, `sameOrganisation`, `rating`, `completedTrips`, `onTimeRate`. 🔒 No full name, phone or user id. `null` only if the driver's account is gone |

**Pickup / drop-off: use the fields that already exist**, `meetingPoint` and `dropoffPoint`: `{lat, lng, label, isExact}` or `null` (`null` = the commute's origin / destination). No `pickup`/`dropoff` aliases, so there's one name per fact.
🔒 Exact only on `CONFIRMED`, `COMPLETED`, `ENDED_EARLY`, `EXITED_DUE_TO_DRIVER_DELAY`. On `PENDING`, `EXPIRED`, `NO_SHOW` and **both cancelled statuses** they're fuzzed (~110 m, `label: null`, `isExact: false`). That's the existing privacy rule, so a cancelled booking does **not** keep the exact point it had while confirmed.

### Example (one item of `GET /my-bookings`)
```json
{
  "id": "01m3…", "tripId": "01m2…", "commuteId": "01k9…",
  "originLabel": "Rehab Gate 2", "destinationLabel": "Smart Village B6",
  "driver": { "publicFirstName": "Karim", "trustLevel": 3, "verifiedLevels": ["phone","government_id"], "sameOrganisation": false, "rating": 4.8, "completedTrips": 112, "onTimeRate": 0.96 },
  "status": "CANCELLED_BY_DRIVER",
  "meetingPoint": { "lat": 30.062, "lng": 31.361, "label": null, "isExact": false },
  "dropoffPoint": { "lat": 30.068, "lng": 31.102, "label": null, "isExact": false },
  "trip": { "tripDate": "2026-10-14", "departureLocal": "2026-10-14 07:05:00", "status": "CANCELLED" }
}
```
Tests: `tests/Feature/Booking/BookingHistoryFieldsTest.php` (fields on both endpoints, driver is first-name only, pending or passenger-cancelled shows the fuzzed point). Guide §4.7 (booking shape, `/my-bookings`, `/bookings/{booking}`).

### What the app should change next
- History card title = `originLabel → destinationLabel`; the driver's line and status notes use `driver.publicFirstName` ("Canceled by Karim").
- Past trip: route, driver and pickup (`meetingPoint`) from `GET /bookings/{booking}`, one call.
- Show "approximate" when `isExact` is false.

### Still open
Nothing. Block still uses `otherPartyUserId` (X-4), which appears only once the journey is over.
