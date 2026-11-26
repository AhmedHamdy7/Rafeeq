# Google Maps cost: backend answers (2026-11-26)

Answers `google-maps-cost-backend.md` (2026-11-25). **All three asks are built and tested.** Details are in
`RAFEEQ_MOBILE_API_GUIDE.md` (changelog rows of 2026-11-26).

## Ask 1: the server sends the road

`polyline` is a Google encoded polyline, the same string Google Routes returns. Draw it and skip your own Routes call.
When it is `null`, ask Google yourself as you do today.

| Endpoint | Field | Which road |
|---|---|---|
| `GET /commutes/{commute}/route-preview` (driver, draft or published) | `polyline` | Start → End |
| `GET /commutes/{commute}` and the other driver commute responses | `routePolyline` | Start → End, as published |
| `GET /commutes/{commute}/public` (commute detail) | `route.polyline` | Start → End |
| `GET /driver/seat-requests/{id}/counteroffer-options` | `route.polyline` | Start → End |
| `GET /driver/commute-occurrences/{trip}/navigation-route` | `polyline` | The day's road through `stops[]` in order |
| `GET /driver/seat-requests/{id}/route-preview` | `polyline` | The day's road **with** her two points |
| `POST /commutes/{commute}/pickup-preview` | `polyline` (new) | Start → her point → End |
| `POST /commutes/{commute}/dropoff-preview` | `polyline` (new) | Start → her pickup → her drop-off → End |

**Same settings as the app:** `travelMode: DRIVE`, `routingPreference: TRAFFIC_UNAWARE`, `computeAlternativeRoutes: false`,
`polylineQuality: OVERVIEW`.

**Not sent:**

- **Search results and match cards.** They carry no road, on purpose.
- **Passenger Live trip.** It already has `carToPickupPolyline`.

**When `polyline` is `null`:**

- Google did not answer.
- A commute's road is more than 30 days old.
- A day's road was built more than 30 days ago.

**Google's terms:**

- A road is never kept or shown past 30 days.
- `commutes:refresh-roads` (daily, 02:30 Cairo time) fetches a commute's road again once it is 7 days old.
- Distance, duration and price stay as published.

## Ask 2: suggested places (`GET /places/suggestions`)

| Call | When |
|---|---|
| `?lat=&lng=&kind=start\|end\|pickup\|dropoff&limit=8` | Before she types: "Suggested near you" |
| `?q=&lat=&lng=` | While she types. **Empty list → call Google Autocomplete** |

**What each item is:** `{id, kind, label, lat, lng, googlePlaceId, area, distanceMetres, uses}`. It is the point shape
the app already sends: send it back as it is. A suggestion she taps costs no Google call.

**How the list is ranked:**

- **Near her:** rows within 5 km, nearest first in 300 m steps. Then the ones used for that `kind`: `start` and `pickup`
  mean getting in, `end` and `dropoff` mean getting out. Then the most used.
- **By name:** English or Arabic, ignoring case and Arabic letter forms (`ا/أ/إ/آ`, `ه/ة`, `ي/ى`, tashkeel). Within
  50 km when `lat` / `lng` are sent.

**How the list is built:** from the points the app already sends. **There is no new call.**

| Source | Shown to others |
|---|---|
| A published commute's start, end and suggested points | Yes |
| A place chosen from Google search (it has `googlePlaceId`) | Yes. Coordinates are refreshed or dropped within 30 days; the name and `googlePlaceId` are kept for good |
| Her own pin | **No**, until 3 different people picked a point within 50 m. Then the centre of those pins is shown, named by a label at least 2 of them typed, otherwise by the area |

**How points are counted:**

- Points within 30 m are one row.
- Her pin on a driver's point counts for that point.
- Draft commutes teach nothing.

The coordinates job is `places:refresh-coordinates` (daily, 02:45 Cairo time). It uses Place Details with field mask
`location`, on the server key.

## Ask 3

| Item | Status |
|---|---|
| **ETA on the server** (`pickupEtaAt`) | Already on `GET /trips/{trip}/location`, from the car's live position |
| **One area check per point** | Done: a search no longer asks Google for the area of her start and end. It uses the `area` the app sends, or one the server already knows. Saved points (commute, demand, seat request, pickup change) are still checked once, when saved |
| **Smooth car over Reverb** | `POST /trips/{trip}/location` takes `points[].heading` (0–360). `location.updated` on `private-trip.{tripSessionId}` carries `{lat, lng, recordedAt, accuracyMeters, speedKmh, heading}`. New `trip.pickup_eta` on `private-user.{userId}`: `{tripId, tripSessionId, bookingId, pickupEtaAt, stopsBeforeYou}`, after each batch that moves the car |
| **Driver send rate** | The server accepts any rate (up to 60 points per batch). Send every 3–5 s on the way to a pickup and every 10 s otherwise |
| **Production server key only on production** | A deployment setting (Google Cloud and Railway), not code. See below |

**Before the socket works on production:** Reverb must be switched on there. Today production has
`BROADCAST_CONNECTION=log`, so nothing reaches the app until the backend team sets
`BROADCAST_CONNECTION=reverb`, fills the `REVERB_*` keys and runs Reverb. Until then the app's 15 s poll is the
only feed.

## Answers to the questions

1. **Compute Route Matrix:** not used anywhere.
2. **What the server calls Google for:**
   - **Routes:** publish and the price, the detour and stop order, search (her own start → end), the day's road leg by
     leg, the leave and arrival times, the live car → next stop leg, and now the weekly road refresh.
   - **Geocoding:** the area check, on saved points only.
   - **Place Details:** the 30-day coordinate refresh of suggestions.
   - **Calls per day on the live backend:** not known from the code. Check APIs & Services → Metrics, by credential.
3. **Ask 1:** all the endpoints above. Each road is refreshed weekly and never kept past 30 days.
4. **Ask 2:** ready now, with all three sources.
5. **Ask 3:**
   - **`pickupEtaAt`:** recomputed from the live position on every read and every moving batch. The road to the next
     stop is fetched again every 5 minutes, or when the car is 75 m off it.
   - **Broadcast:** live on `private-trip.{tripSessionId}` and `private-user.{userId}` once Reverb is on in production.
6. **Area check:** on save only. Search no longer does it.
