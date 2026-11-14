# Answers — test APIs for My vehicle and Driver reliability (2026-11-14)

Answers to `backend-test-apis-vehicle-reliability-2026-11-14.md`. Every call is in guide §4.17, with its body,
answer and errors. Same rules as the other `/testing/*` calls.

| # | Answer |
|---|---|
| R1 | ✅ Built: `POST /testing/reliability/appeals/{appeal}/decide` |
| R2 | ✅ Built: `POST /testing/reliability/reset` |
| R3 | ✅ Built: `POST /testing/reliability/events/{event}/age` |
| R4 | ✅ Built: `POST /testing/reliability/events`, except `allowUnknownKind` (see below) |
| R5 | ✅ Built: `POST /testing/ratings/reports/{report}/decide` |
| R6 | ❌ Not built. Use `?perPage=1` on `GET /ratings/about-me` instead |
| V1 | ✅ Built: `approvedSeats`, `tier` and `hasAc` on the vehicle approve call |
| V2 | ✅ Built: `DELETE /testing/driver/vehicles/{vehicle}` |
| V3 | ✅ Already there: the registration expiry call sets `approvalValidUntil` |
| Preview | ✅ `replace-preview` now answers `blocked` |
| Paused | Intended: a paused commute blocks the switch too |

One change to every testing call: when the testing API is off, they all answer `404` before anything else. Before,
a call with a body could answer `422` or `401` first, which gave away that it existed.

## R1 · Decide a reliability appeal

`POST /testing/reliability/appeals/{appeal}/decide`, as you specified:

```json
{ "decision": "declined", "declineReasonCode": "not_enough_evidence" }
```

- `approved`: `counts: false`, `reversedAt` set, `fee.reversed: true` with the fee credited back, score
  recomputed, `appeal.status: approved`, `decidedAt` set. `appeal_approved` is sent.
- `declined`: `appeal.status: declined`, `decidedAt` and `declineReasonCode`. `appeal_declined` is sent. With a
  round left, the next 48-hour window opens from the decision: `appealsLeft: 1`, `needsNewEvidence: true`, and
  `appealRefusal: NEW_EVIDENCE_REQUIRED` until a new file is added. After a second decline: `appealsLeft: 0`,
  `appealRefusal: NO_APPEALS_LEFT`.
- `declineReasonCode` defaults to `other`. An unknown value or `decision` answers `422`. You can also send an
  optional `note`.
- Answers `200` with the event, exactly as one row of `GET /account/reliability` → `events[]`.
- Refusals: `409 APPEAL_NOT_PENDING` once decided; `404` for somebody else's appeal.

A first-round decline, `200`:

```json
{
  "success": true,
  "data": {
    "id": "01M7DDV19XXAD1J8FTGKX9H4ZV",
    "kind": "DRIVER_CANCELLED_BOOKING",
    "weight": 5,
    "occurredAt": "2026-09-26T05:00:00+00:00",
    "bookingId": "01m7ddv17pm67gf2cn1e9pk7yc",
    "tripId": "01m7ddv0vkhasr5pt7ekb9rwha",
    "commuteId": "01m7ddv0qs219dpwfbf42tqckj",
    "cause": "cancelled_booking",
    "groupId": null,
    "cancelReason": null,
    "person": { "userId": "01m7ddv109meyhvnvbr22b83rm", "publicFirstName": "سارة" },
    "counts": true,
    "reversedAt": null,
    "reversedReason": null,
    "canAppeal": false,
    "appealsLeft": 1,
    "needsNewEvidence": true,
    "appealBy": "2026-09-28T05:00:00+00:00",
    "appealRefusal": "NEW_EVIDENCE_REQUIRED",
    "evidence": [],
    "appeal": {
      "id": "01m7ddv1bbk502hxazrcfq9m38",
      "round": 1,
      "status": "declined",
      "appealedAt": "2026-09-26T05:00:00+00:00",
      "decidedAt": "2026-09-26T05:00:00+00:00",
      "declineReasonCode": "not_enough_evidence"
    },
    "fee": { "debtEntryId": "01m7ddv1ajhphjkg7ha8pbs6db", "amountPiastres": 3500, "reversed": false }
  }
}
```

