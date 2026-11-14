# Answers — Trips (passenger), 2026-11-14

Answers to `backend-trips-gaps-2026-11-14.md`. Everything is in the guide too (4.7 bookings and seat offers, 4.14
notifications, 4.17 testing, 6 realtime). The JSON below was taken from real answers of the new code in a test run, so
dates and ids are test values. Booking samples are trimmed to the fields that matter; the others are whole.

⚠️ Seat-offer ids and `offerGroupId` come out in **upper case** (`01M7DV…`), other ids in lower case. That was
already so before this change. Send them back exactly as you got them.

| # | Answer |
|---|---|
| T-1 | ✅ Fixed. `cancelledReason` is always a code. The typed text is only in `cancelledNote`, and only for whoever wrote it |
| T-2 | ✅ Built: `GET /seat-offers/{offer}`, any status, `404` for somebody else's |
| T-3 | ✅ Built: `GET /change-proposals/{proposal}`, any status. ✅ `GET /change-proposals?bookingId=` |
| T-4 | ✅ `seatRequestId` + `changeProposalId` in `data`. The old request reads `TERMS_CHANGED`. Reapply is the ordinary request |
| T-5 | ✅ Code list on every ended booking, plus `tripCancelReason` and `wasAboard`. One fee exception, read below |
| T-6 | ✅ Built your preferred shape: `alternativeForBookingId`, the server fills the points |
| T-7 | ✅ `commitment` on every booking. Both Ride-again confirmations answered |
| T-8 | ✅ One row per day, grouped by `offerGroupId`. New group accept and decline. No timer |
| T-9 | ✅ `closedReason`, `passedPointLabel`, `stillWaiting`, also on the `offer.closed` socket event |
| T-10 | ✅ Built: close an offer, write a notification. Cancel Active Trip needs no testing call |

**6 new endpoints** (240 in all): `GET /seat-offers/{offer}`, `POST /seat-offers/groups/{group}/accept`,
`POST /seat-offers/groups/{group}/decline`, `GET /change-proposals/{proposal}`, and the staging-only
`POST /testing/seat-offers/{offer}/close` and `POST /testing/notifications`.

**Changed without a new endpoint:** booking fields, seat-offer fields, `?bookingId=` on the proposals list,
`alternativeForBookingId` on the seat request, the `waitlist_cleared_by_change` data, and the `offer.closed` event.

**Behaviour changes you may notice:**

- An open seat offer now closes when its day is cancelled, when its run is stopped, and when she leaves the waiting
  list. Before, it stayed `OPEN` and failed only when she tapped Accept.
- The driver no longer reads the passenger's typed cancel reason either. BOOK-41 says no written reason is shown to
  the other person, in both directions.

Where a rule came from us and not from the business repo, it says **interim**.

---

## T-1 · The driver's private reason

Fixed everywhere the passenger reads a booking: `GET /my-bookings`, `GET /bookings/{id}`, `GET /home`
(`nextJourney` uses the same booking shape), and the notifications, which never carried it.

- `cancelledReason` is a code from the T-5 list. A driver's removal reads `removed_by_driver`.
- The typed text is in **`cancelledNote`**, sent **only to the person who wrote it**:
  - the driver, on his own `removed_by_driver` and `active_trip_cancelled`;
  - the passenger, on her own `passenger_cancelled`, `left_group`, `absence_planned`.
- For everybody else the key is **absent**, not `null`.
- Staff read the text on the dashboard as before.

Rule: BOOK-41 and D-0019, "No written cancel reason is shown to the other person."

The same booking after the driver removed her with `{ "reason": "E13: the car is in service" }`:

**As the passenger** (`GET /bookings/{booking}`, trimmed to the fields that matter here; `cancelledNote` is absent):

```json
{
  "id": "01m7dvh8pq65qtbz6g79ab867k",
  "status": "CANCELLED_BY_DRIVER",
  "commitment": "trial",
  "membership": {
    "committedDaysMask": null,
    "commitment": "trial",
    "status": "ACTIVE"
  },
  "cancelledAt": "2026-09-26T05:00:00+00:00",
  "cancelledReason": "removed_by_driver",
  "tripCancelReason": null,
  "wasAboard": null,
  "cancellationFeePiastres": 0,
  "feeLinePiastres": 0,
  "feeLineReason": null,
  "paymentStatus": "NOT_DUE"
}
```

**As the driver**, same booking:

```json
{
  "id": "01m7dvh8pq65qtbz6g79ab867k",
  "status": "CANCELLED_BY_DRIVER",
  "commitment": "trial",
  "membership": {
    "committedDaysMask": null,
    "commitment": "trial",
    "status": "ACTIVE"
  },
  "cancelledAt": "2026-09-26T05:00:00+00:00",
  "cancelledReason": "removed_by_driver",
  "cancelledNote": "E13: the car is in service",
  "tripCancelReason": null,
  "wasAboard": null,
  "cancellationFeePiastres": 0,
  "feeLinePiastres": 0,
  "feeLineReason": null,
  "paymentStatus": "NOT_DUE"
}
```

