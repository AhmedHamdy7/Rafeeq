# Backend for return trips (2026-11-23)

Answers to `2026-11-23-backend-requirements-return-trips.md` (review items **38, 39, 40, 46**; plan B1–B11). §1–§14
are built and reach the test server with the next deploy. Old builds keep working: every new request field is
optional with today's behaviour as the default, and every new response field is additive. The two breaking changes
are called out: **`direction` values** (§1) and **the overlap error code** (§4).

| # | Plan | Change | State |
|---|---|---|---|
| 1 | B1 | `direction` = `going` / `return` | ✅ built |
| 2 | B2 | A Return is timed by its promised leave time | ✅ built |
| 3 | B3 | `returnOfCommuteId` / `goingCommute` / `returnCommute` link | ✅ built |
| 4 | B7 | Overlap with a 10-minute gap, `409 COMMUTE_SCHEDULE_OVERLAP` | ✅ built |
| 5 | B4 | Search by direction: Going = arrive by, Return = leave at | ✅ built |
| 6 | B5 | Admin setting `returnWindowMinutes` (30) | ✅ built |
| 7 | B6 | Demands by direction, and the paired return demand in one call | ✅ built |
| 8 | S6 | Return suggestion on the seat request | ✅ built |
| 9 | B9 | `alsoOnLinked` on driver rows | ✅ built (item 54's payload doesn't exist yet) |
| 10 | — | `direction` on every list | ✅ built |
| 11 | B8 | A Going and a Return seat on one day don't clash | ✅ confirmed |
| 12 | B10 | Notifications by direction | ✅ built (`canAddReturn` not needed, see §12) |
| 13 | — | `POST /testing/commutes/linked-pair` | ✅ built |
| 14 | B11 | API guide | ✅ updated |

Time format: every `…Local` field on a **day** is `Y-m-d H:i:s` (like `departureLocal`). Fields on a **link** or a
**suggestion** (`returnCommute.departureLocal`, `goingCommute.promisedArrivalLocal`) and the overlap error are `HH:MM`.

---

## 1 · `direction`

- Values are **`going`** and **`return`** everywhere. `to_work` / `to_home` are still **accepted** on requests and
  read as `going` / `return`, but **never sent back**. Existing commutes were migrated (`to_work` → `going`,
  `to_home` → `return`). ⚠️ If the app compares `direction` with `to_work` / `to_home`, change it now.
- `POST /commutes` takes `direction`. Send it on every create; left out it is `going`.
- `PATCH /commutes/{commute}` takes `direction` **on a draft only**. On a published or paused commute a different
  value answers `409 COMMUTE_NOT_EDITABLE`. Changing a draft from `return` to `going` clears `returnOfCommuteId`.
- `GET /commutes/options`: `defaults.direction` is gone. New: `returnWindowMinutes` (30) and `commuteGapMinutes` (10).

## 2 · Return timing

### Saving the Times step

`PUT /commutes/{commute}/schedule` on a **Return** takes `promisedDepartureTime` (`HH:MM` or `HH:MM:SS`), for every day
or per day:

```json
{
  "daysMask": 62,
  "promisedDepartureTime": "17:00",
  "perDay": { "2": { "promisedDepartureTime": "16:30", "seatsTotal": 2 } },
  "startDate": "2026-11-24"
}
```

| Case | Answer |
|---|---|
| Return with `promisedArrivalTime` (top level or `perDay`) | `422 VALIDATION_FAILED`, `error.fields.promisedArrivalTime` (or `perDay.2.promisedArrivalTime`): "A return commute is timed by when you leave." |
| Return without a leave time on a day | `422 COMMUTE_INCOMPLETE`, `error.fields.missing: ["promisedDepartureTime"]` and `perDay.{bit}.promisedDepartureTime` |
| Going with `promisedDepartureTime` | `422 VALIDATION_FAILED` on that field: "A going commute is timed by when you arrive." |
| Clash with another commute | `409 COMMUTE_SCHEDULE_OVERLAP` (§4) |

A Going commute is unchanged (`promisedArrivalTime`).