An approval, `200`. This one is an event with no booking, so `person` and `fee` are `null`. On an event with a
fee, `fee.reversed` turns `true`:

```json
{
  "success": true,
  "data": {
    "id": "01M7DDV1FWWEAYV95GECR7RJVS",
    "kind": "DRIVER_CANCELLED_DAY",
    "weight": 5,
    "occurredAt": "2026-09-26T05:00:00+00:00",
    "bookingId": null,
    "tripId": null,
    "commuteId": null,
    "cause": null,
    "groupId": null,
    "cancelReason": null,
    "person": null,
    "counts": false,
    "reversedAt": "2026-09-26T05:00:00+00:00",
    "reversedReason": null,
    "canAppeal": false,
    "appealsLeft": 0,
    "needsNewEvidence": false,
    "appealBy": null,
    "appealRefusal": "ALREADY_REVERSED",
    "evidence": [],
    "appeal": {
      "id": "01m7ddv1g9m7y68k9j63n5jhk9",
      "round": 1,
      "status": "approved",
      "appealedAt": "2026-09-26T05:00:00+00:00",
      "decidedAt": "2026-09-26T05:00:00+00:00",
      "declineReasonCode": null
    },
    "fee": null
  }
}
```

Deciding it again, `409`:

```json
{
  "success": false,
  "error": {
    "code": "APPEAL_NOT_PENDING",
    "message": "This appeal has already been decided.",
    "fields": null
  }
}
```

⚠️ **Event ids and `groupId` come in upper case**, every other id in lower case. That is how the server has always
written them, on `GET /account/reliability` too. The server accepts either case. Compare ids without case in the
app.

## R2 · Reset the caller's reliability record

`POST /testing/reliability/reset`, no body. It removes every reliability event on the caller (both sides), with
their appeals and evidence files, and takes off the fees those events wrote. The fees are taken off with a credit
line, not deleted, so the debt ledger still adds up. `GET /account/debt` shows the credit.

It answers `200` with the whole record as `GET /account/reliability` returns it: `asDriver.score` back at
`perfectScore`, `events: []` on both sides.

- ⚠️ A driver already **suspended for debt** stays suspended. Call `POST /testing/driver/reinstate` with
  `scope: account` after the reset.
- Passenger fee warnings (`POST /testing/attendance`) are a separate history and are not touched.

```json
{
  "success": true,
  "data": {
    "asDriver": { "score": 100, "events": [] },
    "asPassenger": { "score": 100, "events": [] },
    "windowDays": 90,
    "perfectScore": 100
  }
}
```

## R3 · Move an event's appeal window

`POST /testing/reliability/events/{event}/age` with `{ "hours": 49 }` (1–2400). It moves the event's time, its
recorded time, its appeals and their decisions, and its fee line back by that many hours, then answers the event.

- `49` → `canAppeal: false`, `appealRefusal: APPEAL_WINDOW_CLOSED`, `appealBy: null`. The appeal call then answers
  `422` with `fields.appeal: ["APPEAL_WINDOW_CLOSED"]`.
- After a declined first round, `49` closes the second window the same way.
- `2400` (100 days) takes the event out of the 90-day score. It stays in `events[]`.

The R1 decline above, aged 49 hours, `200`. Only these fields change; the rest of the event is as before:

```json
{
  "success": true,
  "data": {
    "id": "01M7DDV19XXAD1J8FTGKX9H4ZV",
    "occurredAt": "2026-09-24T04:00:00+00:00",
    "canAppeal": false,
    "appealBy": null,
    "appealRefusal": "APPEAL_WINDOW_CLOSED",
    "appeal": {
      "id": "01m7ddv1bbk502hxazrcfq9m38",
      "round": 1,
      "status": "declined",
      "appealedAt": "2026-09-24T04:00:00+00:00",
      "decidedAt": "2026-09-24T04:00:00+00:00",
      "declineReasonCode": "not_enough_evidence"
    }
  }
}
```