Bookings cancelled before this fix still hold the text in the database. They are read through the same code list,
so they come out as `removed_by_driver` as well.

---

## T-2 · `GET /seat-offers/{offer}`

- Answers one item, exactly as a row of `GET /seat-offers`: every field, including `conflictingBookingId` on an
  `OPEN` one and the new T-8 and T-9 fields.
- **Any status**, so a push opened late shows "Somebody was quicker" or "No longer available".
- `404 NOT_FOUND` for somebody else's offer, and for an id that doesn't exist. The two look the same on purpose.

`seat_offered` → open `GET /seat-offers/{data.offerId}`. The guide's notification table now says so.

An open offer, freed seat:

```json
{
  "success": true,
  "data": {
    "id": "01M7DVH8DHTEGH3F21F16RV1AW",
    "status": "OPEN",
    "tripId": "01m7dvh7rh87jvmz6tnffv9gsm",
    "seatRequestId": "01m7dvh8ccrwe2cq1sa2a3954q",
    "offeredAt": "2026-09-26T05:00:00+00:00",
    "closedAt": null,
    "trip": {
      "date": "2026-09-27",
      "departureAt": "2026-09-27T04:12:00+00:00",
      "departureLocal": "2026-09-27 07:12:00",
      "pricePiastres": 14000
    },
    "originLabel": "Rehab Gate 2",
    "destinationLabel": "Smart Village B6",
    "driver": {
      "publicFirstName": "مريم",
      "trustLevel": 3,
      "verifiedLevels": [
        "phone",
        "government_id",
        "selfie",
        "driving_licence"
      ],
      "sameOrganisation": false,
      "rating": null,
      "ratingCount": 0,
      "completedTrips": 0,
      "onTimeRate": null
    },
    "vehicle": {
      "make": "Toyota",
      "model": "Corolla",
      "colour": "Silver"
    },
    "pickup": {
      "label": "Ring Road, Rehab exit",
      "estimatedLocal": "07:38"
    },
    "dropoff": {
      "label": "Mehwar, Sheikh Zayed",
      "estimatedLocal": "09:00"
    },
    "promisedArrivalLocal": "2026-09-27 09:00:00",
    "source": "seat_freed",
    "isPreviousPassenger": false,
    "commitment": "trial",
    "offerGroupId": null,
    "daysMask": null,
    "closedReason": null,
    "passedPointLabel": null,
    "stillWaiting": true,
    "conflictingBookingId": null
  }
}
```

Somebody else's offer:

```json
{
  "success": false,
  "error": {
    "code": "NOT_FOUND",
    "message": "The requested resource was not found.",
    "fields": null
  }
}
```

---

## T-3 · `GET /change-proposals/{proposal}`

- Same shape as a row of `GET /change-proposals`: `id`, `status`, `version`, `scope`, `effectiveFrom`,
  `expiresAt`, `changes` against `previous`, her `bookingId`, `myResponse`.
- `status`: `PENDING` · `APPLIED` · `REJECTED` · `EXPIRED` · `WITHDRAWN`, **whatever it is now**.
- Who may read it: a passenger who answered it, or who holds a booking on one of its days. `404` for anybody
  else, including a passenger who was only on the waiting list.
- 🔒 Same privacy as the list: no roster, no count, nothing about how the others answered.
- `myResponse` is her answer to the **current** `version`, or `null`. After a revision it is `null` again until
  she answers.

**`?bookingId=`: yes, now.** `GET /change-proposals?bookingId=…` keeps only the proposals about that booking of
hers, with that `bookingId` in the row. An id that isn't hers, or isn't affected, gives `[]`.

All four `change_proposal_*` pushes open `GET /change-proposals/{data.changeProposalId}`.

Before she answers, `GET /change-proposals?bookingId=…`:

```json
{
  "success": true,
  "data": [
    {
      "id": "01m7dvh99k33gv3fgewqt2fv0b",
      "commuteId": "01m7dvh7n7e6j5z9phabmdtv17",
      "tripId": "01m7dvh7rwdhbcmze56jxnmks6",
      "tripDate": "2026-09-30",
      "scope": "all_future_on_this_weekday",
      "status": "PENDING",
      "version": 1,
      "changes": {
        "departureTime": "07:30:00",
        "promisedArrivalTime": null,
        "pickupPoints": null
      },
      "previous": {
        "departureTime": "07:12:00",
        "promisedArrivalTime": null,
        "pickupPoints": null
      },
      "effectiveFrom": "2026-09-30",
      "expiresAt": "2026-09-30T04:12:00+00:00",
      "appliedAt": null,
      "createdAt": "2026-09-26T05:00:00+00:00",
      "bookingId": "01m7dvh91f1n99zxznbm8hs6pb",
      "myResponse": null
    }
  ]
}
```

After she accepted and it applied, `GET /change-proposals/{proposal}`:

```json
{
  "success": true,
  "data": {
    "id": "01m7dvh99k33gv3fgewqt2fv0b",
    "commuteId": "01m7dvh7n7e6j5z9phabmdtv17",
    "tripId": "01m7dvh7rwdhbcmze56jxnmks6",
    "tripDate": "2026-09-30",
    "scope": "all_future_on_this_weekday",
    "status": "APPLIED",
    "version": 1,
    "changes": {
      "departureTime": "07:30:00",
      "promisedArrivalTime": null,
      "pickupPoints": null
    },
    "previous": {
      "departureTime": "07:12:00",
      "promisedArrivalTime": null,
      "pickupPoints": null
    },
    "effectiveFrom": "2026-09-30",
    "expiresAt": "2026-09-30T04:12:00+00:00",
    "appliedAt": "2026-09-26T05:00:00+00:00",
    "createdAt": "2026-09-26T05:00:00+00:00",
    "bookingId": "01m7dvh91f1n99zxznbm8hs6pb",
    "myResponse": "ACCEPTED"
  }
}
```

---

## T-4 · `waitlist_cleared_by_change`

**`data` now carries:**

```json
{
  "type": "waitlist_cleared_by_change",
  "title": "A commute you were waiting for has changed",
  "body": "مريم updated this commute’s times, so the waiting list was reset. Take a look and apply again if it still works for you.",
  "data": {
    "commuteId": "01m7dvh7n7e6j5z9phabmdtv17",
    "seatRequestId": "01m7dvh98aqh3fbbbgf14nrmn7",
    "changeProposalId": "01m7dvh99k33gv3fgewqt2fv0b"
  }
}
```

🐞 Also fixed: this notification's text showed a literal `:name` ("`:name` updated this commute's times"). It now
carries the driver's first name, as above.

**The cleared request can still be read.** `GET /seat-requests/{seatRequestId}` answers for it, with its
`pickup`, `dropoff`, `commitment`, `requestedDaysMask`, `seats`, and **`status: "TERMS_CHANGED"`**. That status was
already in the guide's list; it is the one this notification writes, and nothing else writes it.

```json
{
  "id": "01m7dvh98aqh3fbbbgf14nrmn7",
  "commuteId": "01m7dvh7n7e6j5z9phabmdtv17",
  "tripId": "01m7dvh7rwdhbcmze56jxnmks6",
  "status": "TERMS_CHANGED",
  "commitment": "trial",
  "kind": "waitlist_application",
  "requestedDaysMask": null,
  "seats": 1,
  "meetingPreference": "gate",
  "pickup": {
    "lat": 30.0624,
    "lng": 31.3614,
    "label": "Ring Road, Rehab exit",
    "nearPlaceId": null,
    "nearLabel": null,
    "nearDistanceMetres": null,
    "source": "custom_map",
    "suggestionId": null,
    "estimatedAt": "2026-09-30T04:56:48+00:00",
    "estimatedLocal": "2026-09-30 07:56:48"
  },
  "dropoff": {
    "lat": 30.0684,
    "lng": 31.1015,
    "label": "Mehwar, Sheikh Zayed",
    "nearPlaceId": null,
    "nearLabel": null,
    "nearDistanceMetres": null,
    "source": "custom_map",
    "suggestionId": null,
    "estimatedArrivalAt": "2026-09-30T05:50:25+00:00",
    "estimatedArrivalLocal": "2026-09-30 08:50:25",
    "promisedArrivalLocal": "2026-09-30 09:00:00"
  },
  "paymentType": "cash",
  "waitlistPosition": null,
  "respondedAt": "2026-09-26T05:00:00+00:00"
}
```

**Confirmations:**

1. ✅ `GET /commutes/{commute}/public` gives the new times and route: `schedule`, `route`, `pickups[]`,
   `upcomingTrips[]`. It reads after the change has applied, so it is already the new terms.
2. ✅ When the driver has since paused or deleted the commute it answers `404`. Hide Reapply on a `404`.
3. ✅ Reapply is the ordinary `POST /commutes/{commute}/seat-requests`. The old `TERMS_CHANGED` request does
   **not** count as open, so it never answers `BOOKING_ALREADY_REQUESTED`. A test covers it. The new request starts
   at the driver like any other: she needs his approval again (BOOK-19).

---

## T-5 · `cancelledReason` codes

`cancelledReason` is set on **every booking that ended before it was completed**, and `null` while it hasn't. It
is always one of these codes. The list will not change silently: a new code is announced in the guide's change
log.

