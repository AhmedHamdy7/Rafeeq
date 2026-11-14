# Flutter handoff: Verification and Driver reliability

**Date:** 2026-11-14 · **Backend commit:** `3396753` on `main` · **Answers:** `backend-gaps.md` of 2026-11-14.

You are wiring the Verification screens (G09–G13) and the Driver reliability screens (H01–H04).
The backend gaps are closed. This file says what to build, what to change in the design, and shows
the real responses, captured from the server's test run. Ids, dates and names are sample data.

Every response uses the standard envelope: `{ "success": true, "data": ..., "meta": ... }` or
`{ "success": false, "error": { "code", "message", "fields" } }`. All paths are under `/api/v1`.

## 1. What to do

### Verification

1. **Document kinds (V-1).** Send `kind` per `type`: `government_id` → `national_id_front`,
   `national_id_back`; `selfie` → `selfie`; `driving_licence` → `licence_front`, `licence_back`.
2. **Selfie (V-2).** One image is the whole check. Remove "blink" and any liveness step.
3. **Biometric consent (V-3).** When the checkbox is ticked, call `POST /account/consents` with
   `{ "type": "biometric" }`. Read `biometric.acceptedAt` from `GET /account/consents` to know
   whether it is already given. Send no `version`.
4. **Licence (V-4).** Upload both sides and collect `nationalId` (14 digits), `licenceNumber` and
   `licenceExpiry` (`YYYY-MM-DD`). The single tile in the design is not enough.
5. **Registration (V-5).** Do not send `expiresAt`. The reviewer sets it.
6. **Vehicle form (V-6, V-7, V-8, V-9).**
   - Send `make` and `model` as two fields; both are required.
   - `seats` is required (2–8, total with the driver). Add a field.
   - Send the Air conditioning switch as `hasAc`. It is stored as `declaredAc`; `hasAc` in the
     response is the reviewer's value and stays `false` until review.
   - Do not send the Seat belts switch. There is no field for it.
7. **My vehicle card (V-10, V-11, V-14).**
   - Seats: show `approvedSeats`; hide the count while it is `null`.
   - "Submitted today": use `submittedAt`.
   - "Valid until": use `approvalValidUntil`; hide the line while it is `null`.
   - `status` can be `PENDING`, `APPROVED`, `REJECTED` or `SUSPENDED`. Show `rejectionReason`
     and `suspensionReason` when set.
8. **Replace vehicle (V-12, V-13).** The flow is: add the new car → it is reviewed while the
   current car keeps working → when it is `APPROVED`, the driver activates it, and only then are
   bookings cancelled.
   - While the new car is `PENDING`, show "new car under review, current car still active".
     Nothing is paused. Remove the "commutes are paused" state and rows.
   - Before `POST /driver/vehicles/{vehicle}/activate`, call
     `GET /driver/vehicles/replace-preview?vehicle={id}` and show two confirmations: the count
     first, the fee on the second. Do the same when switching back to the old car.
   - The driver may `PATCH` the new car and upload its documents while it is `PENDING` or
     `REJECTED`. The active car answers `409 VEHICLE_LOCKED`.

### Driver reliability

9. **Reliability list (R-1, R-4, R-5, R-11).** Read `GET /account/reliability`.
   - Group driver events that share a `groupId` into one row with a count
     ("Paused commute · 5 bookings canceled"). Events with `groupId: null` are single rows.
   - Use `cause` for the row title and `person.publicFirstName` for "Replaced Salma".
   - Use `cancelReason` for "car breakdown" on stopped trips.
   - `cause`, `groupId`, `person` and `cancelReason` can be `null` on older events; fall back to `kind`.
   - Show `fee.amountPiastres` for the fee line; hide it when `fee` is `null`.
   - A `counts: false` event is shown struck through, not hidden. An unknown `kind` is a plain row.