### `weekdays[]` rows (draft refill)

```json
{ "weekdayBit": 2, "promisedArrivalTime": null, "promisedDepartureTime": "17:00:00",
  "estimatedArrivalTime": "18:10:00", "departureTime": "17:00:00", "departureIsComputed": false, "seatsTotal": null }
```

- `promisedDepartureTime`: the Return's leave time; `null` on a Going day.
- `estimatedArrivalTime`: the arrival estimated from the route. On a Going day it equals `promisedArrivalTime`. `null`
  before a route is saved.
- `departureIsComputed` is always `false` on a Return.
- `missing[]` on the commute gains `promisedDepartureTime` (a Return day without one) or `promisedArrivalTime` (a Going
  day without one). Publish is refused with `422 COMMUTE_INCOMPLETE` until it is empty.

### The departure never moves

On a Return day the departure is the promise: no re-timing when riders join or leave, no `departure_time_changed`
push. The arrival is recomputed from the route through the riders' points and moves later as riders join.

### Day fields (new on every day the app draws)

| Field | Going day | Return day |
|---|---|---|
| `direction` | `going` | `return` |
| `departureLocal` | worked-out departure | the promised leave time |
| `promisedArrivalAt` / `promisedArrivalLocal` | the promise | `null` |
| `promisedDepartureAt` / `promisedDepartureLocal` | `null` | the promise |
| `estimatedArrivalAt` / `estimatedArrivalLocal` | the promised arrival | the estimated arrival |

They are on: `GET /commutes/{commute}` `upcomingTrips[]`, `GET /commutes/{commute}/public` `upcomingTrips[]`, search
and browse results and `otherDays[]`, `GET /matches` and Home `topMatches`, booking `trip`, `GET /home` `nextJourney`
(and its `alternatives[]`, `canceledTrip`), `GET /driver/home` `nextRun` / `laterThisWeek[]` / `expiredRun`,
`GET /driver/trips`, seat offers (`GET /seat-offers`, `GET /driver/seat-offers`) and the seat request's `commute`.

### Other Return rules

- **Seat checks:** `BOOKING_BREAKS_FROZEN_PROMISE` never applies on a Return day (there is no arrival promise). A
  Return day is judged on capacity and detour only.
- **On time:** a Return run is on time when it **leaves** within 5 minutes of the promised leave time. The arrival is
  not scored.
- **Change proposals** (`POST /driver/commutes/{commute}/change-proposals`, `PATCH` to revise) on a Return take
  `promisedDepartureTime`; `promisedArrivalTime` is refused with `422`. The proposal's `changes` / `previous` carry
  `promisedDepartureTime` (and the same value as `departureTime`).
- **Pickup times** (`meetingPoint.estimatedLocal`, `pointTimes[]`) count forward from the leave time.

## 3 · The link

### `POST /commutes/{commute}/return`

| Case | Answer |
|---|---|
| Going commute without a live Return | `201` — a Return **draft**: reversed route, flipped stop roles, terms, preferences, car, **the same days** (`schedule.daysMask`, start moved to tomorrow if needed, end kept) and **the same seats per day**, **no times** (`weekdays[].promisedDepartureTime: null`, `missing` has `promisedDepartureTime`). `returnOfCommuteId` = the source |
| Already has a Return (draft, published or paused) | `409 COMMUTE_RETURN_EXISTS`, `error.fields.returnCommuteId` — open that one |
| The source is a Return | `422 COMMUTE_NOT_GOING` |

⚠️ `schedule` is **no longer `null`** on the new draft: it has the days and no times. Open the Times step on it.

### On the commute

| Field | Where | Shape |
|---|---|---|
| `returnOfCommuteId` | a Return | the Going commute's id, or `null` |
| `goingCommute` | a Return | `{id, status, promisedArrivalLocal: "08:15", daysMask}` or `null` |
| `returnCommute` | a Going commute | `{id, status, departureLocal: "17:00", daysMask}` or `null` |

