# Discover — backend handoff for Flutter

**Date:** 2026-11-03 · **Backend:** `main` (PRs #26 → #32 merged) · **Answers:** `docs/features/discover/discover_backend_gaps.md` (2026-11-01), ClickUp DISCOVER-1 → 15
**Full contract:** `Rafeeq doc/RAFEEQ_MOBILE_API_GUIDE.md` §4.6 – 4.7 · **Real responses:** `Rafeeq doc/COMMUTE_DETAIL_RESPONSE_SHAPES.md`, `Rafeeq doc/HOME_API_RESPONSE_SHAPES.md`

Every gap in the 2026-11-01 file is done on the backend. This file is what the app needs to build Discover from search to sending a seat request, plus demands and Home suggestions.

General rules: responses are `{"success": true, "data": …}`; `…At` is UTC (use it for maths), `…Local` is Cairo's clock (show it as is); money is in piastres; send `Accept-Language: ar | en` on every call.

---

## 1. What changed since the 2026-11-01 file

| Gap | Now |
|---|---|
| G1, G2 | `promisedArrivalAt/Local` and `dropOffPoint` on every result |
| G3 | Recent searches keep `label` / `placeId` per end; `DELETE /me/recent-searches/{id}` |
| G4, G14 | `daysMask` on every result; `otherDays[].departureLocal` |
| G5 | `person.ratingCount` |
| G8 | `vehicle.tier`, `vehicle.hasAc`, `rules` on every result |
| G9, G13 | `overlapsBooking` and `myStatus` on every result and every day |
| G10, G11, G20 | New `GET /commutes/{commute}/public` |
| G15 → G17 | Demands take preferences and labels and return `matchCount` |
| G18 | `/matches` rows carry the full search-result shape |
| G19 | Home's `demandAlert.newMatchesCount`, lowered by `POST /matches/{id}/opened` |
| G21 | `GET /places?lat=&lng=` is the reverse lookup |
| G22 | Seat-request shape documented, with points, counteroffer and commute summary |
| G23 | A **recurring** request on full days becomes a waiting-list application |
| B-3 | `estimatedPickupAt/Local` on `pickup-preview`; `pickupEta/Local` on bookings |
| B-5 | `seatPricePiastres` everywhere (`pricePerSeatPiastres` is a deprecated alias) |
| B-6 | Women-only commutes are shown to everyone; `requestEligibility` says who may request |
| B-7 | The 21:00 booking deadline is gone |

**New since then (not in your file):**
- `requestEligibility` and per-day `pointTimes` on the commute detail.
- `rules` is **always an object** (`{}` when empty) on detail, search, `/matches`, `/home`.
- New rule key **`music`** ("Music on").
- Area names (`route.start/end.label`, demand labels) and common car colours follow `Accept-Language`.
- The time window is matched against **her pickup time**, widened by `flexibilityMinutes`. `HH:mm` is accepted; `maxWalkMinutes` / `maxDetourMinutes` are optional (10 / 15).
- `meetingPreference` is optional on a seat request.
- `user.gender` and `user.canChooseWomenOnly` on `/auth/me`.
- Demands: `GET /commute-demands/{id}`, duplicates → `409`, same From/To → `422`.

---

## 2. Screens → calls

| Screen | Call | Read |
|---|---|---|
| From / To fields (F3P-12) | `GET /places?q=` | `id`, `name`, `nameAr`, `lat`, `lng`. No `q` → the 20 most used places. `q` matches part of a word, any case, max 20 |
| Dropped pin label (B04) | `GET /places?lat=&lng=&radiusMetres=1000` | first `name`, nearest first, with `distanceMetres`; empty → she types it |
| Results (F3P-12) | `GET /search/commutes` | §3 |
| Recent searches (F3P-13, Home) | `GET /me/recent-searches` · `DELETE /me/recent-searches/{id}` · `DELETE /me/recent-searches` | `criteria.origin.label` → `criteria.destination.label`, `searchedAt` |
| Comfort chips remembered | `GET` / `PATCH /account/comfort-preferences` | `rules[]` |
| Commute detail (B01 → B07, F3P-21 → 23) | `GET /commutes/{commute}/public` | §4 |
| Pickup time on a custom point | `POST /commutes/{commute}/pickup-preview` with `scheduledTripId` | `withinLimit`, `estimatedPickupLocal` |
| Send the request (B02 → B08, F10 → F12) | `POST /commutes/{commute}/seat-requests` | §5 |
| Pending request / counteroffer (F3P-51, B09) | `GET /seat-requests` | §5 |
| Demands (F3P-30, F3P-36 → 40, F3P-44) | `/commute-demands` | §6 |
| Home suggestions (F3P-01, F3P-02) | `GET /home` → `topMatches`, `demandAlert`; `GET /matches` | §7 |

---

## 3. Search — `GET /search/commutes`

**Query:** `origin[lat,lng]`, `destination[lat,lng]`, `daysMask` (Sun–Thu = 62), `arrivalWindowStart`, `arrivalWindowEnd` (`HH:mm` or `HH:mm:ss`), `flexibilityMinutes` (0–60, default 15), optional `maxWalkMinutes` / `maxDetourMinutes`, `seatsNeeded`, `womenOnly`, `rules[]` (up to 7), `tier`. Send `origin[label]`, `origin[placeId]`, `destination[label]`, `destination[placeId]` too: they don't change the search, they name the recent search.

**The window is her pickup time.** A single time works: `08:00`–`08:00` with `flexibilityMinutes: 30` matches exactly like `07:30`–`08:30`.

**Card fields:**

| Card | Field |
|---|---|
| `Sun · Tue · Thu` | `daysMask` |
| `07:00 → arrive 08:10` | `departureLocal` → `promisedArrivalLocal`. `null` = the driver set no promised arrival: show `dropOffPoint.estimatedArrivalLocal` as "~08:44 (approx.)" |
| `Rehab Gate 2 → Smart Village B6` | `meetingPoint.address` → `dropOffPoint.address` (in the request's language for catalogue places; driver-typed points as typed) |
| Pickup around 07:05 | `meetingPoint.estimatedLocal` |
| `EGP 85` | `seatPricePiastres` |
| `Nour · 4.9 ★ (210)` | `driver.publicFirstName`, `driver.rating`, `driver.ratingCount` (hide the count when `rating` is null) |
| `1 seat left` / `Full · waiting list` | `seatsAvailable` |
| `♀ Women-only` | `womenOnly` |
| Comfort tags, split results | `rules` (object), `vehicle.tier`, `vehicle.hasAc` |
| Date chips `Tue 20 · 07:15` | `otherDays[].departureLocal` |
| Overlap note | `overlapsBooking` (per result and per day) |
| Action button | `myStatus.kind` (per result and per day) |

**Splitting results (Q5):** "Standard car" when `vehicle.tier != comfort`, "No AC" when `!vehicle.hasAc`, "Smoking allowed" when `rules.nonsmoking != true`.

---

## 4. Commute detail — `GET /commutes/{commute}/public`

```json
{
  "commuteId": "01J…", "status": "PUBLISHED", "audience": "women_only", "womenOnly": true,
  "seatPricePiastres": 8500, "allowsCustomPickup": true,
  "maxPointDistanceMetres": 1500, "maxDetourMinutes": 10, "maxDetourKm": 5.0,
  "schedule": { "daysMask": 42, "departureTime": "07:00:00", "timezone": "Africa/Cairo", "startDate": "…", "endDate": "…" },
  "route": { "start": { "label": "New Cairo", "lat": 30.06, "lng": 31.48 }, "end": { "label": "6th of October", "lat": 30.07, "lng": 30.98 },
             "distanceKm": 27.0, "durationMinutes": 52, "polyline": "…", "isTrimmed": true },
  "pickups":  [{ "id": "01J…", "label": "Rehab Mall 2 gate", "lat": 30.059, "lng": 31.47, "estimatedTime": "07:05:00" }],
  "dropoffs": [{ "id": "01J…", "label": "Smart Village main gate", "lat": 30.071, "lng": 31.03, "estimatedTime": "08:10:00" }],
  "upcomingTrips": [{
    "id": "01J…", "tripDate": "2026-11-04", "departureAt": "…", "departureLocal": "2026-11-04 07:00:00",
    "promisedArrivalAt": null, "promisedArrivalLocal": null, "seatsAvailable": 1, "seatPricePiastres": 8500,
    "myStatus": { "kind": "none", "seatRequestId": null, "bookingId": null },
    "overlapsBooking": null,
    "pointTimes": [{ "pointId": "01J…", "estimatedAt": "…", "estimatedLocal": "2026-11-04 07:05:00" }]
  }],
  "rules": { "nonsmoking": true, "ac": true },
  "requestEligibility": { "canRequest": true, "reason": null },
  "vehicle": { "make": "Kia", "model": "Cerato", "colour": "Red", "tier": "standard", "hasAc": true },
  "driver": { "publicFirstName": "Nour", "rating": 4.9, "ratingCount": 210, "completedTrips": 120 }
}
```

- **Route:** trimmed about 1 km at each end with area names only, until she has a confirmed seat; then the full line and `isTrimmed: false`.
- **Suggested points:** `pickups[].id` / `dropoffs[].id` is the `suggestionId` for the request. Empty arrays → B07 (custom pin only, if `allowsCustomPickup`).
- **Point times:** `estimatedTime` is the usual time; once she picks a day, use that day's `pointTimes`.
- **Who may request:** when `requestEligibility.canRequest` is `false`, disable **Request Seat** and show the reason:

| `reason` | Show |
|---|---|
| `women_only` | Women-only commute |
| `gender_not_set` | Ask Woman / Man, save with `PUT /account/profile/basic`, then retry |
| `own_commute` | It's your own commute |
| `already_requested` | You already asked (open the request) |
| `account_restricted` | Riding is suspended (unpaid balance) |

- **Action button per day:**

| Day state | Button |
|---|---|
| `myStatus.kind = none`, seats left, no `overlapsBooking` | Request Seat |
| `none` and full, or `overlapsBooking` set | Request to Join Waiting List (F3P-21 / F3P-23) |
| `pending_request` | Requested · Withdraw (`DELETE /seat-requests/{seatRequestId}`) |
| `counteroffered` | Open the counteroffer (B09) |
| `waitlisted` | On the waiting list |
| `booked` | Open the booking |

---

## 5. Seat request — `POST /commutes/{commute}/seat-requests`

```json
{
  "commitment": "trial",
  "scheduledTripId": "01J…",
  "seats": 1,
  "pickup":  { "suggestionId": "01J…" },
  "dropoff": { "lat": 30.0714, "lng": 30.9716, "label": "Smart Village B12" },
  "introMessage": "Hello",
  "paymentType": "cash",
  "agreedToRules": true
}
```

- `commitment`: `trial` (one day, `scheduledTripId` required) or `recurring` (`requestedDaysMask` required, `scheduledTripId` = first day).
- Each point is `{ suggestionId }` or `{ lat, lng, label }`. Check a custom point with `pickup-preview` first.
- `meetingPreference` is optional now; leave it out.
- **`201`**: `data.kind` = `seat_request` (seat free) or `waitlist_application` (full day, overlap, or recurring on full days) → pick the confirmation screen.
- Refusals: `403 SEAT_REQUEST_WOMEN_ONLY` (with `fields.reason: ["GENDER_NOT_SET"]` for an old account without gender), `409 BOOKING_ALREADY_REQUESTED`, `422` point errors (`SEAT_REQUEST_POINTS_SAME`, `SEAT_REQUEST_POINTS_OUT_OF_ORDER`, `SEAT_REQUEST_SUGGESTION_UNKNOWN`, `PICKUP_DETOUR_TOO_LONG`).

**Reading it back (`GET /seat-requests`, F3P-51 / B09):** `status` (incl. `COUNTEROFFERED`, `COUNTEROFFER_DECLINED`), `kind`, `pickup` / `dropoff` with `label`, `source`, `estimatedLocal` / `estimatedArrivalLocal` / `promisedArrivalLocal`, `counteroffer {pickup, dropoff, note, at, answeredAt}`, and `commute {originLabel, destinationLabel, tripId, tripDate, departureLocal, seatPricePiastres, driver, vehicle}`. Answer a counteroffer with `POST /seat-requests/{id}/counteroffer/accept` or `/decline`; withdraw with `DELETE /seat-requests/{id}`.

---

## 6. Demands — `/commute-demands`

**Body (POST and full PATCH):**

```json
{
  "origin":      { "lat": 30.0602, "lng": 31.4915, "label": "Rehab Gate 2",     "placeId": "01J…" },
  "destination": { "lat": 30.0724, "lng": 31.0116, "label": "Smart Village B6", "placeId": "01J…" },
  "daysMask": 62,
  "arrivalWindowStart": "08:00",
  "arrivalWindowEnd": "08:00",
  "flexibilityMinutes": 30,
  "womenOnly": true,
  "rules": ["nonsmoking", "ac"]
}
```

- The window is her **pickup** time ("Pickup time" chips 07:00 · 07:30 · 08:00 · 08:30). Card: `08:00 · ±30 min`; with `flexibilityMinutes: 0` just `08:00`.
- **Women-only chip:** show it only when `user.canChooseWomenOnly` is `true` (from `/auth/me`). Send `womenOnly: true` only. Read back: chip on when `womenOnly` is `true`.
- Walk/detour limits: leave them out (10 / 15).

| Action | Call | Notes |
|---|---|---|
| Save (F3P-37 → F3P-30) | `POST /commute-demands` | `201`, `matchCount` already right |
| List (Trips → Demands) | `GET /commute-demands` | `ACTIVE`, `PAUSED`, `EXPIRED`; never deleted ones |
| One (push, Home alert, after edit) | `GET /commute-demands/{id}` | `404` if not hers or deleted |
| Pause / resume (F3P-38 / 39) | `PATCH {paused: true / false}` | resume gives a fresh `expiresAt` |
| Edit (F3P-40) | `PATCH` with the whole body | fresh `expiresAt`; an `EXPIRED` demand comes back as `ACTIVE` |
| Delete | `DELETE /commute-demands/{id}` | `200`, gone from the list |

- `matchCount` = different commutes matched in the **next 7 days** ("this week").
- `origin/destination.label` follows `Accept-Language` when `placeId` is set.
- Errors: `409 DEMAND_EXISTS` with `fields.demandId` → "You already have this demand", open it. `422` on `destination` for the same From and To; `422` on `womenOnly` if she can't choose it.

---

## 7. Home suggestions — `GET /home`, `GET /matches`

- `topMatches[]` (≤ 3) and `GET /matches` rows have the full search-result shape plus `demandId`, `demandTitle` (in the request's language; `null` → "Matches your demand"), `driver.completedTrips` (`0` → "New driver").
- ⚠️ `score` here is the **integer** total stored when the match was found (`64`); on search it is an object (`score.total`).
- `demandAlert {demandId, newMatchesCount, originLabel, destinationLabel, daysMask, arrivalTimeLocal, arrivalWindowEndLocal}` → "N new commutes match your demand"; `null` → no banner.
- **Tap a match → `POST /matches/{id}/opened`**, then open `GET /commutes/{commuteId}/public`. Without the `opened` call the "new" count never goes down.

---

## 8. Checklist for the app

- [ ] Read `seatPricePiastres`; drop `pricePerSeatPiastres`.
- [ ] Parse `rules` as an object; treat `[]` and `{}` both as empty (old builds).
- [ ] Add a label for the `music` rule ("Music on").
- [ ] Send place `label` / `placeId` with search and demands.
- [ ] Reverse lookup for dropped pins.
- [ ] Commute detail from `/public`; disable Request with `requestEligibility.reason`.
- [ ] Action button from `myStatus` + `overlapsBooking` + `seatsAvailable`.
- [ ] Use `pointTimes` for the chosen day.
- [ ] Stop sending `meetingPreference`, `maxWalkMinutes`, `maxDetourMinutes` (optional now).
- [ ] Women-only chip only when `user.canChooseWomenOnly`.
- [ ] Handle `409 DEMAND_EXISTS` and the two `422`s on demands.
- [ ] Call `POST /matches/{id}/opened` on tap.

## 9. Known limits

- **Matches on commutes published later** need the queue worker running on the server (`php artisan queue:work`). The first match when a demand is saved works without it.
- **Places catalogue** is curated: Greater Cairo areas, business parks and universities (`php artisan places:seed-greater-cairo`). A pin far from every place gets no label.
- **Estimated times** ("pickup around") leave out her own stop's detour and traffic; label them "around".
- **Open product decision:** "this week" = the next 7 days, not Sat → Fri.