10. **Appeal (R-2, R-3, R-10, R-12).** One appeal covers the fee and the score.
    - Show the Appeal button when `canAppeal` is `true`. When it is `false`, `appealRefusal` says why.
    - Evidence tiles upload first with `POST /account/reliability/events/{event}/evidence`,
      then submit calls `POST /account/reliability/events/{event}/appeal`.
    - Appeal status screen: read `appeal.status`, `appeal.appealedAt`, `appeal.decidedAt` and
      `appeal.declineReasonCode` from the event. Translate the code in the app.
    - Second appeal: offer it when `appealsLeft` is `1` and `appeal.status` is `declined`. It
      needs a new upload first (`needsNewEvidence`). Show the deadline from `appealBy`.
    - When approved, `counts` becomes `false` and `fee.reversed` becomes `true`.
    - Do not call `POST /account/debt/entries/{entry}/appeal` from these screens.
11. **My ratings (R-6, R-7, R-8, R-9).**
    - Load each section with `?kind=completed_ride` and `?kind=canceled_booking`.
    - Reliability row stars: `meta.byKind.canceled_booking.average`.
    - Canceled-booking feedback is stars only. Remove the comment and "Report comment" from that card.
    - Date line: month only, for example "Oct 2026 · anonymous".
    - "Report comment" on ride reviews: one tap, no body needed. Show "Reported" from `reportedByMe`.

### Take to design

V-4 (second licence tile, three fields), V-6 (two fields or split), V-7 (seat-belt switch),
V-9 (seats field), V-13 (replace flow), R-7 (month only), R-8 (no comment on canceled-booking
feedback), and the missing loading, empty, error, offline, rejected and suspended states (V-15, R-13).

## 2. Fixed values

| Field | Values |
|---|---|
| `events[].cause` | `cancelled_booking` · `replacement` · `cancelled_day` · `pause` · `commute_deleted` · `vehicle_replaced` · `active_trip_cancelled` · `null` |
| `events[].cancelReason` | `breakdown` · `accident` · `emergency` · `other` · `null` |
| `appeal.status` | `pending` · `approved` · `declined` |
| `appeal.declineReasonCode` | `not_enough_evidence` · `evidence_does_not_match` · `within_member_control` · `other` · `null` |
| `appealRefusal` and `error.fields.appeal[0]` | `ALREADY_APPEALED` (one is pending) · `ALREADY_REVERSED` · `APPEAL_WINDOW_CLOSED` · `NEW_EVIDENCE_REQUIRED` · `NO_APPEALS_LEFT` |
| Evidence `kind` | `photo` · `screenshot` · `document` (use `document` for a receipt) |
| Rating `kind` | `completed_ride` · `canceled_booking` |
| Vehicle `status` | `PENDING` · `APPROVED` · `REJECTED` · `SUSPENDED` |
| Preview `reason` | `none` · `mid_notice` · `short_notice` |

Limits: evidence is jpeg, png or webp, up to 6 MB, 5 files per event. Appeal `reason` is 3–1000
characters. Each appeal round has a 48-hour window. `reversedReason` is always `null`; ignore it.

## 3. Responses

### `GET /account/consents`
```json
{
  "success": true,
  "data": {
    "currentVersions": {
      "terms": "1.0",
      "privacy": "1.0"
    },
    "outstanding": [],
    "biometric": {
      "version": "1.0",
      "acceptedAt": null
    }
  }
}
```

### `POST /account/consents` · body `{ "type": "biometric" }`
```json
{
  "success": true,
  "data": {
    "currentVersions": {
      "terms": "1.0",
      "privacy": "1.0"
    },
    "outstanding": [],
    "biometric": {
      "version": "1.0",
      "acceptedAt": "2026-09-26T05:00:00+00:00"
    }
  }
}
```

### `POST /driver/vehicles`
Body: `{ "make": "Kia", "model": "Sportage", "year": 2021, "colour": "white", "plateNumber": "س ن ر 4821", "seats": 5, "hasAc": true }` → `201`
```json
{
  "success": true,
  "data": {
    "id": "01m7br4sapt2jsv0dnkmxesrsp",
    "make": "Kia",
    "model": "Sportage",
    "year": 2021,
    "colour": "white",
    "plateNumber": "س ن ر 4821",
    "seats": 5,
    "approvedSeats": null,
    "tier": "standard",
    "hasAc": false,
    "declaredAc": true,
    "transmission": null,
    "fuelType": null,
    "isActive": false,
    "status": "PENDING",
    "suspensionReason": null,
    "registrationRenewalSubmittedAt": null,
    "rejectionReason": null,
    "submittedAt": "2026-09-26T05:00:00+00:00",
    "approvalValidUntil": null,
    "documents": []
  }
}
```

