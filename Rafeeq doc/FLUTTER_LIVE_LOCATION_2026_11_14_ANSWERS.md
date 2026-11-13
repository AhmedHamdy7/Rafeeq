# Live car position: ordering and freshness — backend answers

**From:** backend · **Date:** 2026-11-14 · **Answers:** `backend-live-location-2026-11-14.md`
**Covers:** `POST /trips/{trip}/location`, `GET /trips/{trip}/location`, channel
`private-trip.{tripSessionId}` (`location.updated`)

The full contract is in `RAFEEQ_MOBILE_API_GUIDE.md`, section 4.9. This file answers the note item
by item and lists what the app has to change.

## Summary

| # | Item | Answer |
|---|---|---|
| 1 | Current position follows `recordedAt` | **Done** (was true only for requests one after another; now atomic) |
| 2 | Arrival time never used for ordering | **Already so** |
| 3 | The same point sent twice changes nothing | **Done** |
| 4 | `location.updated` only for a newer position | **Done** |
| 5 | `GET` returns one consistent snapshot | **Done** |
| 6 | Route and ETA not recalculated on every point | **Done** (rule below) |
| 7 | Device clock problems stay visible | **Confirm: already so. Ask: done** (`rejectedReason`) |
| 8 | Sequence number | **Won't do** (reason below) |

## What the app has to change

1. **Read `rejectedReason` on `POST /trips/{trip}/location`.** When it is `"CLOCK_SKEW"`, tell the
   driver his phone's clock is wrong. Drop the batch from the buffer: sending it again will be
   rejected again.
2. **Drop a batch from the buffer whenever the call returns 200**, whatever `accepted` and
   `rejected` say. A repeated point counts in `accepted`; a dropped point will never be accepted
   later.
3. **Send no two points with the same `recordedAt` second.** A point is identified by its
   `recordedAt`, to the second, within the run. Two points in the same second are one point: the
   first one wins. The app reads every 10 s, so this only matters for test code.
4. **Handle `position: null`.** It becomes `null` 5 minutes after the last fix. `lastLocationAt`
   stays, so "Last seen 7 min ago" is `now − lastLocationAt`.
5. **Handle `carToPickupPolyline: null`.** It is only filled while the passenger's pickup is the
   car's next stop and a position is current.
6. **Animate between positions.** The driver uploads about every 30 s. For a smooth car, move it
   along `carToPickupPolyline` between two positions and do not jump in a straight line.
7. **Keep polling.** The socket is not switched on in production yet (see "Socket" below).

The guard you are adding (ignore a `position` older than the one on screen) is still worth
keeping. It costs nothing and protects against a cached HTTP response.

## 1. The stored current position follows `recordedAt` — Done

The current position of a run is the accepted point with the greatest `recordedAt`.

- A batch whose points are all older than, or equal to, the current position: the points join the
  trail, `position` and `lastLocationAt` do not change, no event is sent.
- A batch that mixes older and newer points: the newest becomes the current position, the rest
  only join the trail. The order of `points[]` in the request does not matter.
- Two requests for the same run at the same instant: the "is this newer?" check and the write now
  run under a per-run lock, and the check re-reads the stored value inside the lock. The result is
  the same as if the requests had run one after the other.

If the lock cannot be taken within 5 seconds the call fails with a 5xx. Retry it as you retry any
failed batch.

## 2. Server arrival time is never used for ordering — Already so

`position.recordedAt` and `lastLocationAt` are the phone's time of the fix. No query, cache key or
sort that decides "latest" uses the arrival time.

A separate arrival-time column was **not** added. `trip_locations.created_at` exists, but it is the
time the batch was written to the database (up to 30 s after arrival), so it is not a clean
measure of the driver's connection.

## 3. The same point sent twice changes nothing — Done

`(trip session, recordedAt)` is unique.

- A repeat adds no trail row, before or after the trail is written to the database.
- A repeat sends no `location.updated`.
- A repeat is counted in `accepted`, so the app drops it from its buffer.

Example: the same two points sent twice.

```json
{ "data": { "accepted": 2, "rejected": 0, "rejectedReason": null } }
```

Both calls return this. Only the first one moves the car and sends an event.

## 4. `location.updated` is only broadcast for a newer position — Done

- A batch that moves the current position forward in time sends **one** event, carrying its newest
  point. A batch of 60 buffered points sends one event, not 60.
- A late batch sends no event.
- A repeated batch sends no event.

Payload, unchanged:

```json
{ "lat": 30.0611, "lng": 31.2314, "recordedAt": "2026-11-14T08:00:30+00:00", "accuracyMeters": 12, "speedKmh": 48 }
```

## 5. `GET /trips/{trip}/location` returns one consistent snapshot — Done