| Code | When | Booking status | Business rule |
|---|---|---|---|
| `removed_by_driver` | `POST /driver/bookings/{booking}/cancel` | `CANCELLED_BY_DRIVER` | COM-21 |
| `day_cancelled` | The driver cancelled that day (`POST /trips/{trip}/cancel`) | `CANCELLED_BY_DRIVER` | COM-21 |
| `commute_paused` | The driver paused the commute | `CANCELLED_BY_DRIVER` | COM-23 |
| `commute_deleted` | The driver deleted the commute, or his account was deleted | `CANCELLED_BY_DRIVER` | COM-35 |
| `commute_ended` | The commute's schedule reached its end date | `CANCELLED_BY_DRIVER` | |
| `commute_stopped` | The platform stopped the commute (see below) | `CANCELLED_BY_DRIVER` | COM-26, COM-37, COM-47 |
| `vehicle_replaced` | The driver replaced the car | `CANCELLED_BY_DRIVER` | COM-25 |
| `replaced` | Her seat went to another passenger (`replacement-offer` accepted) | `CANCELLED_BY_DRIVER` | COM-22 |
| `active_trip_cancelled` | The run was stopped after it set off (`cancel-active`) | `ENDED_EARLY` or `CANCELLED_BY_DRIVER` | TRIP-22, TRIP-23 |
| `trip_expired` | The driver never started (+20 min) | `EXITED_DUE_TO_DRIVER_DELAY` | PAY-26 |
| `not_started` · `passed_your_stop` · `late_for_pickup` | She left with no fee because the driver let her down (`exit-without-fee`). Same values as `driverDelay`, unchanged from before | `EXITED_DUE_TO_DRIVER_DELAY` | PAY-29 |
| `passenger_cancelled` | She cancelled | `CANCELLED_BY_PASSENGER` | |
| `left_group` | She left the group | `CANCELLED_BY_PASSENGER` | |
| `absence_planned` | She planned an absence | `CANCELLED_BY_PASSENGER` | |
| `declared_away` | She said she won't come that day | `CANCELLED_BY_PASSENGER` | |
| `ended_early` | She ended her own ride early (`end-early`) | `ENDED_EARLY` | |
| `switched` | Switch Commute (her own, or after the driver was late) | either | |
| `blocked` | One of them blocked the other | either | |
| `account_suspended` | Her account was suspended (conduct or debt) | `CANCELLED_BY_PASSENGER` | PAY-46 |
| `account_deleted` | She deleted her account | `CANCELLED_BY_PASSENGER` | |

Your suggested names were kept where they fit. What differs:

- `driver_suspended` · `licence_expired` became **one code, `commute_stopped`**. It covers licence expired,
  car suspended, driver suspended, unpaid commission and a staff review. Your screen shows one sentence for all of
  them ("This trip can't run"), and a passenger has no need to learn which of these applies to her driver.
  **Interim:** the business repo doesn't say what she may be told here. If product wants the cause shown, we split
  the code.
- New codes you didn't list: `commute_ended`, `trip_expired`, `not_started`, `passed_your_stop`, `late_for_pickup`, `ended_early`, `left_group`,
  `absence_planned`, `declared_away`, `account_suspended`, `account_deleted`. Each one is a state that can reach
  History today.

A pause, `GET /bookings/{booking}` as the passenger:

```json
{
  "id": "01m7dvh91f1n99zxznbm8hs6pb",
  "status": "CANCELLED_BY_DRIVER",
  "commitment": "trial",
  "membership": {
    "committedDaysMask": null,
    "commitment": "trial",
    "status": "ACTIVE"
  },
  "cancelledAt": "2026-09-26T05:00:00+00:00",
  "cancelledReason": "commute_paused",
  "tripCancelReason": null,
  "wasAboard": null,
  "cancellationFeePiastres": 0,
  "feeLinePiastres": 0,
  "feeLineReason": null,
  "paymentStatus": "NOT_DUE"
}
```

### `tripCancelReason` and `wasAboard`

On every booking; both are `null` unless `cancelledReason` is `active_trip_cancelled`.

| Field | Type | Value |
|---|---|---|
| `tripCancelReason` | `breakdown` · `accident` · `emergency` · `other` · `null` | The category the driver picked on `cancel-active` (TRIP-22). Never his note. |
| `wasAboard` | `bool \| null` | `true`: she was in the car, the booking is `ENDED_EARLY`. `false`: never collected, `CANCELLED_BY_DRIVER`. |

`tripCancelReason` is sent. **Interim:** BOOK-41 forbids a *written* reason. A category the driver picks from a
list isn't one, and nothing else in the business repo covers it. If product says she must not see it, we send
`null` always and tell you.

`wasAboard` picks the banner and the caption, as TRIP-23 says: on board → alternatives from her current location;
not collected → from her agreed pickup. `meta.origin.kind` on the alternatives call agrees with it.

The two cases, taken from the tests:

```json
{ "status": "ENDED_EARLY", "cancelledReason": "active_trip_cancelled", "tripCancelReason": "breakdown", "wasAboard": true }
```

```json
{ "status": "CANCELLED_BY_DRIVER", "cancelledReason": "active_trip_cancelled", "tripCancelReason": "other", "wasAboard": false }
```

### "No fee for you"

- On every driver-side code, **no cancellation fee and no fee line** are charged to her. `cancellationFeePiastres`
  is `0`, and nothing is added to `feeLinePiastres` for it. The driver pays the fee (PAY-23). On a platform stop
  (`commute_stopped`) nobody pays.