- **Driver** (`GET /commutes`, `GET /commutes/{commute}`): any live status, drafts included — so Manage commute shows
  "Return trip" for a draft too, and hides "Add return trip".
- **Passenger** (`GET /commutes/{commute}/public`, search results, `/matches`, seat request): only while the other side
  is **`PUBLISHED`**; a draft or paused Return is `null`.
- `departureLocal` / `promisedArrivalLocal`: the time every day shares, or the next day's when days differ.
- Search results and `/matches` carry `returnCommute` only (a Going result's Return).

### Delete, pause

- Deleting either commute keeps the other and clears the link on both: the Return's `returnOfCommuteId` /
  `goingCommute` become `null`, the Going commute's `returnCommute` becomes `null` (Manage commute shows "Add return
  trip" again).
- Pause / resume never touch the other side.

**Question 5 (link an existing Return later):** not built. It is cheap (`PATCH` with `returnOfCommuteId` on a Return,
same checks as `POST .../return`); say if you want it.

## 4 · Overlap ⚠️ new error code

Item 40, the false report: the code was **`422 COMMUTE_OVERLAPS`** (added 2026-11-11 for COM-36). Two things made it
fire when the times looked fine:

1. It demanded **30 minutes** between one commute's promised arrival and the next one's departure.
2. A Return had no leave time, so its evening time went in as `promisedArrivalTime`, and the server pulled its start
   back by the whole route's duration (e.g. "17:00" became a 16:00 departure).

**Now** `COMMUTE_OVERLAPS` is gone, replaced by **`409 COMMUTE_SCHEDULE_OVERLAP`**:

```json
{ "code": "COMMUTE_SCHEDULE_OVERLAP", "status": 409,
  "error": { "fields": {
    "commuteId": "01…",
    "otherDirection": "going",
    "otherOriginLabel": "Rehab City", "otherDestinationLabel": "Smart Village",
    "weekdayBits": [2, 4],
    "otherStartsAtLocal": "07:15", "otherArrivesAtLocal": "10:00",
    "earliestStartLocal": "10:10",
    "latestArrivalLocal": null,
    "gapMinutes": 10 } } }
```

- The values are **scalars** (not one-item arrays like older errors). `weekdayBits` lists every clashing day with that
  one other commute; mark those days on the Times step.
- `earliestStartLocal` is set when this commute starts too soon after the other; `latestArrivalLocal` when it arrives
  too late for the other's start ("must arrive by 06:50"). One of the two is set.
- **Rule:** shared weekday on a date both run, and one starts before the other's arrival + `gapMinutes` (10, an admin
  setting; `commuteGapMinutes` in options). A Going day starts at its **earliest possible** departure (the worked-out
  one less the 30 minutes it can still move earlier as riders join) and ends at its promised arrival. A Return day
  starts at its leave time and ends at its estimated arrival. Only published and paused commutes count.
- **Checked on:** `PUT /commutes/{commute}/schedule` (draft), `POST .../publish`, `POST .../resume`, and change
  proposals (create and revise).

Example: Going arrives 10:00 → its Return may leave from 10:10. A Return leaving 17:00 never clashes with a Going that
arrives 08:15.

## 5 · Search by direction

`GET /search/commutes` and `GET /search/commutes/browse`:

| Param | Meaning |
|---|---|
| `direction` | `going` (default) · `return`. Only commutes of that direction come back. |
| `arriveBy` | Going: unchanged (promised arrival in [T − 30, T]). Ignored on a Return search. |
| `leaveAt` | Return: `HH:MM`. Fits when the Return's leave time that day is in **[T, T + `returnWindowMinutes`]** (the driver's leave time at the start of his route). Left out: any time. |
| `wantsReturnTrip` | Accepted from old builds and ignored (it never matched anything: it was stored and part of the cache key only). Stop sending it. |

Point-then-area matching, `dateFrom` / `dateTo` and `minSeatsOpen` work in both directions. The `schedule` part of the
score is measured against arrive-by (Going) or leave-at (Return).

Results carry `direction`, the day fields of §2 and `returnCommute` (§3).