- The position is read once per request. `carToPickupPolyline` starts at the returned `position`.
- `pickupEtaAt` is counted from `position.recordedAt`, not from the time of the request. If that
  gives a time in the past, the response says "now".
- `lastLocationAt` is never older than `position.recordedAt` in the same response.
- The cached position is written by the same rule as item 1, so it cannot hold an older point than
  the database.
- Two calls in a row never return a `position.recordedAt` that goes backwards.

Example, passenger whose pickup is the next stop:

```json
{
  "data": {
    "position": { "lat": 30.05, "lng": 31.2, "recordedAt": "2026-11-14T08:00:30+00:00", "accuracyMeters": 12, "speedKmh": 48 },
    "lastLocationAt": "2026-11-14T08:00:30+00:00",
    "isUnderway": true,
    "pickupEtaAt": "2026-11-14T08:06:10+00:00",
    "carToPickupPolyline": "…",
    "stopsBeforeYou": 0
  }
}
```

## 6. Route and ETA are not recalculated on every point — Done

The road from the car to the next stop is fetched from Google once and kept for that leg. On every
new position the server trims that road so it starts at the car, and scales the remaining time by
the remaining distance. This is local arithmetic with no Google call.

The road is fetched again only when one of these is true:

| When | Value |
|---|---|
| The next stop changed, or the itinerary was rebuilt | at once |
| The car is off the stored road on **two readings in a row** | more than 75 m |
| The stored road is old | 5 minutes |
| No road is stored yet | first read |

One reading off the road does not trigger a fetch, because a single reading is often GPS noise.

How to describe freshness in the app:

- `pickupEtaAt` and `carToPickupPolyline` change with every new position.
- The estimate is **without live traffic**. Google is asked for the plain road time.
- The same numbers feed `etaAt` in `navigation-route` and `nextJourney.live.pickupEtaAt` on
  `/home`, so the driver and the passenger see the same time.

The 75 m and 5 minute values are dashboard settings and may be tuned. They are interim: no product
rule fixes them yet.

## 7. Device clock problems stay visible — Done

**Confirm:** a dropped point never becomes the current position. Points are dropped when they are
more than 2 minutes in the future, from before the run could have begun, or have an accuracy over
500 m.

**Ask:** the response of `POST /trips/{trip}/location` has a new field.

| Field | Meaning |
|---|---|
| `accepted` | How many points were kept. Repeats count. |
| `rejected` | How many were dropped. |
| `rejectedReason` | `"CLOCK_SKEW"` when **every** point in the batch was dropped for its time (future, or from before the run could have begun). `null` in every other case. |

Whole batch 10 minutes in the future:

```json
{ "data": { "accepted": 0, "rejected": 2, "rejectedReason": "CLOCK_SKEW" } }
```

One bad point in a batch of two, or a batch dropped for accuracy:

```json
{ "data": { "accepted": 1, "rejected": 1, "rejectedReason": null } }
```

`"CLOCK_SKEW"` is the only value today. Treat any other string as "unknown reason" so a later
value does not break the app. The field name and value are interim: no product rule fixes them yet.

## 8. Sequence number — Won't do

`recordedAt` is enough now that items 1 and 3 hold.

- Ordering for one phone is already correct without it.
- The map and the ETA need to know **when** the car was at a point, so `recordedAt` would still be
  required next to `seq`.
- A counter restarts at 1 when the driver reinstalls the app, clears its data or changes phone
  during a run. The server would then reject every new point as old.
- The one case it would help is a phone whose clock jumps backwards during a run. That is rare,
  and item 7 already tells the app when a whole batch was dropped for its time.

If clock jumps show up in real trips, `seq` can be added later without breaking the app.

## Tests

All in `tests/Feature/Trip/TripLocationTest.php`.

| # | Status | Note |
|---|---|---|
| T1 | Passes | Also checks the late point is in the trail |
| T2 | Passes | |
| T3 | Passes | Checked before and after the trail is written down |
| T4 | Passes | **Simulated**: the test replays a request that holds an out-of-date copy of the run. It does not fire 100 parallel requests |
| T5 | Passes | Zero events for 60 late points |
| T6 | Passes | One event, carrying the newest of 60 shuffled points |
| T7 | Passes | |
| T8 | Passes | Sequential reads; no real 1 s wait |

## Socket

`.env.production.example` has `BROADCAST_CONNECTION=log`, so `location.updated` is not delivered
on a server set up from it. Polling `GET /trips/{trip}/location` is the working path until Reverb
is switched on. Nothing in the payload or channel name will change when it is.

## Testing endpoint

The testing endpoint that places the car stamps the point with "now". Two calls within the same
second count as one point, so the second call does not move the car. Leave one second between
calls in automated tests.
