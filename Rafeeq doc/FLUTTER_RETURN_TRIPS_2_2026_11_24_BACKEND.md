# Return trips, round 2: backend answers (2026-11-24)

Answers `docs/feedback/2026-11-24-backend-requirements-return-trips-2.md` in the app repo. Every new
field is additive and optional; nothing that existed changed shape. Full detail is in
`RAFEEQ_MOBILE_API_GUIDE.md` → "Return trips (2026-11-23)".

| # | Ask | Answer |
|---|---|---|
| 1 | Price and free seats on the Return suggestion | ✅ Added |
| 2 | The linked commute on a driver day | ✅ Added as `linkedCommute` |
| 3 | The linked seat on the passenger payload (item 54) | ✅ Added as `linkedBooking` on the driver's booking rows |
| 4 | The Return suggestion after joining a waiting list | ✅ Already sent; now covered by a test |
| 5 | A Return day's estimated arrival on Home | ✅ Added `dropoff.estimatedArrivalLocal` (her drop-off) |
| 6 | Change proposals on a Return | ✅ Confirmed; `direction` added |
| 7 | `alsoOnLinked` on the request preview | ✅ Added |

---

## 1. Price and free seats on the Return suggestion

`POST /commutes/{commute}/seat-requests` and `GET /seat-requests/{seatRequest}`:

```json
"commute": {
  "returnCommute": {
    "id": "01…", "departureLocal": "17:00", "daysMask": 42,
    "runsOnRequestedDays": [2, 8], "trialTripId": null, "myStatus": null,
    "seatPricePiastres": 11500,
    "seatsAvailable": 2
  }
}
```

- `seatPricePiastres`: the Return commute's seat price (`int`, or `null` if it has none yet; a
  published Return always has one).
- `seatsAvailable`:
  - On a recurring request: the free seats on the Return's next scheduled day among her requested
    days.
  - On a trial: the free seats on `trialTripId`'s day.
  - `0` when that day is full. Show the card anyway; the request becomes a waiting-list application.
  - `null` when no such day is scheduled (no `trialTripId` on a trial, or no upcoming day on her
    days). Hide the "Seats left" figure then.

## 2. `linkedCommute` on a driver day

This is on every day row the driver reads:
- `GET /driver/trips` rows
- `GET /driver/home` → `nextRun` and each `laterThisWeek[]` row
- `upcomingTrips[]` on his own `GET /commutes/{commute}` (the Commute details day chips; passengers
  never get it)

```json
"linkedCommute": {
  "id": "01…", "direction": "return", "status": "PUBLISHED",
  "tripId": "01…",
  "departureLocal": "2026-11-29 17:00:00",
  "promisedArrivalLocal": null,
  "estimatedArrivalLocal": "2026-11-29 18:10:00"
}
```

- It names the other commute of the pair: the Return on a Going day, the Going commute on a Return
  day. It shows in any live status (`DRAFT`, `PUBLISHED`, `PAUSED`).
- `tripId` and the three times come from that commute's day **on the same date**, as
  `Y-m-d H:i:s`. All four are `null` when it doesn't run that date or that day is cancelled. The row
  then says it doesn't run that day.
- On a Going linked commute, `promisedArrivalLocal` is set. On a Return, it is `null` and you read
  `departureLocal` (the leave time) and `estimatedArrivalLocal`.
- `linkedCommute` is `null` when the commute isn't linked, or the other side was deleted.

## 3. `linkedBooking` for the Passenger screen (item 54)

The Passenger screen is built from the driver's booking row (`GET /driver/bookings`). Next to
`alsoOnLinked`, each row now carries:

```json
"alsoOnLinked": "booked",
"linkedBooking": {
  "bookingId": "01…", "commuteId": "01…", "direction": "return",
  "pickupLabel": "Smart Village B6",
  "pickupEtaLocal": "17:00",
  "daysMask": 10,
  "tripDate": null
}
```

- `linkedBooking` is set only when she holds a seat on the linked commute **on the same date** as this
  row's day (`alsoOnLinked: "booked"`). Otherwise it is `null`.
- `daysMask`: the weekdays she rides on the linked commute, on a recurring seat. It is `null` on a
  one-day seat, which has `tripDate` (`Y-m-d`) instead.
- `pickupEtaLocal` is `HH:MM` on the linked day's clock, the same estimate her own booking shows. It
  is `null` if her point can't be placed on the route.
- Passengers never get `linkedBooking`.

## 4. The Return suggestion after a waiting-list application

It's already sent. A request on a full Going day comes back as `kind: waitlist_application`, with the
same `commute.returnCommute` as a seat request, and the new fields from §1 are included. Nothing to
change on the backend. Show the card there if the design wants it.

## 5. A Return day's drop-off time on Home

`GET /home` → `nextJourney.dropoff` adds:

| Field | Meaning |
|---|---|
| `estimatedArrivalAt` / `estimatedArrivalLocal` | When the run is expected at **her** drop-off point (`Y-m-d H:i:s`). It falls back to the end of the route when her point can't be placed. |

- `dropoff.promisedArrivalLocal` stays `null` on a Return, so read `dropoff.estimatedArrivalLocal`
  there.
- `nextJourney.estimatedArrivalLocal` is still the end of the route.
- **Live card:** once the run starts, `nextJourney.live.arrivalEtaAt` is the live ETA to her drop-off
  (it is already computed to her drop-off stop). Use it instead of the estimate while `live` is set.

## 6. Change proposals on a Return

- `changes` and `previous` both carry `promisedDepartureTime` (`"17:15:00"` and `"17:00:00"`).
  `departureTime` carries the same values too.
- `direction` (`going` · `return`) is now on every proposal payload, the driver's
  (`GET /driver/change-proposals` and the create answer) and the passenger's (`GET /change-proposals`,
  `GET /change-proposals/{proposal}`).
  - Label a Return's change **"Leaves at"**.
  - Note: the driver's list is `GET /driver/change-proposals`. There is no
    `GET /driver/commutes/{commute}/change-proposals`; that path is `POST` only (create).
- An overlap on a proposal answers the same `409 COMMUTE_SCHEDULE_OVERLAP` with the same
  `error.fields` as the schedule save (`earliestStartLocal`, `otherArrivesAtLocal`, …), so the same
  "Use 10:10 AM" fix works.

## 7. `alsoOnLinked` on the request preview

`GET /driver/seat-requests/{seatRequest}/route-preview` now carries `alsoOnLinked` (`booked` ·
`pending` · `null`), worked out the same way as on the request row. Either source is fine; they agree.