Recent searches (`GET /me/recent-searches`) return `criteria.direction` and `criteria.leaveAt` (`HH:MM` or `null`), so a
recent Return search reopens as one. Send them back with the search.

## 6 · `returnWindowMinutes`

Dashboard → Settings → Commute: "Return search window", default **30**, 0–120. Also "Gap between two commutes of one
driver", default **10**. Both are in `GET /commutes/options` (`returnWindowMinutes`, `commuteGapMinutes`). Use them for
copy like "leaves 5:00–5:30 PM".

## 7 · Demands

`POST /commute-demands` and `PATCH /commute-demands/{demand}` take `direction` and `leaveAt` like search.

Response fields:

| Field | Meaning |
|---|---|
| `direction` | `going` · `return` |
| `leaveAt` | Return: `HH:MM`; Going: `null` |
| `arrivalWindowStart` / `arrivalWindowEnd` | Going: as today; Return: `null` |
| `pairedDemandId` | The other demand of a pair made in one call; `null` once either is deleted |

### The pair in one call

```json
POST /commute-demands
{ "origin": {…home…}, "destination": {…work…}, "daysMask": 62, "arriveBy": "08:30",
  "returnDemand": { "leaveAt": "17:00" } }
```

- Creates the Going demand and a **Return** demand with origin and destination **swapped**, the same days,
  preferences and rules, leaving at `returnDemand.leaveAt`. Both or neither: one error refuses both.
- `returnDemand` on a Return demand: `422`, `error.fields.returnDemand`.
- `201` answers the Going demand as today **plus `returnDemand`** (the full second demand), so Review shows both.
- Afterwards they are separate: own `PATCH`, own `DELETE`, own alerts. Use `pairedDemandId` to show them together.

Matching only pairs commutes of the same direction: Return by leave time in [leaveAt, leaveAt + window], Going as
before. `matchCount` per demand as today. `GET /matches` and Home `topMatches` carry `direction` on each match.

## 8 · The ride-back suggestion (S6)

`POST /commutes/{commute}/seat-requests` and `GET /seat-requests/{seatRequest}` (her own view), under `commute`:

```json
"commute": {
  "direction": "going",
  "returnCommute": {
    "id": "01…", "departureLocal": "17:00", "daysMask": 62,
    "runsOnRequestedDays": [2, 8],
    "trialTripId": null,
    "myStatus": null
  }
}
```

- Only when the commute is a Going one with a **published** Return; otherwise `null`.
- Recurring request: `runsOnRequestedDays` = her requested weekday bits that the Return also runs (all its days when she
  asked for every day); `trialTripId` is `null`.
- Trial request: `trialTripId` = the Return's day on the same date (`null` when it doesn't run that date);
  `runsOnRequestedDays` is `[]`.
- `myStatus`: `booked` (a seat on an upcoming Return day) · `pending` (an open or counter-offered request) ·
  `waitlisted` · `null`. Any non-null hides the card.
- `commute` also carries the §2 day fields for the day in view.

**Question 7 (server snapping):** nothing new; the app snaps from `GET /commutes/{returnId}/public` as planned. The two
seat requests stay separate: nothing links them.

## 9 · Who rides both ways (`alsoOnLinked`)

`booked` · `pending` · `null`: she holds a seat (`booked`) or an open request (`pending`, counter-offered or waitlisted
count too) on the **linked** commute on the same day (recurring: any of the same weekdays).

| Where | Notes |
|---|---|
| `GET /driver/seat-requests` rows, and the driver's `GET /seat-requests/{seatRequest}` | absent on the passenger's own view |
| `GET /driver/bookings` rows (and `?commuteId=`) | the driver's view only |
| `GET /driver/waitlist`, `GET /driver/commutes/{commute}/waitlist` rows | rows also carry `direction` (item 45 group header) |

The day's passengers call (items 34–35) uses `GET /driver/bookings?commuteId=`. Item 54's passenger payload doesn't
exist yet; it will carry `alsoOnLinked` when it is built.

## 10 · `direction` everywhere

