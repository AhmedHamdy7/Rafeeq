# Maps redesign: backend answers

**Date:** 2026-11-10 · **Answers:** `docs/blockers/maps-redesign-backend.md` (mobile repo) · **Full contract:** `RAFEEQ_MOBILE_API_GUIDE.md`, change log entry "Maps redesign" of 2026-11-10

Items 1, 2, 3, 5, 6 and 8 are done, with a few differences from what you asked. Item 4 is partly done: the
server does **not** detect arrival, because a product rule says the driver taps it. Item 7 is answered. The
changes go live on `rafeeq-production` after the next deploy; we will tell you when.

| # | Status | What you need to do |
|---|---|---|
| 1 | ✅ Done | Use `avatarPath`, not `avatarUrl`. Filter with `?commuteId=&status=PENDING`. |
| 2 | ✅ Done | Match rows by the new ids. Read `alternativePlace`. The lock is **12 h**, not 16 h. |
| 3 | ✅ Done, one difference | "Arrived" is `arrivedAt`, not `reachedAt`. Use `nextStopIndex`. |
| 4 | ⚠️ Partly | Batches already work. Arrival is **not** detected on the server: send "I've arrived" from the Live Activity. |
| 5 | ✅ Done (interim) | Read `pickupEtaAt` / `carToPickupPolyline` from `/trips/{trip}/location`. |
| 6 | ✅ Done, differently | The 3 km tier already exists and is **red**, not amber. Use `detourTiers`. Drop the 1.5 km pickup-change radius. |
| 7 | 🟡 Answered | `polyline` is a straight line unless the server runs the Google engine. No `suggestedPoints` key: use `pickups[]` / `dropoffs[]`. |
| 8 | ✅ Done | Handle five new `type`s. |

---

## 1 · Driver: pickup-change requests on his commute map

`GET /driver/pickup-requests` rows now carry everything you listed. The only name change is
`avatarPath` (the name used everywhere else), not `avatarUrl`.

```json
{
  "id": "01JC…",
  "status": "PENDING",
  "commuteId": "01JA…",
  "commuteGroupId": "01JB…",
  "seatRequestId": null,
  "groupMemberId": "01JD…",
  "tripId": "01JE…",
  "bookingId": "01JF…",
  "effectiveFrom": "next_trip",
  "passenger": { "id": "01JG…", "publicFirstName": "Mona", "avatarPath": null },
  "currentPoint": { "label": "Mostafa El Nahas", "lat": 30.0606, "lng": 31.3302, "isExact": true },
  "label": "أمام صيدلية العبور",
  "proposedPoint": { "label": "Nasr City", "lat": 30.061, "lng": 31.331, "isExact": false },
  "addedMinutes": 3.0,
  "addedKm": 1.2,
  "runTotalMinutes": 7.0,
  "maxDetourMinutes": 30,
  "alternativePlaceId": null,
  "alternativePlace": null,
  "createdAt": "2026-11-10T15:00:00+00:00"
}
```

- `tripId` / `bookingId`: the first day it would apply to. For a member, her next confirmed booking on
  this commute. For a new joiner, the seat request's day, and `bookingId` is null.
- `currentPoint`: her pickup today. For a member, the pickup on that next booking. For a new joiner, the pickup
  she chose on the seat request. Exact, because the driver already sees it.
- `proposedPoint.label`: the area (district or city) while it is fuzzed. Once approved, it is what she typed, or
  the area if she typed nothing. `label` at the top level is what she typed, as before.
- **Filters:** `?status=` (`PENDING`, or several comma-separated), `?commuteId=`, `?commuteGroupId=`,
  `?seatRequestId=`. The commute screen asks `GET /driver/pickup-requests?commuteId={id}&status=PENDING`.
- `approve`, `suggest-alternative` and `reject` work as before and return the same row.

## 2 · Passenger: which booking a request belongs to

`GET /pickup-requests` rows have the same fields as above, without `passenger`. Match a row to a screen
with `commuteGroupId` (booking / group screen) or `seatRequestId` (pending request). The same filters work, so
the booking screen can ask `GET /pickup-requests?commuteGroupId={id}`.

`alternativePlace` is `{id, label, lat, lng, isExact: true}` when the driver countered, so the card can
name and draw the place.

`commuteId` was already on `GET /groups/{group}`.

**Your questions:**

1. **Refusal codes on `POST /groups/{group}/pickup-request`** (and the seat-request one):

   | Code | When | `error.fields` |
   |---|---|---|
   | `422 PICKUP_DETOUR_TOO_LONG` | Beyond the red tier: more than 3 km from the route, over 15 min for her, or over 30 min for the whole run. | `addedMinutes`, `runTotalMinutes`, `maxDetourMinutes`, `pointMeters`, `maxPointMeters` |
   | `422 PICKUP_NOT_ON_COMMUTE` | The driver turned off custom pickups (`allowsCustomPickup: false`), or the route can't be read. **Not** "too far": too far is the code above. | — |
   | `409 PICKUP_ALREADY_REQUESTED` | She already has a `PENDING` or `SUGGESTED_ALTERNATIVE` request. | — |
   | `409 GROUP_NOT_ACTIVE` / `409 SEAT_REQUEST_NOT_PENDING` | Her membership ended / the seat request was answered. | — |

   `COMMUTE_SCHEDULE_LOCKED` is **never** answered when she sends. It is checked when the change is applied.
   Remove it from the send handler, and add the two `409`s above.