## R4 · Write a reliability event

`POST /testing/reliability/events`:

```json
{ "kind": "DRIVER_LATE", "count": 3, "grouped": true, "minutesAgo": 30 }
```

| Field | |
|---|---|
| `kind` | Any documented kind, upper or lower case. Driver kinds need an approved driver: `422` with `fields.kind: ["DRIVER_NOT_APPROVED"]` |
| `cause` | Any documented cause, or leave it out for the old shape with no `cause` |
| `count` | 1–10, default 1 |
| `minutesAgo` | Default 0. The appeal window runs from then |
| `grouped` | One shared `groupId` for all of them |
| `bookingId` | One booking on the caller's commute (driver kinds) or her own seat (passenger kinds). Fills `person`, `bookingId` and `tripId`. Only with `count: 1` |
| `withFee` · `feePercent` | A fee line on that booking, `feePercent` (default 50) of its price. Needs `bookingId` and a kind that carries a fee, otherwise `422` |

It answers `201` with the written events, each as a row of `events[]`. Two grouped, with no cause:

```json
{
  "success": true,
  "data": [
    {
      "id": "01M7DDV1E555BCDXTJ1JTVW47X",
      "kind": "DRIVER_LATE",
      "weight": 2,
      "occurredAt": "2026-09-26T04:30:00+00:00",
      "bookingId": null,
      "tripId": null,
      "commuteId": null,
      "cause": null,
      "groupId": "01M7DDV1E46MB18RZHD8S1FPRH",
      "cancelReason": null,
      "person": null,
      "counts": true,
      "reversedAt": null,
      "reversedReason": null,
      "canAppeal": true,
      "appealsLeft": 2,
      "needsNewEvidence": false,
      "appealBy": "2026-09-28T04:30:00+00:00",
      "appealRefusal": null,
      "evidence": [],
      "appeal": null,
      "fee": null
    },
    {
      "id": "01M7DDV1E722ZYV44CKJ59Y7H2",
      "kind": "DRIVER_LATE",
      "weight": 2,
      "occurredAt": "2026-09-26T04:30:00+00:00",
      "bookingId": null,
      "tripId": null,
      "commuteId": null,
      "cause": null,
      "groupId": "01M7DDV1E46MB18RZHD8S1FPRH",
      "cancelReason": null,
      "person": null,
      "counts": true,
      "reversedAt": null,
      "reversedReason": null,
      "canAppeal": true,
      "appealsLeft": 2,
      "needsNewEvidence": false,
      "appealBy": "2026-09-28T04:30:00+00:00",
      "appealRefusal": null,
      "evidence": [],
      "appeal": null,
      "fee": null
    }
  ]
}
```

One on a booking with a fee, from
`{ "kind": "DRIVER_LATE_CAUSED_EXIT", "cause": "cancelled_booking", "bookingId": "…", "withFee": true, "feePercent": 20 }`.
The booking's price was EGP 140, so the fee is EGP 28:

```json
{
  "success": true,
  "data": [
    {
      "id": "01M7DDV1ETP83M78N0BT51CNYG",
      "kind": "DRIVER_LATE_CAUSED_EXIT",
      "weight": 10,
      "occurredAt": "2026-09-26T05:00:00+00:00",
      "bookingId": "01m7ddv17pm67gf2cn1e9pk7yc",
      "tripId": "01m7ddv0vkhasr5pt7ekb9rwha",
      "commuteId": null,
      "cause": "cancelled_booking",
      "groupId": null,
      "cancelReason": null,
      "person": { "userId": "01m7ddv109meyhvnvbr22b83rm", "publicFirstName": "سارة" },
      "counts": true,
      "reversedAt": null,
      "reversedReason": null,
      "canAppeal": true,
      "appealsLeft": 2,
      "needsNewEvidence": false,
      "appealBy": "2026-09-28T05:00:00+00:00",
      "appealRefusal": null,
      "evidence": [],
      "appeal": null,
      "fee": { "debtEntryId": "01m7ddv1ezz7tbhjcvgnqd7reh", "amountPiastres": 2800, "reversed": false }
    }
  ]
}
```