### `GET /driver/vehicles`
The current car, and a replacement under review.
```json
{
  "success": true,
  "data": [
    {
      "id": "01m7br4rpvzg5a33d0rpfdsw47",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2019,
      "colour": "Silver",
      "plateNumber": "ABC 1235",
      "seats": 5,
      "approvedSeats": 3,
      "tier": "standard",
      "hasAc": true,
      "declaredAc": null,
      "transmission": null,
      "fuelType": "petrol",
      "isActive": true,
      "status": "APPROVED",
      "suspensionReason": null,
      "registrationRenewalSubmittedAt": null,
      "rejectionReason": null,
      "submittedAt": "2026-09-26T05:00:00+00:00",
      "approvalValidUntil": null,
      "documents": [
        {
          "id": "01m7br4rr0yzfjeqsm51rg521k",
          "type": "registration",
          "status": "PENDING",
          "expiresAt": null
        }
      ]
    },
    {
      "id": "01m7br4sapt2jsv0dnkmxesrsp",
      "make": "Kia",
      "model": "Sportage",
      "year": 2021,
      "colour": "white",
      "plateNumber": "س ن ر 4821",
      "seats": 5,
      "approvedSeats": null,
      "tier": "standard",
      "hasAc": false,
      "declaredAc": true,
      "transmission": null,
      "fuelType": null,
      "isActive": false,
      "status": "PENDING",
      "suspensionReason": null,
      "registrationRenewalSubmittedAt": null,
      "rejectionReason": null,
      "submittedAt": "2026-09-26T05:00:00+00:00",
      "approvalValidUntil": null,
      "documents": [
        {
          "id": "01m7br4sbm53yw97p2hez0gzne",
          "type": "registration_front",
          "status": "PENDING",
          "expiresAt": null
        }
      ]
    }
  ]
}
```

### `PATCH /driver/vehicles/{vehicle}` on the active car → `409`
```json
{
  "success": false,
  "error": {
    "code": "VEHICLE_LOCKED",
    "message": "Your car is being reviewed and can't be changed right now. You can change it once the review is done.",
    "fields": null
  }
}
```

### `GET /driver/vehicles/replace-preview?vehicle={id}`
All counts are `0` and `perTrip` is empty when nothing would be cancelled.
```json
{
  "success": true,
  "data": {
    "affectedBookings": 1,
    "affectedCommutes": 1,
    "affectedSeats": 1,
    "feePiastres": 2500,
    "reason": "short_notice",
    "perTrip": [
      {
        "tripId": "01m7br4rxg26kjh81wv585xt0g",
        "departureAt": "2026-09-26T07:00:00+00:00",
        "seats": 1,
        "percent": 25,
        "feePiastres": 2500
      }
    ]
  }
}
```

### `GET /account/reliability`
After one pause that cancelled two bookings: two events, one `groupId`.
```json
{
  "success": true,
  "data": {
    "asDriver": {
      "score": 90,
      "events": [
        {
          "id": "01M7BR4SQCZ527N15FG9RTAPHY",
          "kind": "DRIVER_DISPLACED_PASSENGER",
          "weight": 5,
          "occurredAt": "2026-09-26T05:00:00+00:00",
          "bookingId": "01m7br4skyw6yx3fft4je51xgd",
          "tripId": "01m7br4rxg26kjh81wv585xt0g",
          "commuteId": "01m7br4rt4j85ybj9d24k7z33b",
          "cause": "pause",
          "groupId": "01M7BR4SPCXSC3XETWJW9JJB8M",
          "cancelReason": null,
          "person": {
            "userId": "01m7br4sdnjfv0kfhkkm3ykr7g",
            "publicFirstName": "سارة"
          },
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
          "fee": {
            "debtEntryId": "01m7br4sqq9wykth98yanz1bar",
            "amountPiastres": 3500,
            "reversed": false
          }
        },
        {
          "id": "01M7BR4SPRX1971VNH5196D1XD",
          "kind": "DRIVER_DISPLACED_PASSENGER",
          "weight": 5,
          "occurredAt": "2026-09-26T05:00:00+00:00",
          "bookingId": "01m7br4s8henyjgspe08wmrxt9",
          "tripId": "01m7br4rxg26kjh81wv585xt0g",
          "commuteId": "01m7br4rt4j85ybj9d24k7z33b",
          "cause": "pause",
          "groupId": "01M7BR4SPCXSC3XETWJW9JJB8M",
          "cancelReason": null,
          "person": {
            "userId": "01m7br4s1pp77nkg2w5r8k8c4s",
            "publicFirstName": "سارة"
          },
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
          "fee": {
            "debtEntryId": "01m7br4sq4vqx6z8q2cxvdkap3",
            "amountPiastres": 2500,
            "reversed": false
          }
        }
      ]
    },
    "asPassenger": {
      "score": 100,
      "events": []
    },
    "windowDays": 90,
    "perfectScore": 100
  }
}
```