- ⚠️ **One exception to "You won't be charged for this ride":** `active_trip_cancelled` with `wasAboard: true` and
  `tripCancelReason` `breakdown` · `accident` · `emergency`. If she had ridden **more than 25 %** of her own route,
  she pays that share of the fare (PAY-24). `price.totalPiastres` is already the reduced figure. At 25 % or less she
  pays 0 and `paymentStatus` is `WAIVED`. With `other` she always pays 0 (PAY-25).
  So on the aboard banner, say "You won't be charged" only when `paymentStatus` is `WAIVED`. Otherwise show the
  reduced fare. The business repo's own note on screen G05 says the same.

---

## T-6 · Request a seat from Alternatives

Your preferred shape, built:

```
POST /commutes/{commute}/seat-requests
{
  "commitment": "trial",
  "scheduledTripId": "<the card's tripId>",
  "seats": 1,
  "paymentType": "cash",
  "agreedToRules": true,
  "alternativeForBookingId": "<the booking she lost>"
}
```

- `paymentType` and `agreedToRules` are still required. They were missing from the example in your file.
- With `alternativeForBookingId`, leave `pickup` and `dropoff` out. The server fills them:
  - **pickup** = the origin the alternatives call searched from, the same rule as `meta.origin`: her agreed pickup
    when she was never collected; where the car stopped, or the last stop it reached, when she was aboard. Where
    the car stopped has no name, so its label is "Where the car stopped" / "مكان توقف السيارة", in her language.
  - **dropoff** = the old booking's agreed drop-off, with its label.
  - A `pickup` or `dropoff` you do send wins over the filled one.
- The booking must be hers, must not be `COMPLETED`, and must have a pickup and a drop-off. Otherwise
  `422 VALIDATION_FAILED` with `fields.alternativeForBookingId`.
- Everything else is the ordinary request, with the ordinary refusals. The request answers `201` like any other.

**Interim:** the business repo says nothing about pre-filling points from Alternatives (BOOK-08 still requires
both). We built your preferred shape because v5 says she must not retype a journey at the roadside.

`201`, trimmed:

```json
{
  "success": true,
  "data": {
    "id": "01m7dvh8t56f0wyrprysa74eae",
    "commuteId": "01m7dvh7n7e6j5z9phabmdtv17",
    "tripId": "01m7dvh7rscf5yc5ytjj97y6zj",
    "status": "PENDING",
    "commitment": "trial",
    "kind": "seat_request",
    "seats": 1,
    "meetingPreference": "custom",
    "pickup": {
      "lat": 30.0624,
      "lng": 31.3614,
      "label": "Ring Road, Rehab exit",
      "nearPlaceId": null,
      "nearLabel": null,
      "nearDistanceMetres": null,
      "source": "custom_map",
      "suggestionId": null,
      "estimatedAt": "2026-09-29T04:38:48+00:00",
      "estimatedLocal": "2026-09-29 07:38:48"
    },
    "dropoff": {
      "lat": 30.0684,
      "lng": 31.1015,
      "label": "Mehwar, Sheikh Zayed",
      "nearPlaceId": null,
      "nearLabel": null,
      "nearDistanceMetres": null,
      "source": "custom_map",
      "suggestionId": null,
      "estimatedArrivalAt": "2026-09-29T05:32:25+00:00",
      "estimatedArrivalLocal": "2026-09-29 08:32:25",
      "promisedArrivalLocal": "2026-09-29 09:00:00"
    },
    "paymentType": "cash"
  }
}
```