The refusals are all `422 VALIDATION_FAILED`. A driver kind for somebody who isn't an approved driver:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": { "kind": ["DRIVER_NOT_APPROVED"] }
  }
}
```

`withFee` without a booking gives `"fields": { "withFee": ["NEEDS_DRIVER_BOOKING_AND_FEE_KIND"] }`. A kind the
server doesn't know gives `"fields": { "kind": ["The selected kind is invalid."] }`.

**The old event shape:** leave out `cause` and `bookingId`. That gives `cause`, `groupId`, `person` and
`cancelReason` all `null`, as on events written before those fields existed.

**`allowUnknownKind` was not built.** The server stores `kind` as a fixed list. An event with an unknown kind
would break `GET /account/reliability` itself (a `500`), not show the app's fallback row. A new kind can only reach
the app after a server update adds it to the list. Test the plain-row fallback with a mocked response.

**`withFee` needs a booking.** A fee line belongs to a booking, so `withFee` without `bookingId` answers
`422 fields.withFee: ["NEEDS_DRIVER_BOOKING_AND_FEE_KIND"]`.

## R5 · Decide a review report

`POST /testing/ratings/reports/{report}/decide` with `{ "decision": "removed" }` or `"kept"`. It answers:

```json
{ "id": "…", "status": "UPHELD", "reviewVisible": false }
```

- `removed` → `status: UPHELD`. The review leaves `GET /ratings/about-me`, and `meta.byKind` counts and averages
  without it.
- `kept` → `status: DISMISSED`. The review stays.
- The status names are the ones the server already uses for a report (`PENDING` when filed). They are not
  `REMOVED` / `KEPT`.
- **`reportedByMe` after `kept` stays `true`.** It means "you reported this review", whatever was decided. Keep
  showing "Reported" on it.
- Refusals: `409 REVIEW_REPORT_NOT_PENDING` once decided; `404` for somebody else's report.

Deciding it again, `409`:

```json
{
  "success": false,
  "error": {
    "code": "REVIEW_REPORT_NOT_PENDING",
    "message": "This report has already been reviewed.",
    "fields": null
  }
}
```

## R6 · Seed reviews — not built

Every review belongs to a real booking and a real reviewer, so seeding 25 would mean creating 25 bookings and 25
fake members on the shared server. Two things cover what you need instead:

- **Load more:** `GET /ratings/about-me` takes `perPage` (1 up to the server's maximum). With `?perPage=1`, two
  reviews make two pages, so the second page and `meta.hasMore` can be tested with the reviews one run makes.
- **A non-whole canceled-booking average:** two canceled-booking ratings, one of 2 stars and one of 3, give 2.5.
  Each needs a cancelled booking, but no completed run.

## V1 · Reviewer's values on the vehicle approval

`POST /testing/driver/vehicles/{vehicle}/approve` now takes an optional body:

```json
{ "approvedSeats": 3, "tier": "standard", "hasAc": false }
```

- Each value is optional. Leave them all out for the old behaviour: the seats typed (up to 7), `comfort`, and
  `hasAc` as declared.
- `approvedSeats` takes the dashboard's range, 3 to 7 today. It can be higher than the seats typed, as on the
  dashboard, so pick a sensible number. Outside the range: `422 VALIDATION_FAILED` with `fields.approvedSeats`.
- The answer now also has `tier`, `hasAc` and `declaredAc`, so `declaredAc: true` with `hasAc: false` shows
  directly.

A car added with `hasAc: true`, approved with the body above, `200`. `declaredAc` is `null` when the driver
didn't say:

```json
{
  "success": true,
  "data": {
    "id": "01m7ddwzydc5vb62qsw98sk6dr",
    "verificationStatus": "APPROVED",
    "approvedSeats": 3,
    "tier": "STANDARD",
    "hasAc": false,
    "declaredAc": true,
    "isActive": false,
    "rejectedItem": null,
    "rejectionReason": null
  }
}
```

`approvedSeats: 9`, `422`:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": { "approvedSeats": ["Approved seats must be between 3 and 7."] }
  }
}
```