### `POST /account/reliability/events/{event}/evidence` · multipart `kind`, `file` → `201`
```json
{
  "success": true,
  "data": {
    "id": "01m7br4st9cp03816axya62988",
    "kind": "photo",
    "purgeAfter": "2027-09-26",
    "createdAt": "2026-09-26T05:00:00+00:00"
  }
}
```

### `POST /account/reliability/events/{event}/appeal` · body `{ "reason": "..." }`
```json
{
  "success": true,
  "data": {
    "reliabilityEventId": "01M7BR4SPRX1971VNH5196D1XD",
    "appealedAt": "2026-09-26T05:00:00+00:00",
    "stillCounts": true,
    "appeal": {
      "id": "01m7br4sth1bn3e1f6hwzt44zf",
      "round": 1,
      "status": "pending",
      "appealedAt": "2026-09-26T05:00:00+00:00",
      "decidedAt": null,
      "declineReasonCode": null
    },
    "appealsLeft": 1
  }
}
```

### The same call while one is pending → `422`
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "appeal": [
        "ALREADY_APPEALED"
      ]
    }
  }
}
```

### The event in `GET /account/reliability` after the first appeal is declined
```json
{
  "id": "01M7BR4SPRX1971VNH5196D1XD",
  "kind": "DRIVER_DISPLACED_PASSENGER",
  "weight": 5,
  "occurredAt": "2026-09-26T05:00:00+00:00",
  "bookingId": "01m7br4s8henyjgspe08wmrxt9",
  "tripId": "01m7br4rxg26kjh81wv585xt0g",
  "commuteId": "01m7br4rt4j85ybj9d24k7z33b",
  "cause": "pause",
  "groupId": "01M7BR4SPCXSC3XETWJW9JJB8M",
  "cancelReason": null,
  "person": {
    "userId": "01m7br4s1pp77nkg2w5r8k8c4s",
    "publicFirstName": "سارة"
  },
  "counts": true,
  "reversedAt": null,
  "reversedReason": null,
  "canAppeal": false,
  "appealsLeft": 1,
  "needsNewEvidence": true,
  "appealBy": "2026-09-28T05:00:00+00:00",
  "appealRefusal": "NEW_EVIDENCE_REQUIRED",
  "evidence": [
    {
      "id": "01m7br4st9cp03816axya62988",
      "kind": "photo",
      "purgeAfter": "2027-09-26",
      "createdAt": "2026-09-26T05:00:00+00:00"
    }
  ],
  "appeal": {
    "id": "01m7br4sth1bn3e1f6hwzt44zf",
    "round": 1,
    "status": "declined",
    "appealedAt": "2026-09-26T05:00:00+00:00",
    "decidedAt": "2026-09-26T05:00:00+00:00",
    "declineReasonCode": "not_enough_evidence"
  },
  "fee": {
    "debtEntryId": "01m7br4sq4vqx6z8q2cxvdkap3",
    "amountPiastres": 2500,
    "reversed": false
  }
}
```

### Second appeal with no new upload → `422`
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "appeal": [
        "NEW_EVIDENCE_REQUIRED"
      ]
    }
  }
}
```

### Second appeal after a new upload
```json
{
  "success": true,
  "data": {
    "reliabilityEventId": "01M7BR4SPRX1971VNH5196D1XD",
    "appealedAt": "2026-09-26T05:00:00+00:00",
    "stillCounts": true,
    "appeal": {
      "id": "01m7br4syp7txkma8htd87597m",
      "round": 2,
      "status": "pending",
      "appealedAt": "2026-09-26T05:00:00+00:00",
      "decidedAt": null,
      "declineReasonCode": null
    },
    "appealsLeft": 0
  }
}
```