2. **Yes.** `accept-alternative` answers `409 COMMUTE_SCHEDULE_LOCKED` (`lockedFrom`, `lockHours`), the same as
   `approve`. The lock is **12 hours** (D-0041), not 16. Read `lockHours`; don't hard-code it.
3. **At once.** Approve (and accept-alternative) writes the new point on **every** upcoming confirmed booking
   on that commute in one go, so `meetingPoint` changes immediately. If any of those days is inside the lock,
   nothing moves and the call is refused. `next_trip` is the only `effectiveFrom` value.
4. **No cooldown.** After a reject she can send a new request right away.
5. **Yes.** `POST /seat-requests/{id}/pickup-request` is for a seat request that is **pending or on the waiting
   list**. The body is the same: `lat`, `lng`, `label`.

## 3 · Driver: live ETAs and reached stops

`GET /driver/commute-occurrences/{trip}/navigation-route` now has:

| Field | Meaning |
|---|---|
| `stops[].etaAt` | **Recalculated on every read once the run has started.** The next stop is routed from the car's latest position; the stops after it add their measured leg times. Never in the past. Without a position in the last 5 minutes, it counts from the last stop reached (or the start). Before the start, it is the planned time. Cached 30 s. |
| `stops[].reachedAt` | As before: a pickup is reached at check-in or no-show, a drop-off at drop-off or early exit. `DRIVER_START` now reads as reached from the moment the run started. |
| `stops[].arrivedAt` | **New.** On `PASSENGER_PICKUP` only: when the driver marked "I've arrived" there. |
| `nextStopIndex` | The first stop not reached, skipping `DRIVER_START`. Null when all are reached. |
| `nextStopDistanceMeters` | Road distance from the car to the next stop. Null without a live position. `distanceMeters` stays the whole run. |
| `etasAreLive` | `true` once the ETAs are live. |

**One difference from your ask:** "Arrived" does **not** set `reachedAt`. While the driver waits at her stop,
that stop stays "next", and the driver can still call her (the call is open only for the next pickup not yet
reached). The stop becomes reached when she boards or is marked a no-show. To show "waiting at Mona's stop",
read `arrivedAt`.

## 4 · Driver location while he is in Google Maps / Waze

1. **Batches: already accepted.** `POST /trips/{trip}/location` takes `points[]` (1–60), each
   `{lat, lng, recordedAt, accuracyMeters, speedKmh}`. `recordedAt` is the phone's time. There is no `heading`:
   work it out from two fixes. **Fixed today:** a late batch older than what we already have used to move the car
   back. Now its points go into the trail, but the current position and `lastLocationAt` don't change.
2. **Arrival on the server: not built.** TRIP-10 says the driver **taps** "I've arrived" and GPS checks it
   (150 m). That tap starts her 5-minute grace, which leads to the no-show fee. Starting it from GPS alone would
   change that rule, so the partners have to decide first. Until then, **put an "I've arrived" button on the
   Live Activity / edge bubble** and call `POST /trips/{trip}/wait-timers` with `bookingId` and the phone's
   current `lat`/`lng`. That follows TRIP-10 and needs no Rafeeq screen.