## V2 · Delete a test vehicle

`DELETE /testing/driver/vehicles/{vehicle}` removes one of the caller's cars, whatever its status, with its
documents and their files. It frees the plate again.

- Answers `200` with `{ vehicleId, deleted: true }`, not `204`. Every delete in this API answers with the
  envelope.
- `409 VEHICLE_IS_ACTIVE` for the active car.
- `409 VEHICLE_IN_USE` with `fields.commuteIds` while a commute still names the car, **archived commutes
  included**. A commute that moved to a new car with a switch no longer names the old one, so the car you switched
  away from can be deleted. A car that ever ran a commute you archived without switching cannot.
- `404` for somebody else's car.

Deleted, `200`:

```json
{
  "success": true,
  "data": { "vehicleId": "01m7ddv37eb54x65bf8qmjxh1a", "deleted": true }
}
```

The active car, `409`:

```json
{
  "success": false,
  "error": {
    "code": "VEHICLE_IS_ACTIVE",
    "message": "This is the car you drive now. Switch to another car before removing it.",
    "fields": null
  }
}
```

A car an archived commute still names, `409`:

```json
{
  "success": false,
  "error": {
    "code": "VEHICLE_IN_USE",
    "message": "A commute still uses this car, so it cannot be removed.",
    "fields": { "commuteIds": ["01m7ddycqe955yxf9z13wx7we6"] }
  }
}
```

## V3 · Set a car's approval date

Already covered. `POST /testing/driver/documents/vehicle_registration/expiry` with `{ "expiresAt": "2027-03-03" }`
makes `approvalValidUntil: "2027-03-03"` in `GET /driver/vehicles`. `approvalValidUntil` is the earliest
registration expiry on the car, and that call sets it.

A **past date** answers your second question: the car becomes `status: SUSPENDED` with
`suspensionReason: registration_expired`. There is no separate "approval lapsed" status. `vehicleId` picks a car
other than the active one.

## The replace preview and the seats refusal

**1. The preview now warns.** `GET /driver/vehicles/replace-preview?vehicle={id}` has a new field, `blocked`.
`activate` makes the same checks in the same order, so when `blocked` is `null` the switch won't be refused for the
car or its seats:

```json
{
  "success": true,
  "data": {
    "affectedBookings": 0,
    "affectedCommutes": 0,
    "affectedSeats": 0,
    "feePiastres": 0,
    "reason": "none",
    "perTrip": [],
    "blocked": {
      "code": "COMMUTE_SEATS_EXCEED_APPROVED",
      "commuteIds": ["01m7ddycqe955yxf9z13wx7we6"],
      "approvedSeats": 3
    }
  }
}
```

| `code` | Means |
|---|---|
| `VEHICLE_NOT_ALLOWED` | The car isn't approved yet (`commuteIds` empty) |
| `VEHICLE_SEATS_NOT_APPROVED` | The car has no approved seat count |
| `COMMUTE_SEATS_EXCEED_APPROVED` | These commutes offer more seats than `approvedSeats` |

`blocked` is `null` when the switch would go through, and always `null` without `?vehicle=`. When it is set, show
the reason instead of the confirm.

**2. A paused commute blocking the switch is intended.** Every commute that isn't archived moves to the new car,
paused ones included. A paused commute resumes on the active car and sells the seats it advertises. If it could
move onto a smaller car, resuming it would sell more seats than the car has. The guide now says so. To switch, that
commute has to fit the new car's seats or be archived first, the same as a published one.