### The event after the appeal is approved
```json
{
  "id": "01M7BR4SPRX1971VNH5196D1XD",
  "kind": "DRIVER_DISPLACED_PASSENGER",
  "weight": 5,
  "occurredAt": "2026-09-26T05:00:00+00:00",
  "bookingId": "01m7br4s8henyjgspe08wmrxt9",
  "tripId": "01m7br4rxg26kjh81wv585xt0g",
  "commuteId": "01m7br4rt4j85ybj9d24k7z33b",
  "cause": "pause",
  "groupId": "01M7BR4SPCXSC3XETWJW9JJB8M",
  "cancelReason": null,
  "person": {
    "userId": "01m7br4s1pp77nkg2w5r8k8c4s",
    "publicFirstName": "سارة"
  },
  "counts": false,
  "reversedAt": "2026-09-26T05:00:00+00:00",
  "reversedReason": null,
  "canAppeal": false,
  "appealsLeft": 0,
  "needsNewEvidence": false,
  "appealBy": null,
  "appealRefusal": "ALREADY_REVERSED",
  "evidence": [
    {
      "id": "01m7br4st9cp03816axya62988",
      "kind": "photo",
      "purgeAfter": "2027-09-26",
      "createdAt": "2026-09-26T05:00:00+00:00"
    },
    {
      "id": "01m7br4syf0qfgzyzqsc05ptmc",
      "kind": "document",
      "purgeAfter": "2027-09-26",
      "createdAt": "2026-09-26T05:00:00+00:00"
    }
  ],
  "appeal": {
    "id": "01m7br4syp7txkma8htd87597m",
    "round": 2,
    "status": "approved",
    "appealedAt": "2026-09-26T05:00:00+00:00",
    "decidedAt": "2026-09-26T05:00:00+00:00",
    "declineReasonCode": null
  },
  "fee": {
    "debtEntryId": "01m7br4sq4vqx6z8q2cxvdkap3",
    "amountPiastres": 2500,
    "reversed": true
  }
}
```

### `GET /ratings/about-me`
```json
{
  "success": true,
  "data": [
    {
      "id": "01m7br4sn6x0m8va6jk9cz640m",
      "direction": "passenger_to_driver",
      "kind": "canceled_booking",
      "stars": 2,
      "comment": null,
      "tags": [],
      "month": "2026-09",
      "wasEdited": false,
      "reportedByMe": false
    },
    {
      "id": "01m7br4sd4cwpyf5hp7b31hqe9",
      "direction": "passenger_to_driver",
      "kind": "completed_ride",
      "stars": 4,
      "comment": "On time and friendly.",
      "tags": [],
      "month": "2026-09",
      "wasEdited": false,
      "reportedByMe": true
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false,
    "byKind": {
      "completed_ride": {
        "count": 1,
        "average": 4
      },
      "canceled_booking": {
        "count": 1,
        "average": 2
      }
    }
  }
}
```

### `GET /ratings/about-me?kind=canceled_booking`
```json
{
  "success": true,
  "data": [
    {
      "id": "01m7br4sn6x0m8va6jk9cz640m",
      "direction": "passenger_to_driver",
      "kind": "canceled_booking",
      "stars": 2,
      "comment": null,
      "tags": [],
      "month": "2026-09",
      "wasEdited": false,
      "reportedByMe": false
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "lastPage": 1,
    "hasMore": false,
    "byKind": {
      "completed_ride": {
        "count": 1,
        "average": 4
      },
      "canceled_booking": {
        "count": 1,
        "average": 2
      }
    }
  }
}
```

### `POST /ratings/{rating}/report` with no body → `201`
```json
{
  "success": true,
  "data": {
    "id": "01m7br4snex7cngn8ywv8n2g4a",
    "status": "PENDING",
    "reviewRemains": true
  }
}
```

### The same report again → `409`
```json
{
  "success": false,
  "error": {
    "code": "REVIEW_ALREADY_REPORTED",
    "message": "You have already reported this review.",
    "fields": null
  }
}
```

### Reporting canceled-booking feedback → `422`
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "rating": [
        "NOT_REPORTABLE"
      ]
    }
  }
}
```
