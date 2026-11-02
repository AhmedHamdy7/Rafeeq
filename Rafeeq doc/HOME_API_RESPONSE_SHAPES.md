# Home API — response shapes for Flutter

Real responses captured on 2026-11-02 from a local server running branch `fix/home-gaps` (PR #26) with the demo data. Names and ids come from the demo seeder. Fields that the demo data leaves empty are explained next to them, with what they look like when filled.

Every response is wrapped as `{"success": true, "data": …}`. Errors are `{"success": false, "error": {"code", "message", "fields"}}`.

All times come twice: `…At` is ISO-8601 UTC (use it for maths), `…Local` is `YYYY-MM-DD HH:mm:ss` on Cairo's clock (show it as is). Money is in piastres (int).

---

## 1. `GET /v1/home` — passenger Home (screen 9)

Poll every **30 s while `nextJourney.live` is not null**; otherwise load on open and pull-to-refresh.

### 1.1 Full response (card in `scheduled`)

```json
{
  "success": true,
  "data": {
    "greetingName": "أحمد",
    "verification": null,
    "unreadNotifications": 0,
    "nextJourney": {
      "id": "01m6exd5seytge2cc0za7vrxng",
      "tripId": "01m6exd5mqwk2ncd4g2hchrek3",
      "commuteGroupId": null,
      "commuteId": "01m6exd5cm1pff2gq4hgafhez9",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "driver": {
        "publicFirstName": "داليا",
        "trustLevel": 3,
        "verifiedLevels": [
          "phone",
          "government_id",
          "selfie"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 231,
        "onTimeRate": 96
      },
      "status": "CONFIRMED",
      "seatsReserved": 1,
      "boardingCode": null,
      "price": {
        "seatPricePiastres": 17000,
        "totalPiastres": 17000,
        "driverSharePiastres": {},
        "driverShareTotalPiastres": {}
      },
      "paymentType": "cash",
      "paymentStatus": "NOT_DUE",
      "meetingPoint": null,
      "dropoffPoint": null,
      "otherPartyUserId": null,
      "cancelledAt": null,
      "cancelledReason": null,
      "outcomeSeenAt": null,
      "cancellationFeePiastres": 0,
      "feeLinePiastres": 0,
      "feeLineReason": null,
      "earlyDrop": {
        "askedAt": null,
        "distanceMeters": null,
        "answer": null
      },
      "createdAt": "2026-11-02T19:57:28+00:00",
      "driverDelay": null,
      "pickupEta": null,
      "pickupEtaLocal": null,
      "myRating": {},
      "trip": {
        "id": "01m6exd5mqwk2ncd4g2hchrek3",
        "tripDate": "2026-11-03",
        "departureAt": "2026-11-03T04:40:00+00:00",
        "departureLocal": "2026-11-03 06:40:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 17000,
        "pricePerSeatPiastres": 17000
      },
      "phase": "scheduled",
      "departureAt": "2026-11-03T04:40:00+00:00",
      "departureLocal": "2026-11-03 06:40:00",
      "departsInMinutes": 522,
      "isToday": false,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "daysMask": 62,
      "pickup": {
        "label": "الرحاب، بوابة ٢",
        "at": "2026-11-03T04:40:00+00:00",
        "local": "2026-11-03 06:40:00",
        "lat": null,
        "lng": null,
        "isExact": false
      },
      "dropoff": {
        "label": "القرية الذكية، مبنى B6",
        "promisedArrivalAt": null,
        "promisedArrivalLocal": null
      },
      "live": null,
      "checkIn": {
        "groupId": null,
        "answer": null,
        "cutoffAt": "2026-11-03T02:40:00+00:00",
        "canAnswer": false
      },
      "canLeaveWithoutFee": false,
      "endedAt": null,
      "alternatives": [],
      "completedAt": null,
      "canRate": false,
      "isSeen": false,
      "isTrial": false,
      "vehicle": {
        "make": "Jeep",
        "model": "Compass",
        "colour": "أسود",
        "seats": 3,
        "plateNumber": "ص ع 673"
      }
    },
    "demandAlert": {
      "demandId": "01m6exd5sm3a1nc20qnzk4rsbr",
      "newMatchesCount": 6,
      "originLabel": "الرحاب",
      "destinationLabel": "القرية الذكية",
      "daysMask": 62,
      "arrivalTimeLocal": "06:45",
      "arrivalWindowEndLocal": "08:00"
    },
    "topMatches": [
      {
        "id": "01m6exd6edrhtakh8zms831j70",
        "demandId": "01m6exd5sm3a1nc20qnzk4rsbr",
        "commuteId": "01m6exd5ds98t60344sn738pby",
        "score": 100,
        "audience": "women_only",
        "womenOnly": true,
        "seatPricePiastres": 9500,
        "pricePerSeatPiastres": 9500,
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "departureTimeLocal": "07:45:00",
        "timezone": "Africa/Cairo",
        "tripId": "01m6exd5nzqczgy0rrc941kq23",
        "tripDate": "2026-11-03",
        "departureAt": "2026-11-03T05:45:00+00:00",
        "departureLocal": "2026-11-03 07:45:00",
        "promisedArrivalAt": null,
        "promisedArrivalLocal": null,
        "daysMask": 62,
        "seatsAvailable": 4,
        "meetingPoint": {
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "walkMinutes": 0,
          "estimatedAt": "2026-11-03T05:45:00+00:00",
          "estimatedLocal": "2026-11-03 07:45:00"
        },
        "dropOffPoint": {
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "walkMinutes": 0,
          "estimatedArrivalAt": "2026-11-03T07:24:00+00:00",
          "estimatedArrivalLocal": "2026-11-03 09:24:00"
        },
        "rules": [],
        "otherDays": [
          {
            "id": "01m6exd5p0pya06xrz3yq81p7t",
            "tripDate": "2026-11-04",
            "departureAt": "2026-11-04T05:45:00+00:00",
            "departureLocal": "2026-11-04 07:45:00",
            "promisedArrivalAt": null,
            "promisedArrivalLocal": null,
            "seatsAvailable": 4,
            "myStatus": {
              "kind": "none",
              "seatRequestId": null,
              "bookingId": null
            },
            "overlapsBooking": null
          }
        ],
        "myStatus": {
          "kind": "none",
          "seatRequestId": null,
          "bookingId": null
        },
        "overlapsBooking": {
          "bookingId": "01m6exd5seytge2cc0za7vrxng",
          "driverFirstName": "داليا",
          "departureLocal": "2026-11-03 06:40:00",
          "arrivalLocal": null
        },
        "driver": {
          "publicFirstName": "ياسمين",
          "trustLevel": 3,
          "verifiedLevels": [
            "phone",
            "government_id",
            "selfie"
          ],
          "sameOrganisation": false,
          "rating": null,
          "ratingCount": 0,
          "completedTrips": 0,
          "onTimeRate": null
        },
        "vehicle": {
          "make": "Kia",
          "model": "Cerato",
          "colour": "أحمر",
          "tier": "standard",
          "hasAc": true
        },
        "deliveredAt": null,
        "clickedAt": null,
        "createdAt": "2026-11-02T19:57:29+00:00",
        "demandTitle": "الرحاب → القرية الذكية"
      }
    ],
    "savedSearches": [
      {
        "id": "01m6exd5sk6vbscb8zrkta7v7f",
        "title": "الرحاب ← القرية الذكية",
        "lastSearchedAt": null
      }
    ]
  }
}
```

### 1.2 Top-level fields

| Field | Type | Notes |
|---|---|---|
| `greetingName` | string | Her public first name. |
| `verification` | object \| null | `null` for a passenger-only account. Drivers: `{level, of, percentage, isComplete}`. |
| `unreadNotifications` | int | Bell badge. Same number as `GET /notifications` → `meta.unreadCount`. |
| `nextJourney` | object \| null | The card. `null` → F3P-01 (no commute). See §1.3. |
| `demandAlert` | object \| null | `{demandId, newMatchesCount, originLabel, destinationLabel, daysMask, arrivalTimeLocal "HH:mm", arrivalWindowEndLocal "HH:mm"}`. `null` → no card. The count drops only when the app calls `POST /matches/{match}/opened` (§4). |
| `topMatches[]` | array (≤ 3) | Same shape as `GET /matches` rows, plus `demandTitle` (string \| null → "Matches your demand"), `seatsAvailable` (int \| null), `driver.completedTrips` (int, `0` → "New driver"). |
| `savedSearches[]` | array (≤ 5) | `{id, title, lastSearchedAt: string \| null}`. |

> `topMatches[].seatsAvailable` is the seats on that commute's **soonest day that is still open**. It is `null` when the commute has no future day left (for example a server whose demo days are all in the past). With fresh data it's a number, as above.
>
> `savedSearches[].lastSearchedAt` is `null` until she runs that exact search again after saving it.

### 1.3 `nextJourney` — fields by state

The card is the booking (same fields as `GET /bookings/{booking}`) plus the Home fields below. **Switch the card on `phase`**, never on `status`.

| Field | Type | Filled when |
|---|---|---|
| `phase` | string | Always. `scheduled` · `driver_en_route` · `driver_arrived` · `on_board` · `driver_delayed` · `cancelled_by_driver` · `expired` · `exited` · `completed`. |
| `status` | string | Always. The booking status (`CONFIRMED`, `CANCELLED_BY_DRIVER`, `EXITED_DUE_TO_DRIVER_DELAY`, `COMPLETED`). Only confirmed journeys or ended ones are ever on the card. |
| `daysMask` | int | Always. Sat 1, Sun 2, Mon 4, Tue 8, Wed 16, Thu 32, Fri 64. |
| `pickup` | object | Always. `{label, at, local, lat, lng, isExact}`. `at` = her pickup time. `lat`/`lng` are `null` and `label` is the commute's origin when she meets at the standard point. |
| `dropoff` | object | Always. `{label, promisedArrivalAt, promisedArrivalLocal}`; the promise is `null` when the driver made none. |
| `live` | object \| null | `driver_en_route`, `driver_arrived`, `on_board`, and `driver_delayed` once the run has started. See §1.4. |
| `checkIn` | object \| null | Confirmed journeys only. `{groupId: string \| null, answer: "coming" \| null, cutoffAt, canAnswer: bool}`. Show "I'm coming / I can't make it" only while `canAnswer`. Send to `POST /groups/{groupId}/attendance` `{tripId, status: "coming" \| "away"}`. `null` answer = coming. |
| `canLeaveWithoutFee` | bool | `true` only in `driver_delayed`. Button → `POST /bookings/{id}/exit-without-fee` (§5). |
| `driverDelay` | string \| null | Why she may leave free: `not_started` · `late_for_pickup` · `passed_your_stop`. |
| `endedAt` | string \| null | `cancelled_by_driver`, `expired`, `exited`. |
| `alternatives[]` | array (≤ 2) | Same three phases; otherwise `[]`. See §1.5. |
| `completedAt` | string \| null | `completed`. |
| `canRate` | bool | `completed` and not yet rated. Rate → `POST /bookings/{id}/rating`. |
| `isSeen` | bool | `false` until the app calls `POST /bookings/{id}/seen` (§3). |
| `outcomeSeenAt` | string \| null | When `seen` was first called. |
| `vehicle` | object | `{make, model, colour, seats, plateNumber}`. |
| `driver` | object | Public profile + `completedTrips`. |
| `attendance` | object | `{coming, away, awaiting, total}` for the group. |

> In the demo data `checkIn.groupId` is `null` and `canAnswer` is `false`, because the seeded bookings have no group. A booking made through the app (request → driver approves) has a group, so `groupId` is a string and `canAnswer` is `true` until 2 h before departure.

### 1.4 `driver_delayed` (F3P-07)

Real response, 6 min after departure with the driver not started:

```json
{
  "phase": "driver_delayed",
  "status": "CONFIRMED",
  "canLeaveWithoutFee": true,
  "driverDelay": "not_started",
  "live": null,
  "endedAt": null,
  "alternatives": [],
  "isSeen": false
}
```

Rules (D-0020): `not_started` → 5 min after departure and the run hasn't begun. `late_for_pickup` → the run began but the driver hasn't arrived 5 min after `pickup.at`; then `live` is filled like en route. `passed_your_stop` → the car reached a stop after hers.

### 1.5 `driver_en_route` → `driver_arrived` → `on_board` (F3P-04 → 06)

Real response after the driver started:

```json
{
  "phase": "driver_en_route",
  "status": "CONFIRMED",
  "canLeaveWithoutFee": false,
  "live": {
    "pickupEtaAt": null,
    "arrivalEtaAt": null,
    "progressPercent": null,
    "driverArrivedAt": null,
    "boardedAt": null
  }
}
```

`live` is `{pickupEtaAt, arrivalEtaAt, progressPercent, driverArrivedAt, boardedAt}`, all nullable. The demo run has no itinerary, so the ETAs are `null`. With an itinerary:

```json
"live": { "pickupEtaAt": "2026-11-03T04:44:00+00:00", "arrivalEtaAt": null, "progressPercent": 45, "driverArrivedAt": null, "boardedAt": null }
```

- `driver_en_route`: `pickupEtaAt` + `progressPercent` (to her pickup).
- `driver_arrived`: `driverArrivedAt`, `progressPercent: 100`.
- `on_board`: `arrivalEtaAt` + `progressPercent` (to her drop-off) + `boardedAt`.

### 1.6 Ended: `exited` / `cancelled_by_driver` / `expired` (F3P-08 → 10)

Real response after she pressed Leave Without Fee:

```json
{
  "phase": "exited",
  "status": "EXITED_DUE_TO_DRIVER_DELAY",
  "endedAt": "2026-11-02T19:57:33+00:00",
  "cancelledAt": "2026-11-02T19:57:33+00:00",
  "cancelledReason": "not_started",
  "cancellationFeePiastres": 0,
  "alternatives": [],
  "checkIn": null,
  "isSeen": false,
  "outcomeSeenAt": null
}
```

Real response after the driver cancelled her day (5 days ahead):

```json
{
  "phase": "cancelled_by_driver",
  "status": "CANCELLED_BY_DRIVER",
  "endedAt": "2026-11-02T19:57:34+00:00",
  "cancelledReason": "driver_cancelled_day",
  "cancellationFeePiastres": 0,
  "alternatives": [],
  "isSeen": false
}
```

`expired` looks the same with `status: "EXITED_DUE_TO_DRIVER_DELAY"` and `cancelledReason: "trip_expired"`.

`alternatives[]` is empty in the demo because no other commute fits. When filled:

```json
"alternatives": [
  { "commuteId": "01k9…", "tripId": "01k9…", "departureAt": "2026-11-03T05:30:00+00:00", "departureLocal": "2026-11-03 07:30:00",
    "originLabel": "Fifth Settlement", "destinationLabel": "Smart Village B12", "seatsAvailable": 2,
    "driver": { "publicFirstName": "Karim" } }
]
```

**The ended card stays until the app calls `POST /bookings/{id}/seen`** (§3), for up to 7 days. After that `/home` moves on (here, `nextJourney: null`).

### 1.7 `completed` (F3P-11)

```json
"phase": "completed", "status": "COMPLETED", "completedAt": "2026-11-03T05:47:00+00:00", "canRate": true,
"isSeen": false, "live": null, "endedAt": null, "alternatives": [], "checkIn": null
```

Stays until she rates or the app calls `seen`.

---

## 2. `GET /v1/driver/home` — driver Home (screen 23)

Real response with the run under way and one passenger who left (`notices`):

```json
{
  "success": true,
  "data": {
    "greetingName": "داليا",
    "nextRun": {
      "commuteId": "01m6exd5cm1pff2gq4hgafhez9",
      "tripId": "01m6exd5mqwk2ncd4g2hchrek3",
      "groupId": null,
      "status": "EN_ROUTE",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "departureAt": "2026-11-02T20:02:34+00:00",
      "departureLocal": "2026-11-02 22:02:34",
      "departsInMinutes": 5,
      "isToday": true,
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 2,
      "seatsTaken": 1,
      "seatsOpen": 1,
      "collectPiastres": 17000,
      "keepPiastres": 15401,
      "passengers": [
        {
          "bookingId": "01m6exd5t2n5aawkdbdrx9a0kp",
          "person": {
            "publicFirstName": "عمر",
            "trustLevel": 3,
            "verifiedLevels": [
              "phone",
              "government_id",
              "selfie"
            ],
            "sameOrganisation": false,
            "rating": null,
            "ratingCount": 0,
            "completedTrips": 0,
            "onTimeRate": null
          },
          "commitment": null,
          "requestedDaysMask": null,
          "seatsReserved": 1,
          "pickup": null,
          "attendanceStatus": null
        }
      ]
    },
    "laterThisWeek": [
      {
        "commuteId": "01m6exd5cm1pff2gq4hgafhez9",
        "tripId": "01m6exd5mr5fdan2kpcgnejhvm",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "departureAt": "2026-11-04T04:40:00+00:00",
        "departureLocal": "2026-11-04 06:40:00",
        "seatsTotal": 2,
        "seatsTaken": 0,
        "seatsOpen": 2
      },
      {
        "commuteId": "01m6exd5cm1pff2gq4hgafhez9",
        "tripId": "01m6exd5mshbc93n1t91te08k2",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "departureAt": "2026-11-05T04:40:00+00:00",
        "departureLocal": "2026-11-05 06:40:00",
        "seatsTotal": 2,
        "seatsTaken": 0,
        "seatsOpen": 2
      }
    ],
    "notices": [
      {
        "kind": "seat_opened",
        "bookingId": "01m6exd5seytge2cc0za7vrxng",
        "tripId": "01m6exd5mqwk2ncd4g2hchrek3",
        "commuteId": "01m6exd5cm1pff2gq4hgafhez9",
        "person": {
          "publicFirstName": "أحمد",
          "trustLevel": 3,
          "verifiedLevels": [
            "phone",
            "government_id",
            "selfie"
          ],
          "sameOrganisation": false,
          "rating": null,
          "ratingCount": 0,
          "completedTrips": 0,
          "onTimeRate": null
        },
        "seatsReleased": 1,
        "seatsOpen": 1,
        "departureAt": "2026-11-02T20:02:34+00:00",
        "departureLocal": "2026-11-02 22:02:34",
        "at": "2026-11-02T19:57:33+00:00"
      }
    ],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "stats": {
      "onTimeRate": 96,
      "completedTrips": 231,
      "avgDetourMinutes": 0
    }
  }
}
```

| Field | Type | Notes |
|---|---|---|
| `nextRun` | object \| null | Soonest run not finished. |
| `nextRun.leaveBy` | object \| null | `{at, local, lateWarning: bool}` — "Leave by 7:10". Only for a run not started yet, leaving within 3 h, with a promised arrival; `null` otherwise (as in the demo). `lateWarning: true` → traffic makes the scheduled departure late. |
| `laterThisWeek[]` | array (≤ 7) | Her runs in the next 7 days after `nextRun`. |
| `notices[]` | array (≤ 5) | Last 24 h, newest first. `kind`: `seat_opened` (cancelled / left) or `passenger_switched` (moved to another commute). |
| `pendingRequests` | object | `{count, items[] (≤ 5)}`; `count` is all of them. |
| `stats` | object | `{onTimeRate: int \| null, completedTrips: int, avgDetourMinutes: number \| null}`. Show a dash for `null`. |

`leaveBy` when filled:

```json
"leaveBy": { "at": "2026-11-03T04:28:00+00:00", "local": "2026-11-03 06:28:00", "lateWarning": true }
```

A passenger-only account gets `{greetingName, nextRun: null, laterThisWeek: [], notices: [], pendingRequests: {count: 0, items: []}, stats: {…}}`, never a 404.

---

## 3. `POST /v1/bookings/{booking}/seen`

No body. Call it once the ended or completed card has actually been on screen, not on every `/home` load. Safe to repeat; the first time is kept.

**200** — returns the booking:

```json
{
  "success": true,
  "data": {
    "id": "01m6exd5seytge2cc0za7vrxng",
    "tripId": "01m6exd5mqwk2ncd4g2hchrek3",
    "commuteGroupId": null,
    "status": "EXITED_DUE_TO_DRIVER_DELAY",
    "seatsReserved": 1,
    "boardingCode": null,
    "price": {
      "seatPricePiastres": 17000,
      "totalPiastres": 17000
    },
    "paymentType": "cash",
    "paymentStatus": "NOT_DUE",
    "meetingPoint": null,
    "dropoffPoint": null,
    "otherPartyUserId": "01m6exd5c7w71wa1659t3c3arn",
    "cancelledAt": "2026-11-02T19:57:33+00:00",
    "cancelledReason": "not_started",
    "outcomeSeenAt": "2026-11-02T19:57:33+00:00",
    "cancellationFeePiastres": 0,
    "feeLinePiastres": 0,
    "feeLineReason": null,
    "earlyDrop": {
      "askedAt": null,
      "distanceMeters": null,
      "answer": null
    },
    "createdAt": "2026-11-02T19:57:28+00:00"
  }
}
```

**409** — the journey hasn't ended:

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_NOT_ENDED",
    "message": "That journey has not ended yet.",
    "fields": {
      "status": [
        "CONFIRMED"
      ]
    }
  }
}
```

**404** — not her booking.

---

## 4. `POST /v1/matches/{match}/opened`

No body. Call it when she taps a match (a `topMatches[]` row or a `GET /matches` row). Safe to repeat. Lowers `demandAlert.newMatchesCount`.

**200** — returns the match with `clickedAt` set:

```json
{
  "success": true,
  "data": {
    "id": "01m6exd6edrhtakh8zms831j70",
    "demandId": "01m6exd5sm3a1nc20qnzk4rsbr",
    "commuteId": "01m6exd5ds98t60344sn738pby",
    "score": 100,
    "audience": "women_only",
    "womenOnly": true,
    "seatPricePiastres": 9500,
    "pricePerSeatPiastres": 9500,
    "originLabel": "الرحاب، بوابة ٢",
    "destinationLabel": "القرية الذكية، مبنى B6",
    "departureTimeLocal": "07:45:00",
    "timezone": "Africa/Cairo",
    "tripId": "01m6exd5nzqczgy0rrc941kq23",
    "tripDate": "2026-11-03",
    "departureAt": "2026-11-03T05:45:00+00:00",
    "departureLocal": "2026-11-03 07:45:00",
    "promisedArrivalAt": null,
    "promisedArrivalLocal": null,
    "daysMask": 62,
    "seatsAvailable": 4,
    "meetingPoint": {
      "lat": 30.0602,
      "lng": 31.4915,
      "address": "الرحاب، بوابة ٢",
      "walkMinutes": 0,
      "estimatedAt": "2026-11-03T05:45:00+00:00",
      "estimatedLocal": "2026-11-03 07:45:00"
    },
    "dropOffPoint": {
      "lat": 30.0724,
      "lng": 31.0116,
      "address": "القرية الذكية، مبنى B6",
      "walkMinutes": 0,
      "estimatedArrivalAt": "2026-11-03T07:24:00+00:00",
      "estimatedArrivalLocal": "2026-11-03 09:24:00"
    },
    "rules": [],
    "otherDays": [
      {
        "id": "01m6exd5p0pya06xrz3yq81p7t",
        "tripDate": "2026-11-04",
        "departureAt": "2026-11-04T05:45:00+00:00",
        "departureLocal": "2026-11-04 07:45:00",
        "promisedArrivalAt": null,
        "promisedArrivalLocal": null,
        "seatsAvailable": 4,
        "myStatus": {
          "kind": "none",
          "seatRequestId": null,
          "bookingId": null
        },
        "overlapsBooking": null
      }
    ],
    "myStatus": {
      "kind": "none",
      "seatRequestId": null,
      "bookingId": null
    },
    "overlapsBooking": {
      "bookingId": "01m6exd5seytge2cc0za7vrxng",
      "driverFirstName": "داليا",
      "departureLocal": "2026-11-03 06:40:00",
      "arrivalLocal": null
    },
    "driver": {
      "publicFirstName": "ياسمين",
      "trustLevel": 3,
      "verifiedLevels": [
        "phone",
        "government_id",
        "selfie"
      ],
      "sameOrganisation": false,
      "rating": null,
      "ratingCount": 0,
      "completedTrips": 0,
      "onTimeRate": null
    },
    "vehicle": {
      "make": "Kia",
      "model": "Cerato",
      "colour": "أحمر",
      "tier": "standard",
      "hasAc": true
    },
    "deliveredAt": null,
    "clickedAt": "2026-11-02T19:57:33+00:00",
    "createdAt": "2026-11-02T19:57:29+00:00"
  }
}
```

**404** — not her match.

---

## 5. `POST /v1/bookings/{booking}/exit-without-fee`

Body optional: `{"reason": "…"}`. Show the button only when `nextJourney.canLeaveWithoutFee` is `true`.

**200** — returns the booking, `status: EXITED_DUE_TO_DRIVER_DELAY`, fee 0:

```json
{
  "success": true,
  "data": {
    "id": "01m6exd5seytge2cc0za7vrxng",
    "tripId": "01m6exd5mqwk2ncd4g2hchrek3",
    "commuteGroupId": null,
    "status": "EXITED_DUE_TO_DRIVER_DELAY",
    "seatsReserved": 1,
    "boardingCode": null,
    "price": {
      "seatPricePiastres": 17000,
      "totalPiastres": 17000
    },
    "paymentType": "cash",
    "paymentStatus": "NOT_DUE",
    "meetingPoint": null,
    "dropoffPoint": null,
    "otherPartyUserId": "01m6exd5c7w71wa1659t3c3arn",
    "cancelledAt": "2026-11-02T19:57:33+00:00",
    "cancelledReason": "not_started",
    "outcomeSeenAt": null,
    "cancellationFeePiastres": 0,
    "feeLinePiastres": 0,
    "feeLineReason": null,
    "earlyDrop": {
      "askedAt": null,
      "distanceMeters": null,
      "answer": null
    },
    "createdAt": "2026-11-02T19:57:28+00:00"
  }
}
```

**409** — not entitled yet:

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_NOT_CANCELLABLE",
    "message": "That booking can no longer be cancelled.",
    "fields": {
      "driverDelay": [
        "NOT_DELAYED"
      ],
      "departureAt": [
        "2026-11-03T04:40:00+00:00"
      ]
    }
  }
}
```

**404** — not her booking.