Added on: `GET /commutes` (top level), `GET /driver/trips`, `GET /driver/home` (`nextRun`, `laterThisWeek[]`,
`expiredRun`), `GET /driver/seat-requests`, `GET /driver/seat-offers` (rounds), `GET /driver/waitlist`,
`GET /driver/bookings` and `GET /bookings` / `GET /bookings/{booking}` (top level, and `trip.direction`), `GET /home`
(`nextJourney`, `nextJourney.alternatives[]`, `canceledTrip`, `topMatches`), `GET /seat-requests` (top level and
`commute.direction`), `GET /seat-offers`, `GET /matches`, `GET /bookings/{booking}/alternatives`, search results.

## 11 · Two seats on one day (confirm)

- "One seat per passenger per time slot" (`BOOKING_ALREADY_BOOKED`) stays.
- A Going seat 07:15–08:15 and a Return seat 17:00–18:10 on the same date are both allowed.
- `overlapsBooking` on search / public / `otherDays[]` only flags a seat that overlaps in time, so her Going seat never
  flags its Return.
- A Return's time slot ends at its **estimated arrival**.

## 12 · Notifications

- Every notification about a commute, a day or a booking carries **`data.direction`**.
- `match_found` on a Return: "A new return commute leaves at 5:10 PM." / "فيه مشوار عودة جديد بيتحرك الساعة 5:10 م."
  (with an area: "leaves Nasr City at 5:10 PM").
- No `departure_time_changed` on a Return.
- `commute_published` doesn't exist as a push, so there is no `canAddReturn`; the Published screen decides from the
  publish response (`direction: going` and `returnCommute: null`).

## 13 · Testing

`POST /testing/commutes/linked-pair` (signed in as an approved driver, staging only):

```json
{ "passengerUserId": "01…", "arriveBy": "08:15", "leaveAt": "17:00" }
```

All optional. Builds a published Going commute (arrives 08:15) and its linked Return (leaves 17:00), Sun–Thu from
tomorrow, through the real create / route / schedule / publish calls. `passengerUserId` gets a confirmed seat on the
next day of both. `201` with `{going, return, bookingIds}`. It goes through the overlap rule, so a second call for the
same driver answers `409 COMMUTE_SCHEDULE_OVERLAP` until the first pair is deleted.

## 14 · Answers to the questions

| # | Question | Answer |
|---|---|---|
| 1 | Item 40: which code, and why? | `422 COMMUTE_OVERLAPS`: a 30-minute gap, and the Return's evening time read as an arrival (§4). Now `409 COMMUTE_SCHEDULE_OVERLAP` with 10 minutes. |
| 2 | Existing `to_home` commutes | Migrated to `return`: each day's leave time = its departure, frozen; future days lose the arrival promise and get an estimated arrival. Not counted on staging yet (the local data has none); the backend counts them before the deploy. |
| 3 | Overlap start for a Going commute | The earliest possible departure (worked-out departure − 30 min). |
| 4 | On-time for a Return | Leaving within 5 minutes of the promised leave time. |
| 5 | Link an existing Return later | Not built; cheap, say if wanted. |
| 6 | `wantsReturnTrip` today | Stored and echoed only; it never filtered or matched. Ignored now. |
| 7 | Server-side snapping | The app snaps. |

## What the app changes

- Read `direction` as `going` / `return` and send it on create (and on draft `PATCH` when the type changes).
- Times step: `promisedDepartureTime` for a Return; show `estimatedArrivalTime` under it.
- `POST .../return` now returns a schedule with days and empty leave times.
- Map `409 COMMUTE_SCHEDULE_OVERLAP` (fields above) on the Times step and publish; drop `COMMUTE_OVERLAPS`.
- Discover: send `direction` and, for Return, `leaveAt` instead of `arriveBy`; stop sending `wantsReturnTrip`.
- New demand: `direction`, `leaveAt`, and `returnDemand {leaveAt}` for the ride back (one call).
- Request sent: `commute.returnCommute` for "Need a ride back too?".
- Driver rows: `alsoOnLinked`; tags from `direction`.