3. **Last fix time.** There is no `car` object. Read `position.recordedAt` (the phone's time of the fix) and
   `lastLocationAt` (the same, kept after `position` turns `null` 5 minutes after the last fix). "Last seen 2 min
   ago" = `now − lastLocationAt`.
4. **Your questions:**
   - **Timers with gaps:** the +1 / +5 / +10 / +20 min ladder is about a run that **never started**. It reads
     only the departure time and the trip's status, not location, so gaps don't affect it. The pickup grace starts
     from the arrival tap. The early-drop question at drop-off needs a fix from the last 5 minutes; without one,
     it is simply not asked. The admin board flags a car silent for over 2 minutes.
   - **"Driver left Rafeeq" signal:** not needed.

## 5 · Passenger: the driver's ETA to her pickup

`GET /trips/{trip}/location` now has, for a passenger with a confirmed seat:

| Field | Meaning |
|---|---|
| `pickupEtaAt` | When the car should reach her pickup. **The same number** as `etaAt` on her stop for the driver, and as `nextJourney.live.pickupEtaAt` on `/home` (which now uses the same calculation). Null for the driver, before the start, after the arrival tap and after she boards. |
| `carToPickupPolyline` | Encoded road from the car to her pickup, **only while her pickup is the next stop**. Null while the car is collecting someone before her: keep your own Routes call for that case, or draw nothing. |

**Interim:** TRIP-31 says "Driver arrives in X min" is computed **in the app** with Google Maps. We added the
server number so the driver and the passenger see the same time. We are asking the partners to confirm. Until
then, both ways work.

**`nextJourney.live.pickupEtaAt`:** sent while `phase` is `driver_en_route`, when the day's itinerary was built
(the driver opened navigation, or a booking changed). We have checked the code, not the staging and production
data.

## 6 · Place picker: the 3 km tier and the pickup-change radius

**The 3 km tier already exists**, and it is not a warning tier. COM-40 (D-0042) has two tiers:

| Tier | Point from the route | Per passenger | Whole trip |
|---|---|---|---|
| 🟢 On your way | up to 1.5 km | up to 10 min | up to 20 min |
| 🔴 A bit far | up to 3 km | up to 15 min | up to 30 min |

- A point in the red ring can be **sent like any other**. The driver approves every pickup change anyway. Beyond
  3 km (or beyond the minutes), the request can't be sent (`PICKUP_DETOUR_TOO_LONG`).
- ⚠️ **`maxPointDistanceMetres` is already 3000**, the outer red limit, not the 1500 teal area. If the app draws
  the teal area from it, the area is twice too big.
- **New:** `GET /commutes/{commute}/public` returns `detourTiers` (the same shape as `GET /commutes/options`):

  ```json
  "detourTiers": {
    "onWay": { "pointMeters": 1500, "requestMinutes": 10, "tripMinutes": 20 },
    "far": { "pointMeters": 3000, "requestMinutes": 15, "tripMinutes": 30 }
  }
  ```

  Draw the inner area from `onWay.pointMeters` and the ring from `far.pointMeters`.
- **Colour:** the product rule calls the outer tier 🔴 red ("A bit far"), and Discover uses red for it. The new
  design uses amber. Check with design.
- **How we measure:** a **straight line to the route polyline** (the nearest point on the line), not by road and
  not from a suggested point. The minutes are by road. Your road check is stricter than ours, so the server never
  refuses a point for distance that the app accepted. It can still refuse on minutes, so call
  `POST /commutes/{commute}/pickup-preview` before she sends (it returns `tier`, `withinLimit`, `pointMeters`).
- **Pickup change on a booking:** there is **no radius around her current pickup**. A pickup change is checked
  against the route with the same two tiers, like any pickup (BOOK-37, BOOK-08). No product rule has the
  1.5 km-around-current limit. Please remove that constant, or it will block changes the server accepts.

## 7 · What the maps rely on

1. **`polyline`:** it is the real road **only when the server runs the Google engine**
   (`RAFEEQ_GEO_ENGINE=google`). With the default `straight_line` engine, every leg is a straight line. Our
   `.env.production.example` still says `straight_line`; we are checking the real staging and production
   settings and will tell you. When `polyline` is null, keep your fallback.
2. **`lat` / `lng`:** on every stop of `navigation-route`, yes. On `nextJourney.pickup`, yes (rounded to about
   110 m until the seat is confirmed). **New:** `nextJourney.dropoff` now has `lat`, `lng` and `isExact` too.
3. **Trimmed routes:** yes, on `GET /commutes/{commute}/public` → `route`: `isTrimmed: true` before a confirmed
   seat, with `start` / `end` as `{label, lat, lng}`. The labels are **always** area names, also once the route
   is whole. Search results and matches have no polyline; their `originLabel` / `destinationLabel` are place
   names, not areas.
4. **Suggested points:** there is no `suggestedPoints` key. They are `pickups[]` and `dropoffs[]` on
   `GET /commutes/{commute}/public`, each `{id, label, lat, lng, estimatedTime}`. `label` is the address the
   driver typed and can be null. Pass `id` as `suggestionId` when she snaps to one.
5. **Meeting point:** yes. `booking.meetingPoint` is `{lat, lng, label, isExact}`. `isExact: true` once the
   booking is `CONFIRMED` (also `COMPLETED` and ended early). Before that, lat/lng are rounded to about 110 m and
   `label` is null.

## 8 · Push notifications for pickup changes

| Event | `type` | Who | `data` |
|---|---|---|---|
| Passenger asked for a new pickup | `pickup_change_requested` | Driver | `pickupRequestId`, `commuteId`, plus `commuteGroupId` (member) or `seatRequestId` (new joiner) |
| Driver approved | `pickup_change_approved` | Passenger | `pickupRequestId`, `commuteId`, plus `bookingId` + `commuteGroupId` (member) or `seatRequestId` |
| Driver suggested another spot | `pickup_change_alternative` | Passenger | same as approved |
| Driver rejected | `pickup_change_rejected` | Passenger | same as approved |
| Passenger accepted the driver's spot | `pickup_change_alternative_accepted` | Driver | `pickupRequestId`, `commuteId`, `commuteGroupId` (member) |

Keys with no value are left out, not sent as null. All five are in the Booking category (D-0039: "change
proposal · answer to a request").

---

## Known gap on our side

`suggest-alternative` doesn't check the driver's place against the detour tiers. A driver can counter with a
place far from his route. We will fix it separately; the app doesn't need to change.