Somebody else's booking:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "alternativeForBookingId": [
        "The selected alternativeForBookingId is invalid."
      ]
    }
  }
}
```

### The card

What you asked to confirm, checked against the card code:

| You asked for | On the card |
|---|---|
| `vehicle.make`, `model`, `colour` | ✅ `vehicle.make`, `vehicle.model`, `vehicle.colour` (plus `tier`, `hasAc`) |
| `seatsAvailable` | ✅ `seatsAvailable` |
| the price | ✅ `seatPricePiastres` |
| the driver as a `person` | ✅ `driver`, plus `completedTrips` |
| the day's `tripId` and `departureLocal` | ✅ `tripId`, `tripDate`, `departureAt`, `departureLocal` at the top of the card |
| when the car passes her | ✅ `meetingPoint.estimatedLocal` (and `estimatedAt`): when the car should reach the meeting point matched to the searched origin. "Passes near you at ~07:31". `null` when the route can't be read |
| `promisedArrivalLocal` | ✅ `promisedArrivalLocal` |

It is the ordinary search card, the same shape as `GET /search/commutes` (guide 4.6), so the card you already draw
for search fits here. ⚠️ The filled pickup is `meta.origin`, not the card's `meetingPoint`. The card's point is the nearest place on that
commute's route, and it can be a short walk away. If she should be collected there instead, send `pickup` yourself
(`{lat, lng, label}` from `meetingPoint`), and the server keeps the filled drop-off.

---

## T-7 · `commitment` on every booking

`commitment: "trial" | "recurring"` is now on every booking: `GET /my-bookings`, `GET /bookings/{booking}`,
`nextJourney`, and every list that returns bookings. It is taken from the request that made the booking, and is
**never `null`**, also after she left the group. `membership` stays as it was.

```json
{ "id": "…", "status": "COMPLETED", "commitment": "trial", "membership": null }
```

**Ride again confirmations:**

1. ✅ `GET /commutes/{commuteId}/public` answers for anybody signed in, so for a passenger whose only link is a past
   booking as well. Nothing about the call depends on a booking.
2. ✅ Paused or archived → `404`. There is no other state to show: a draft is `404` too, and a published commute
   answers in full. A commute she may no longer ask on says so in `requestEligibility`.

Then send the ordinary recurring request. After a trial, BOOK-16 says the driver approves again.

---

## T-8 · Seats the driver offered on resume

**Answers to the questions:**

1. **One row per day.** BOOK-16: each weekday is its own occurrence with its own seat. Rows from one resume offer to
   her share an **`offerGroupId`**, so you draw one card.
2. **Accepting is per day, and there is a group call.** First to accept wins **each day** (COM-33, BOOK-21). She may
   accept some days and not others, with the ordinary `POST /seat-offers/{offer}/accept`. For "Accept" on the card,
   use the new **`POST /seat-offers/groups/{offerGroupId}/accept`**: it takes every open day of the group it can and
   tells you per day what happened.
3. **No timer, no `expiresAt`.** BOOK-22: an offer stays open while the seat is free and the pickup can be reached.
   The driver can withdraw it (COM-33).

**New fields on each `GET /seat-offers` row, and on `GET /seat-offers/{offer}`:**

| Field | Type | Value |
|---|---|---|
| `source` | `seat_freed` · `driver_resume` · `replacement` | Freed seat, the driver chose her on resume, or the driver offered her a named passenger's seat |
| `isPreviousPassenger` | `bool` | She has held a seat on this commute before (COM-29). Same rule as the driver's waiting-list card |
| `commitment` | `trial` · `recurring` | From her waiting request |
| `offerGroupId` | `string \| null` | Only on `driver_resume`, shared by the rows of one resume offer |
| `daysMask` | `int \| null` | Only on `driver_resume`: the weekdays of the group, the same bits as `requestedDaysMask` (Sat 1, Sun 2, Mon 4, Tue 8, Wed 16, Thu 32, Fri 64) |
| `pickup.estimatedLocal` | `HH:MM \| null` | When the car should pass her pickup that day, along the published route |
| `dropoff.estimatedLocal` | `HH:MM \| null` | The promised arrival as a clock: "by 08:15". Same instant as `promisedArrivalLocal` |

`GET /seat-offers?status=OPEN` after a resume, one row shown:

```json
{
  "success": true,
  "data": [
    {
      "id": "01M7DVH9HRECQ87ZD7KS2Y1PF3",
      "status": "OPEN",
      "tripId": "01m7dvh7rwdhbcmze56jxnmks6",
      "seatRequestId": "01m7dvh90bpc70vb32s3m82br3",
      "offeredAt": "2026-09-26T05:00:00+00:00",
      "closedAt": null,
      "trip": {
        "date": "2026-09-30",
        "departureAt": "2026-09-30T04:30:00+00:00",
        "departureLocal": "2026-09-30 07:30:00",
        "pricePiastres": 14000
      },
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "driver": {
        "publicFirstName": "مريم",
        "trustLevel": 3,
        "verifiedLevels": [
          "phone",
          "government_id",
          "selfie",
          "driving_licence"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "vehicle": {
        "make": "Toyota",
        "model": "Corolla",
        "colour": "Silver"
      },
      "pickup": {
        "label": "Ring Road, Rehab exit",
        "estimatedLocal": "07:56"
      },
      "dropoff": {
        "label": "Mehwar, Sheikh Zayed",
        "estimatedLocal": "09:00"
      },
      "promisedArrivalLocal": "2026-09-30 09:00:00",
      "source": "driver_resume",
      "isPreviousPassenger": false,
      "commitment": "trial",
      "offerGroupId": "01M7DVH9HNG7VRGXNHM7M7F0YC",
      "daysMask": 16,
      "closedReason": null,
      "passedPointLabel": null,
      "stillWaiting": true,
      "conflictingBookingId": null
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "lastPage": 1,
    "hasMore": false
  }
}
```

### `POST /seat-offers/groups/{offerGroupId}/accept`

- Takes every **open** day of the group, earliest first, each one by the ordinary accept rules.
- Answers `200` with the bookings it made, and `meta.offers[]` with one row per day:
  `{offerId, status, bookingId, error}`.
  - `status` is `ACCEPTED`, or that day's status when it was lost or had already ended.
  - `error` is the code that lost it (`SEAT_OFFER_CLOSED`, `BOOKING_ALREADY_BOOKED`, …), or `null`.
- Some days can be won while others are lost. Show which.
- `409 SEAT_OFFER_CLOSED` with `fields.offers` when **no** day could be taken.
- `403 RIDING_SUSPENDED_FOR_DEBT` stops the whole call.
- `404` for a group that isn't hers, or isn't a resume group.

`POST /seat-offers/groups/{offerGroupId}/decline` declines every open day and answers all the group's rows. She
stays on the waiting list.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m7dvh9kdmkpc9zwhsk9tdeg0",
      "tripId": "01m7dvh7rwdhbcmze56jxnmks6",
      "tripDate": "2026-09-30",
      "status": "CONFIRMED",
      "commitment": "trial",
      "seatsReserved": 1,
      "price": {
        "seatPricePiastres": 14000,
        "totalPiastres": 14000
      },
      "meetingPoint": {
        "lat": 30.0624,
        "lng": 31.3614,
        "label": "Ring Road, Rehab exit",
        "nearPlaceId": null,
        "nearLabel": null,
        "nearDistanceMetres": null,
        "isExact": true
      },
      "cancelledReason": null
    }
  ],
  "meta": {
    "offers": [
      {
        "offerId": "01M7DVH9HRECQ87ZD7KS2Y1PF3",
        "status": "ACCEPTED",
        "bookingId": "01m7dvh9kdmkpc9zwhsk9tdeg0",
        "error": null
      }
    ]
  }
}
```

---

## T-9 · Why an offer ended

**New fields on each row, on `GET /seat-offers/{offer}`, and on the `offer.closed` socket event:**

| Field | Type | Value |
|---|---|---|
| `closedReason` | see below | `null` while `OPEN` or `ACCEPTED` |
| `passedPointLabel` | `string \| null` | Only with `car_passed`: her pickup's label. "{name}'s trip is already past **Rehab Gate 2**". `null` otherwise, so drop the sentence |
| `stillWaiting` | `bool` | Her waiting request is still `WAITLISTED`, so "You're still on the waiting list for future dates" is true |

**`closedReason` values** (our own list):

| Value | Status | When |
|---|---|---|
| `car_passed` | `INELIGIBLE` | The run is under way and her pickup can no longer be reached (BOOK-22) |
| `already_booked` | `INELIGIBLE` | She already rides that day |
| `seat_taken` | `TAKEN_BY_OTHER` | Somebody was quicker (BOOK-21) |
| `declined` | `DECLINED` | She declined |
| `withdrawn_by_driver` | `WITHDRAWN` | The driver took back his resume offer (COM-33) |
| `request_withdrawn` | `CLOSED` | She left the waiting list |
| `day_cancelled` | `CLOSED` | The day was cancelled, or its run was stopped |
| `commute_paused` · `commute_deleted` · `vehicle_replaced` | `CLOSED` | The commute stopped running |
| `replaced_booking_cancelled` | `CLOSED` | A replacement offer whose passenger cancelled first |

You listed `filled_by_driver`. Nothing in the app lets a driver fill a seat outside the waiting list, so that
value doesn't exist.

Offers that ended before this change have no stored reason. They read from their status: `TAKEN_BY_OTHER` →
`seat_taken`, `INELIGIBLE` → `car_passed`, `DECLINED` → `declined`, `WITHDRAWN` → `withdrawn_by_driver`. An old
`CLOSED` one reads `null`.

"No longer available" against "Somebody was quicker": `seat_taken` is the race (`TAKEN_BY_OTHER`). Everything
under `CLOSED` and `INELIGIBLE` means the seat or her eligibility went away.

The business repo has no wording for "can no longer reach your pickup" (its screen list marks that screenshot as
missing). The sentence is yours.

Closed by the car passing her pickup (made with the testing call):

```json
{
  "success": true,
  "data": {
    "id": "01M7DVH8DHTEGH3F21F16RV1AW",
    "status": "INELIGIBLE",
    "tripId": "01m7dvh7rh87jvmz6tnffv9gsm",
    "seatRequestId": "01m7dvh8ccrwe2cq1sa2a3954q",
    "offeredAt": "2026-09-26T05:00:00+00:00",
    "closedAt": "2026-09-26T05:00:00+00:00",
    "trip": {
      "date": "2026-09-27",
      "departureAt": "2026-09-27T04:12:00+00:00",
      "departureLocal": "2026-09-27 07:12:00",
      "pricePiastres": 14000
    },
    "originLabel": "Rehab Gate 2",
    "destinationLabel": "Smart Village B6",
    "driver": {
      "publicFirstName": "مريم",
      "trustLevel": 3,
      "verifiedLevels": [
        "phone",
        "government_id",
        "selfie",
        "driving_licence"
      ],
      "sameOrganisation": false,
      "rating": null,
      "ratingCount": 0,
      "completedTrips": 0,
      "onTimeRate": null
    },
    "vehicle": {
      "make": "Toyota",
      "model": "Corolla",
      "colour": "Silver"
    },
    "pickup": {
      "label": "Ring Road, Rehab exit",
      "estimatedLocal": "07:38"
    },
    "dropoff": {
      "label": "Mehwar, Sheikh Zayed",
      "estimatedLocal": "09:00"
    },
    "promisedArrivalLocal": "2026-09-27 09:00:00",
    "source": "seat_freed",
    "isPreviousPassenger": false,
    "commitment": "trial",
    "offerGroupId": null,
    "daysMask": null,
    "closedReason": "car_passed",
    "passedPointLabel": "Ring Road, Rehab exit",
    "stillWaiting": true,
    "conflictingBookingId": null
  }
}
```

**`offer.closed` socket event** on `private-user.{userId}`:

```json
{ "offerId": "…", "occurrenceId": "…", "reason": "ineligible", "closedReason": "car_passed", "stillWaiting": true }
```

It is now sent when somebody was quicker, when the car passed her pickup, when the day was cancelled or the run
stopped, and when she left the waiting list. A pause, a deleted commute or a driver withdrawing changes
`GET /seat-offers` but is **not** broadcast yet. Refresh the list on `seats_offered_by_driver` and on the
`commute_*_seat_cancelled` pushes.

---

## T-10 · Testing calls

Same rules as the other `/testing/*` calls: staging only, test numbers only, `404` when the testing API is off.

### `POST /testing/seat-offers/{offer}/close`

```json
{ "status": "INELIGIBLE", "closedReason": "car_passed" }
```

- `status`: `INELIGIBLE` · `CLOSED` · `TAKEN_BY_OTHER`.
- `closedReason` is optional, any value from the T-9 list. Left out: `INELIGIBLE` → `car_passed`,
  `TAKEN_BY_OTHER` → `seat_taken`, `CLOSED` → `day_cancelled`.
- Ends the caller's **open** offer the way the server would, with the `offer.closed` event. Answers the offer as
  `GET /seat-offers/{offer}` does (sample in T-9).
- `404` for somebody else's offer, `409 SEAT_OFFER_CLOSED` if it already ended.

There is no push for a closed offer in the product, so the call sends none.

### `POST /testing/notifications`

```json
{
  "type": "waitlist_cleared_by_change",
  "data": { "commuteId": "…", "seatRequestId": "…", "changeProposalId": "…" },
  "params": { "name": "Nour" }
}
```

- `type`: `waitlist_cleared_by_change` · `seat_offered` · `seats_offered_by_driver` · `change_proposal_received` ·
  `change_proposal_revised` · `change_proposal_applied` · `change_proposal_rejected` · `booking_cancelled`. Any
  other type → `422`.
- `data` keys: `offerId` · `seatRequestId` · `bookingId` · `changeProposalId` · `commuteId` · `tripId`.
  - `offerId`, `seatRequestId` and `bookingId` must be the caller's own.
  - The other three must exist.
  - Otherwise, or for any other key: `422` with `fields.data.<key>`.
- `params` fills the text: `name`, `date`, `days`. Defaults are `Nour`, today, `3`.
- Writes one inbox row for the caller, and the push, exactly as the server would. Answers `201` with the
  notification as `GET /notifications` shows it, or `data: null` when her in-app setting for that category is off.

`201`:

```json
{
  "success": true,
  "data": {
    "id": "01m7dvh9mg2zhjevjxnazq5457",
    "type": "waitlist_cleared_by_change",
    "category": "booking",
    "title": "A commute you were waiting for has changed",
    "body": "Nour updated this commute’s times, so the waiting list was reset. Take a look and apply again if it still works for you.",
    "data": {
      "commuteId": "01m7dvh7n7e6j5z9phabmdtv17",
      "seatRequestId": "01m7dvh98aqh3fbbbgf14nrmn7",
      "changeProposalId": "01m7dvh99k33gv3fgewqt2fv0b"
    },
    "isRead": false,
    "readAt": null,
    "createdAt": "2026-09-26T05:00:00+00:00"
  }
}
```

An offer that isn't hers:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "data.offerId": [
        "Not one of your own records."
      ]
    }
  }
}
```

### Cancel Active Trip

No testing call needed. The real `POST /trips/{trip}/cancel-active` on a started test run gives
`active_trip_cancelled`:

1. Start the run: `POST /testing/trips/{trip}/departure` to bring it close, `POST /trips/{trip}/start`, then status
   `EN_ROUTE`.
2. For `wasAboard: true`, check her in first: `POST /trips/{trip}/check-in` with her `bookingId`, as the driver.
3. `POST /trips/{trip}/cancel-active` with `{ "reason": "breakdown" }`.

Leave out step 2 for `wasAboard: false`. Both are covered by tests.

### A cleared waiting list (T-4)

Also no call needed. Publish → put a second passenger on the waiting list → propose an `all_future_on_this_weekday`
change → every rider on that weekday accepts. The last yes applies the change, writes `TERMS_CHANGED` and sends
`waitlist_cleared_by_change`. That is exactly how the T-4 samples above were made.

---

## Your "needs no backend work" list

All five are right, with one note:

- **Switch Commute** with `switchFromBookingId`: yes. The old booking now reads `cancelledReason: "switched"`.
- **Canceled-booking feedback:** `POST /bookings/{booking}/rating`, stars only (SAFE-23).
