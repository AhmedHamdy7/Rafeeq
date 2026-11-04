# Driver Home: real responses

Full, unedited JSON from a running server. Nothing is masked or shortened.

- **Captured on:** a local server after seeding, with `Accept-Language: en`. Not captured on staging.
- **Code:** branch `fix/driver-home-gaps-2`, based on `6989a5a`.
- **Test accounts:** run `php artisan demo:driver-home-states`, or reset one account with `php artisan demo:driver-home-states --state=F3D-05 --phone=+201055557005`.
- **Every account was re-seeded just before its capture**, so times are relative to that moment. Ids differ on each server.
- **`NOT-STARTED` has no driver profile**, so its `GET /driver/application` is a real `404`.
- **`F3D-06`** is set directly rather than through the sweep, because the sweep would touch every trip on the server. Every other state with a real flow uses it: the licence, car and application rejections, the submit and approval, `RENEWAL-LICENCE` and both suspensions.

## States and phone numbers

| State | `--state` | Phone |
|---|---|---|
| F3D-01 scheduled, +130 min, people waiting, canStart false | `F3D-01` | `+201055557001` |
| F3D-01 inside the start window, canStart true | `F3D-01-CAN-START` | `+201055557016` |
| F3D-02 starting soon, +25 min | `F3D-02` | `+201055557002` |
| F3D-03 started, PREPARING | `F3D-03-PREPARING` | `+201055557003` |
| F3D-03 started, EN_ROUTE | `F3D-03-EN-ROUTE` | `+201055557017` |
| F3D-03 started, IN_PROGRESS | `F3D-03-IN-PROGRESS` | `+201055557018` |
| F3D-04 not started, +1 min | `F3D-04` | `+201055557004` |
| F3D-05 late, +10 min | `F3D-05` | `+201055557005` |
| F3D-06 expiredRun set, next commute later | `F3D-06` | `+201055557006` |
| F3D-07 seat_opened notice | `F3D-07` | `+201055557007` |
| F3D-08 passenger_switched, rejoinedWaitlist true | `F3D-08-REJOINED` | `+201055557008` |
| F3D-08 passenger_switched, rejoinedWaitlist false | `F3D-08-LEFT` | `+201055557009` |
| No promised arrival | `NO-PROMISE` | `+201055557021` |
| laterThisWeek with waitlistCount 0 and more than 0 | `LATER-WAITLIST` | `+201055557022` |
| Verified driver with no commutes | `NO-COMMUTES` | `+201055557023` |
| One person waiting on two days | `SAME-PERSON-TWO-DAYS` | `+201055557024` |
| No nextRun, with notices, requests and a waiting list | `NO-RUN-ACTIVITY` | `+201055557028` |
| not_started | `NOT-STARTED` | `+201055557201` |
| in_progress | `IN-PROGRESS` | `+201055557202` |
| Complete draft (missing: []) | `COMPLETE-DRAFT` | `+201055557203` |
| under_review | `UNDER-REVIEW` | `+201055557204` |
| verified, 5 of 5 | `VERIFIED` | `+201055557025` |
| expiring | `EXPIRING` | `+201055557013` |
| renewal_required, expired licence | `RENEWAL-LICENCE` | `+201055557014` |
| renewal_required, expired car registration | `RENEWAL-REGISTRATION` | `+201055557026` |
| action_required, rejected driving_licence | `REJECTED-LICENCE` | `+201055557205` |
| action_required, rejected vehicle_registration | `REJECTED-REGISTRATION` | `+201055557206` |
| action_required, rejected vehicle_details | `REJECTED-DETAILS` | `+201055557207` |
| action_required, whole application rejected | `REJECTED-APPLICATION` | `+201055557208` |
| suspended, with suspensionReason | `SUSPENDED` | `+201055557015` |
| Whole account suspended | `ACCOUNT-SUSPENDED` | `+201055557027` |

## Commute states

### F3D-01 scheduled, +130 min, people waiting, canStart false

- **State:** `F3D-01`
- **Phone:** `+201055557001`
- **Captured:** 2026-11-04 15:35:11 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Scheduled",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcg3356qsbhv2ejxnacxx",
      "tripId": "01m6jbcgmnprbdmxbtvarc2xsh",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 130,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jbch1yrxv9tjq14ytxzkmn",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jbcg3356qsbhv2ejxnacxx",
        "tripId": "01m6jbcgmp3vak5v0arqa1vb8j",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg3356qsbhv2ejxnacxx",
        "tripId": "01m6jbcgmrffesw1rnbtt33pg7",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg3356qsbhv2ejxnacxx",
        "tripId": "01m6jbcgmrffesw1rnbtt33pg8",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg3356qsbhv2ejxnacxx",
        "tripId": "01m6jbcgms0yxvqd8am7p1cqj6",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg3356qsbhv2ejxnacxx",
        "tripId": "01m6jbcgmtd11stkt2h5dyc5cg",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:10+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcg2x4am5mjsq2bb5ae25",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 700",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxvf5amr8x476yqpaje2h",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-01 inside the start window, canStart true

- **State:** `F3D-01-CAN-START`
- **Phone:** `+201055557016`
- **Captured:** 2026-11-04 15:35:12 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "F3d01canstart",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfxvhpvf0vg7vaxjd0fd3z",
      "tripId": "01m6jfxvn1r4jngqrttag4dbcy",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T16:35:00+00:00",
      "departureLocal": "2026-11-04 18:35:00",
      "departsInMinutes": 60,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T17:45:00+00:00",
      "promisedArrivalLocal": "2026-11-04 19:45:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": true,
      "startOpensAt": "2026-11-04T15:05:00+00:00",
      "startOpensLocal": "2026-11-04 17:05:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T16:40:00+00:00",
        "warningLocal": "2026-11-04 18:40:00",
        "lateAt": "2026-11-04T16:45:00+00:00",
        "lateLocal": "2026-11-04 18:45:00",
        "expiresAt": "2026-11-04T16:55:00+00:00",
        "expiresLocal": "2026-11-04 18:55:00"
      },
      "leaveBy": {
        "at": "2026-11-04T16:06:00+00:00",
        "local": "2026-11-04 18:06:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxvpdscb6ez3578ffk9hy",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfxvhpvf0vg7vaxjd0fd3z",
        "tripId": "01m6jfxvn27fa9nzg4kas5n8p3",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvhpvf0vg7vaxjd0fd3z",
        "tripId": "01m6jfxvn3wn97axaegf85yc69",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvhpvf0vg7vaxjd0fd3z",
        "tripId": "01m6jfxvn4mcqgx5bkzp1af9hh",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvhpvf0vg7vaxjd0fd3z",
        "tripId": "01m6jfxvn69xpmjea76jpr6xpc",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvhpvf0vg7vaxjd0fd3z",
        "tripId": "01m6jfxvn7rzh7r4wesygsf6ya",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:11+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfxvhg7e18zzs9n74pdpem",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7016",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxvnzb39shp15xtgf2rn1",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-02 starting soon, +25 min

- **State:** `F3D-02`
- **Phone:** `+201055557002`
- **Captured:** 2026-11-04 15:35:13 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Soon",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcg4hshemj96911j5yfew",
      "tripId": "01m6jbcgnjz3fm11nz94qfqz5s",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "starting_soon",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T16:00:00+00:00",
      "departureLocal": "2026-11-04 18:00:00",
      "departsInMinutes": 25,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T17:10:00+00:00",
      "promisedArrivalLocal": "2026-11-04 19:10:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": true,
      "startOpensAt": "2026-11-04T14:30:00+00:00",
      "startOpensLocal": "2026-11-04 16:30:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T16:05:00+00:00",
        "warningLocal": "2026-11-04 18:05:00",
        "lateAt": "2026-11-04T16:10:00+00:00",
        "lateLocal": "2026-11-04 18:10:00",
        "expiresAt": "2026-11-04T16:20:00+00:00",
        "expiresLocal": "2026-11-04 18:20:00"
      },
      "leaveBy": {
        "at": "2026-11-04T15:31:00+00:00",
        "local": "2026-11-04 17:31:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jbch2rr0gpr0aya852wvp1",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jbcg4hshemj96911j5yfew",
        "tripId": "01m6jbcgnk52t8nxtqyb46xgys",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg4hshemj96911j5yfew",
        "tripId": "01m6jbcgnmshr288fkv6yeshtv",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg4hshemj96911j5yfew",
        "tripId": "01m6jbcgnmshr288fkv6yeshtw",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg4hshemj96911j5yfew",
        "tripId": "01m6jbcgnn88pdxrv5pt2cnsv4",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg4hshemj96911j5yfew",
        "tripId": "01m6jbcgnpqmk6zyqdv2gf6aa8",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:13+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcg4g0gzrtcwxmr2xe5sk",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 701",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxvpz5zj0hxwea9e5mkre",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-03 started, PREPARING

- **State:** `F3D-03-PREPARING`
- **Phone:** `+201055557003`
- **Captured:** 2026-11-04 15:35:15 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Active",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcg5mx8n40t8sb10exzkp",
      "tripId": "01m6jbcgpfnecvqkb20jcp49r1",
      "groupId": null,
      "status": "PREPARING",
      "phase": "in_progress",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T15:27:00+00:00",
      "departureLocal": "2026-11-04 17:27:00",
      "departsInMinutes": -8,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T16:37:00+00:00",
      "promisedArrivalLocal": "2026-11-04 18:37:00",
      "startedAt": "2026-11-04T15:25:14+00:00",
      "startedLocal": "2026-11-04 17:25:14",
      "canStart": false,
      "startOpensAt": null,
      "startOpensLocal": null,
      "startDeadlines": null,
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jbch39v85wda5reybfqvhp",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jbcg5mx8n40t8sb10exzkp",
        "tripId": "01m6jbcgpgtyvy3vn6jatcj2f8",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg5mx8n40t8sb10exzkp",
        "tripId": "01m6jbcgpgtyvy3vn6jatcj2f9",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg5mx8n40t8sb10exzkp",
        "tripId": "01m6jbcgphxw37pf607fcgk2q8",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg5mx8n40t8sb10exzkp",
        "tripId": "01m6jbcgpjrfvvaqs89je2v1ee",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg5mx8n40t8sb10exzkp",
        "tripId": "01m6jbcgpk05fkjen5w1nya92r",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:14+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcg5j5n1jkn14edq48tgj",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 702",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxvr4j15z37310s1qqk1t",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-03 started, EN_ROUTE

- **State:** `F3D-03-EN-ROUTE`
- **Phone:** `+201055557017`
- **Captured:** 2026-11-04 15:35:16 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "F3d03enroute",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfxvsv7jx41c55fesbbksv",
      "tripId": "01m6jfxvwrvzrmsn2d91gfgv3b",
      "groupId": null,
      "status": "EN_ROUTE",
      "phase": "in_progress",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T15:27:00+00:00",
      "departureLocal": "2026-11-04 17:27:00",
      "departsInMinutes": -8,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T16:37:00+00:00",
      "promisedArrivalLocal": "2026-11-04 18:37:00",
      "startedAt": "2026-11-04T15:25:15+00:00",
      "startedLocal": "2026-11-04 17:25:15",
      "canStart": false,
      "startOpensAt": null,
      "startOpensLocal": null,
      "startDeadlines": null,
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxvy3rh4bqzwkv7zh3de7",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfxvsv7jx41c55fesbbksv",
        "tripId": "01m6jfxvwsh3syw50qkfkfbc3n",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvsv7jx41c55fesbbksv",
        "tripId": "01m6jfxvwtecdvp6se8vrgsgf1",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvsv7jx41c55fesbbksv",
        "tripId": "01m6jfxvwvf77v7je2x11fbxqe",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvsv7jx41c55fesbbksv",
        "tripId": "01m6jfxvwwt1njvgh59knkavs9",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvsv7jx41c55fesbbksv",
        "tripId": "01m6jfxvwx0hwtbfeentsqgg3m",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:15+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfxvsstgz6qv4px7g3b1fj",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7017",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxvxk994m4wk182xhsc59",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-03 started, IN_PROGRESS

- **State:** `F3D-03-IN-PROGRESS`
- **Phone:** `+201055557018`
- **Captured:** 2026-11-04 15:35:17 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "F3d03inprogress",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfxvz199q39k6t4q5jdwzr",
      "tripId": "01m6jfxw1kgbyt863h3t876sy5",
      "groupId": null,
      "status": "IN_PROGRESS",
      "phase": "in_progress",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T15:27:00+00:00",
      "departureLocal": "2026-11-04 17:27:00",
      "departsInMinutes": -8,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T16:37:00+00:00",
      "promisedArrivalLocal": "2026-11-04 18:37:00",
      "startedAt": "2026-11-04T15:25:17+00:00",
      "startedLocal": "2026-11-04 17:25:17",
      "canStart": false,
      "startOpensAt": null,
      "startOpensLocal": null,
      "startDeadlines": null,
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxw2v4ed52r934n7d6nve",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfxvz199q39k6t4q5jdwzr",
        "tripId": "01m6jfxw1mpyz1dkp98d7cpkpf",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvz199q39k6t4q5jdwzr",
        "tripId": "01m6jfxw1nb4er4xcyxwc7r1k6",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvz199q39k6t4q5jdwzr",
        "tripId": "01m6jfxw1pzbed19f3h289474t",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvz199q39k6t4q5jdwzr",
        "tripId": "01m6jfxw1qfbzatksqp314kecx",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxvz199q39k6t4q5jdwzr",
        "tripId": "01m6jfxw1r17jhmrfxvt5z0gr1",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:16+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfxvyzn9e0xz9p1s1bbrcq",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7018",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxw2dykrqr9t3e2j4j9ww",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-04 not started, +1 min

- **State:** `F3D-04`
- **Phone:** `+201055557004`
- **Captured:** 2026-11-04 15:35:19 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "NotStarted",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcg6mzvxqtcs1236h5www",
      "tripId": "01m6jbcgqdcxjemkqdssh5smjr",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "not_started",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T15:34:00+00:00",
      "departureLocal": "2026-11-04 17:34:00",
      "departsInMinutes": -1,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T16:44:00+00:00",
      "promisedArrivalLocal": "2026-11-04 18:44:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": true,
      "startOpensAt": "2026-11-04T14:04:00+00:00",
      "startOpensLocal": "2026-11-04 16:04:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T15:39:00+00:00",
        "warningLocal": "2026-11-04 17:39:00",
        "lateAt": "2026-11-04T15:44:00+00:00",
        "lateLocal": "2026-11-04 17:44:00",
        "expiresAt": "2026-11-04T15:54:00+00:00",
        "expiresLocal": "2026-11-04 17:54:00"
      },
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jbch3y3jcntahn3dv7yt4e",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jbcg6mzvxqtcs1236h5www",
        "tripId": "01m6jbcgqee382y4m4ke2z9mn9",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg6mzvxqtcs1236h5www",
        "tripId": "01m6jbcgqee382y4m4ke2z9mna",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg6mzvxqtcs1236h5www",
        "tripId": "01m6jbcgqfxh8d8web27nhg0nr",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg6mzvxqtcs1236h5www",
        "tripId": "01m6jbcgqgy7e8f6j6n284pwkj",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg6mzvxqtcs1236h5www",
        "tripId": "01m6jbcgqhyek3tdjs5pwf28qr",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:18+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcg6kw9zdqfffn4ewwj2j",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 703",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxw3gzndcyfqgv6z8zqgc",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-05 late, +10 min

- **State:** `F3D-05`
- **Phone:** `+201055557005`
- **Captured:** 2026-11-04 15:35:20 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Late",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcg7qtww9thrkh3ba2hb6",
      "tripId": "01m6jbcgraa771k4cvvt5vht48",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "late",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T15:25:00+00:00",
      "departureLocal": "2026-11-04 17:25:00",
      "departsInMinutes": -10,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T16:35:00+00:00",
      "promisedArrivalLocal": "2026-11-04 18:35:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": true,
      "startOpensAt": "2026-11-04T13:55:00+00:00",
      "startOpensLocal": "2026-11-04 15:55:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T15:30:00+00:00",
        "warningLocal": "2026-11-04 17:30:00",
        "lateAt": "2026-11-04T15:35:00+00:00",
        "lateLocal": "2026-11-04 17:35:00",
        "expiresAt": "2026-11-04T15:45:00+00:00",
        "expiresLocal": "2026-11-04 17:45:00"
      },
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 2,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jbch4fqr02sbxj8xv11t9b",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jbcg7qtww9thrkh3ba2hb6",
        "tripId": "01m6jbcgrb00j2b5pr3a8km7sg",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg7qtww9thrkh3ba2hb6",
        "tripId": "01m6jbcgrb00j2b5pr3a8km7sh",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg7qtww9thrkh3ba2hb6",
        "tripId": "01m6jbcgrc5580j0rq3440yx7n",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg7qtww9thrkh3ba2hb6",
        "tripId": "01m6jbcgrd07zts4r6067pa9fm",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg7qtww9thrkh3ba2hb6",
        "tripId": "01m6jbcgressp7spmbaj7r46x9",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:19+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcg7nd7p7hgtj60cazpjm",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 704",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxw4hq2a6yr28p1x97fdz",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-06 expiredRun set, next commute later

- **State:** `F3D-06`
- **Phone:** `+201055557006`
- **Captured:** 2026-11-04 15:35:21 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Expired",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": {
      "tripId": "01m6jbcgs87vgxr1ej5s6qk1as",
      "commuteId": "01m6jbcg8qkk646emk0nc9g2z4",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T15:05:00+00:00",
      "departureLocal": "2026-11-04 17:05:00",
      "expiredAt": "2026-11-04T15:25:20+00:00",
      "expiredLocal": "2026-11-04 17:25:20"
    },
    "nextRun": {
      "commuteId": "01m6jbcg8qkk646emk0nc9g2z4",
      "tripId": "01m6jbcgs9vtbe61d3m1atvx6a",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-05T05:00:00+00:00",
      "departureLocal": "2026-11-05 07:00:00",
      "departsInMinutes": 805,
      "isToday": false,
      "promisedArrivalAt": "2026-11-05T06:10:00+00:00",
      "promisedArrivalLocal": "2026-11-05 08:10:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-05T03:30:00+00:00",
      "startOpensLocal": "2026-11-05 05:30:00",
      "startDeadlines": null,
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsOpen": 3,
      "waitlistCount": 1,
      "collectPiastres": 0,
      "keepPiastres": 0,
      "passengers": []
    },
    "laterThisWeek": [
      {
        "commuteId": "01m6jbcg8qkk646emk0nc9g2z4",
        "tripId": "01m6jbcgsaaqzf0s7sfcrryt58",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg8qkk646emk0nc9g2z4",
        "tripId": "01m6jbcgsaaqzf0s7sfcrryt59",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg8qkk646emk0nc9g2z4",
        "tripId": "01m6jbcgsbp1jtm3gbb7btkzer",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg8qkk646emk0nc9g2z4",
        "tripId": "01m6jbcgscxp86qtpsqx1fdzdf",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:20+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcg8n7eh9ce29m2n6kh4q",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 705",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxw5msmhjhk5kvv3b4qr2",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-07 seat_opened notice

- **State:** `F3D-07`
- **Phone:** `+201055557007`
- **Captured:** 2026-11-04 15:35:22 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "SeatOpened",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcg9mve9hpa2k5dgkc76b",
      "tripId": "01m6jbcgt7ab821k8vxyb2q2jn",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 130,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsOpen": 3,
      "waitlistCount": 2,
      "collectPiastres": 0,
      "keepPiastres": 0,
      "passengers": []
    },
    "laterThisWeek": [
      {
        "commuteId": "01m6jbcg9mve9hpa2k5dgkc76b",
        "tripId": "01m6jbcgt8kr2pn9zqpc7cjyms",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg9mve9hpa2k5dgkc76b",
        "tripId": "01m6jbcgt9jkg8qt8fv7ddv07w",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg9mve9hpa2k5dgkc76b",
        "tripId": "01m6jbcgtanmpb3kzqm3amcn3e",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg9mve9hpa2k5dgkc76b",
        "tripId": "01m6jbcgtbh46s0fh2agkszyrh",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcg9mve9hpa2k5dgkc76b",
        "tripId": "01m6jbcgtczsyfnynm08babwk3",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [
      {
        "kind": "seat_opened",
        "rejoinedWaitlist": false,
        "bookingId": "01m6jbch5ppv00j9qvx56q22az",
        "tripId": "01m6jbcgt7ab821k8vxyb2q2jn",
        "commuteId": "01m6jbcg9mve9hpa2k5dgkc76b",
        "person": {
          "publicFirstName": "ليلى",
          "trustLevel": 2,
          "verifiedLevels": [],
          "sameOrganisation": false,
          "rating": null,
          "ratingCount": 0,
          "completedTrips": 0,
          "onTimeRate": null
        },
        "seatsReleased": 1,
        "seatsOpen": 3,
        "departureAt": "2026-11-04T17:45:00+00:00",
        "departureLocal": "2026-11-04 19:45:00",
        "at": "2026-11-04T15:35:22+00:00"
      }
    ],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:22+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcg9jqmgew200bt69wt86",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 706",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxw6v33qc4aweda2h77ch",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-08 passenger_switched, rejoinedWaitlist true

- **State:** `F3D-08-REJOINED`
- **Phone:** `+201055557008`
- **Captured:** 2026-11-04 15:35:24 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Rejoined",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcgax44tw0bktq1zdj1xr",
      "tripId": "01m6jbcgv5zczpykbbp8vsrxyy",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 130,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsOpen": 3,
      "waitlistCount": 3,
      "collectPiastres": 0,
      "keepPiastres": 0,
      "passengers": []
    },
    "laterThisWeek": [
      {
        "commuteId": "01m6jbcgax44tw0bktq1zdj1xr",
        "tripId": "01m6jbcgv6ersypbczrkm32d52",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgax44tw0bktq1zdj1xr",
        "tripId": "01m6jbcgv6ersypbczrkm32d53",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgax44tw0bktq1zdj1xr",
        "tripId": "01m6jbcgv76zc1qqpkqqv7xfqy",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgax44tw0bktq1zdj1xr",
        "tripId": "01m6jbcgv80hxg59z6tpk923az",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgax44tw0bktq1zdj1xr",
        "tripId": "01m6jbcgv91mc1kqq0hemqgq12",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [
      {
        "kind": "passenger_switched",
        "rejoinedWaitlist": true,
        "bookingId": "01m6jbch6b2g9kyjgzc062afm7",
        "tripId": "01m6jbcgv5zczpykbbp8vsrxyy",
        "commuteId": "01m6jbcgax44tw0bktq1zdj1xr",
        "person": {
          "publicFirstName": "ليلى",
          "trustLevel": 2,
          "verifiedLevels": [],
          "sameOrganisation": false,
          "rating": null,
          "ratingCount": 0,
          "completedTrips": 0,
          "onTimeRate": null
        },
        "seatsReleased": 1,
        "seatsOpen": 3,
        "departureAt": "2026-11-04T17:45:00+00:00",
        "departureLocal": "2026-11-04 19:45:00",
        "at": "2026-11-04T15:35:23+00:00"
      }
    ],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 3
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:23+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcgav9xjesa6b9md3kcfy",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 707",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxw80ccshrrkawkahjvyq",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### F3D-08 passenger_switched, rejoinedWaitlist false

- **State:** `F3D-08-LEFT`
- **Phone:** `+201055557009`
- **Captured:** 2026-11-04 15:35:25 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Left",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcgc8ptj83y13j7yfmy1p",
      "tripId": "01m6jbcgw1vnp3rwvdj2q1arem",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 130,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsOpen": 3,
      "waitlistCount": 2,
      "collectPiastres": 0,
      "keepPiastres": 0,
      "passengers": []
    },
    "laterThisWeek": [
      {
        "commuteId": "01m6jbcgc8ptj83y13j7yfmy1p",
        "tripId": "01m6jbcgw27y6cg1bn0rfra374",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgc8ptj83y13j7yfmy1p",
        "tripId": "01m6jbcgw27y6cg1bn0rfra375",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgc8ptj83y13j7yfmy1p",
        "tripId": "01m6jbcgw3antz1may2dq7barj",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgc8ptj83y13j7yfmy1p",
        "tripId": "01m6jbcgw4b5bkf5n0070br8e4",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jbcgc8ptj83y13j7yfmy1p",
        "tripId": "01m6jbcgw56jw3zveq0ztdp2by",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [
      {
        "kind": "passenger_switched",
        "rejoinedWaitlist": false,
        "bookingId": "01m6jbch73xjbqa00sr4epms7r",
        "tripId": "01m6jbcgw1vnp3rwvdj2q1arem",
        "commuteId": "01m6jbcgc8ptj83y13j7yfmy1p",
        "person": {
          "publicFirstName": "ليلى",
          "trustLevel": 2,
          "verifiedLevels": [],
          "sameOrganisation": false,
          "rating": null,
          "ratingCount": 0,
          "completedTrips": 0,
          "onTimeRate": null
        },
        "seatsReleased": 1,
        "seatsOpen": 3,
        "departureAt": "2026-11-04T17:45:00+00:00",
        "departureLocal": "2026-11-04 19:45:00",
        "at": "2026-11-04T15:35:24+00:00"
      }
    ],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:24+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcgc69fgchr4qq9hd0fxc",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 708",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxw97vgwfh53me6tkkff8",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

## Variations

### No promised arrival

- **State:** `NO-PROMISE`
- **Phone:** `+201055557021`
- **Captured:** 2026-11-04 15:35:26 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Nopromise",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfxwascap095bm5bj5ajcf",
      "tripId": "01m6jfxwdac7xfzb5401f8yqxk",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 130,
      "isToday": true,
      "promisedArrivalAt": null,
      "promisedArrivalLocal": null,
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": null,
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 0,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxweg0p6b9xf4vze4331a",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfxwascap095bm5bj5ajcf",
        "tripId": "01m6jfxwdac7xfzb5401f8yqxm",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxwascap095bm5bj5ajcf",
        "tripId": "01m6jfxwdbzbmed4dfz5aytsv1",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxwascap095bm5bj5ajcf",
        "tripId": "01m6jfxwdcdar6ftrrspasq1rz",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxwascap095bm5bj5ajcf",
        "tripId": "01m6jfxwddmn1nsbtnqnyc5mfr",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxwascap095bm5bj5ajcf",
        "tripId": "01m6jfxwddmn1nsbtnqnyc5mfs",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:25+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfxwaqjj61kmdfr81z2393",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7021",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxwe3wfdgt40n8rhbnvye",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### laterThisWeek with waitlistCount 0 and more than 0

- **State:** `LATER-WAITLIST`
- **Phone:** `+201055557022`
- **Captured:** 2026-11-04 15:35:28 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Laterwaitlist",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfxwf7g8mkcfq69tabgtp2",
      "tripId": "01m6jfxwj75215qrw0f6paeb35",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 130,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 0,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxwkg7nbvxshxqtqvehya",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfxwf7g8mkcfq69tabgtp2",
        "tripId": "01m6jfxwj8z9a9eg3qd45fs0ny",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxwf7g8mkcfq69tabgtp2",
        "tripId": "01m6jfxwj96359d0bqmzwsghb6",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxwf7g8mkcfq69tabgtp2",
        "tripId": "01m6jfxwj96359d0bqmzwsghb7",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxwf7g8mkcfq69tabgtp2",
        "tripId": "01m6jfxwjapn4c4jmzvzp2w5f9",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxwf7g8mkcfq69tabgtp2",
        "tripId": "01m6jfxwjbdwnkb84p98tbevkq",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 1
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:27+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfxwf59snnhg2vf2v4k602",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7022",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxwk2r3wr1tq46yhak6kv",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### Verified driver with no commutes

- **State:** `NO-COMMUTES`
- **Phone:** `+201055557023`
- **Captured:** 2026-11-04 15:35:29 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Nocommutes",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:28+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfzhjb75fyqvyybzn13pqq",
        "make": "Hyundai",
        "model": "Elantra",
        "year": 2021,
        "colour": "فضي",
        "plateNumber": "DH 7023",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfzhjfwn2jd9pgarya8bvp",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### One person waiting on two days

- **State:** `SAME-PERSON-TWO-DAYS`
- **Phone:** `+201055557024`
- **Captured:** 2026-11-04 15:35:30 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Samepersontwodays",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfxwm8pmjyfcd61se1bm1k",
      "tripId": "01m6jfxwpzbnnwwg05xc383g5v",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 129,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 1,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxwrb696wg3em9wfhmdjy",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfxwm8pmjyfcd61se1bm1k",
        "tripId": "01m6jfxwq0pgdd42zc2cmgcebc",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxwm8pmjyfcd61se1bm1k",
        "tripId": "01m6jfxwq1z9qmkvmebn9hdmyh",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxwm8pmjyfcd61se1bm1k",
        "tripId": "01m6jfxwq2drj8ep1rmznbham4",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxwm8pmjyfcd61se1bm1k",
        "tripId": "01m6jfxwq375xffsag616mq1q8",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      },
      {
        "commuteId": "01m6jfxwm8pmjyfcd61se1bm1k",
        "tripId": "01m6jfxwq4dqte4sjs59bkcfnh",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 1
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 1
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:29+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfxwm6fjn698eax8sdx215",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7024",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxwqvex5zj4ptfsmnz72v",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### No nextRun, with notices, requests and a waiting list

- **State:** `NO-RUN-ACTIVITY`
- **Phone:** `+201055557028`
- **Captured:** 2026-11-04 15:35:31 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Norunactivity",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [
      {
        "kind": "seat_opened",
        "rejoinedWaitlist": false,
        "bookingId": "01m6keyesdrtnw0c0bm03azxdx",
        "tripId": "01m6keyep76qbrtsk1xrvt3060",
        "commuteId": "01m6keyejgp45ft0jg7h6sppxn",
        "person": {
          "publicFirstName": "ليلى",
          "trustLevel": 2,
          "verifiedLevels": [],
          "sameOrganisation": false,
          "rating": null,
          "ratingCount": 0,
          "completedTrips": 0,
          "onTimeRate": null
        },
        "seatsReleased": 1,
        "seatsOpen": 3,
        "departureAt": "2026-11-04T17:45:00+00:00",
        "departureLocal": "2026-11-04 19:45:00",
        "at": "2026-11-04T15:35:30+00:00"
      }
    ],
    "pendingRequests": {
      "count": 1,
      "items": [
        {
          "id": "01m6kk6ydvphr0h2saycgjg260",
          "commuteId": "01m6keyejgp45ft0jg7h6sppxn",
          "tripId": "01m6keyep76qbrtsk1xrvt3060",
          "status": "PENDING",
          "commitment": "trial",
          "kind": "seat_request",
          "hasOtherConfirmedCommute": {},
          "requestedDuringRide": false,
          "requestedDaysMask": null,
          "seats": 1,
          "meetingPreference": "gate",
          "pickup": {
            "lat": 30.0624,
            "lng": 31.3614,
            "label": "Ring Road, Rehab exit",
            "source": "custom_map",
            "suggestionId": null,
            "estimatedAt": null,
            "estimatedLocal": null
          },
          "dropoff": {
            "lat": 30.0684,
            "lng": 31.1015,
            "label": "Mehwar, Sheikh Zayed",
            "source": "custom_map",
            "suggestionId": null,
            "estimatedArrivalAt": null,
            "estimatedArrivalLocal": null,
            "promisedArrivalLocal": null
          },
          "counteroffer": {},
          "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
          "paymentType": "cash",
          "agreedToRulesAt": "2026-11-04T15:35:31+00:00",
          "waitlistPosition": null,
          "availableToday": true,
          "responseNote": null,
          "respondedAt": null,
          "pausedAt": null,
          "pausedUntil": null,
          "joinedWaitlistAt": null,
          "expiresAt": null,
          "createdAt": "2026-11-04T15:35:31+00:00",
          "passenger": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
            "sameOrganisation": false,
            "rating": null,
            "ratingCount": 0,
            "completedTrips": 0,
            "onTimeRate": null
          },
          "commute": {}
        }
      ]
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 1,
    "pausedCommuteReasons": [
      "by_driver"
    ],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:30+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6keyejam6a7dkmy715fx8qp",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7028",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6keyeq8ct6zwcw4jstb52ck",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

## Verification states

### not_started

- **State:** `NOT-STARTED`
- **Phone:** `+201055557201`
- **Captured:** 2026-11-04 15:35:33 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Notstarted",
    "verification": {
      "overall": "not_started",
      "completed": 0,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "not_started",
    "completed": 0,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_details",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `404`

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

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### in_progress

- **State:** `IN-PROGRESS`
- **Phone:** `+201055557202`
- **Captured:** 2026-11-04 15:35:34 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Inprogress",
    "verification": {
      "overall": "in_progress",
      "completed": 2,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "in_progress",
    "completed": 2,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_details",
        "status": "not_started",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "DRAFT",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": null,
    "rejectionReason": null,
    "missing": [
      "licence_front",
      "licence_back",
      "vehicle"
    ],
    "vehicles": []
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### Complete draft (missing: [])

- **State:** `COMPLETE-DRAFT`
- **Phone:** `+201055557203`
- **Captured:** 2026-11-04 15:35:35 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Completedraft",
    "verification": {
      "overall": "in_progress",
      "completed": 2,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "in_progress",
    "completed": 2,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "DRAFT",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": null,
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfzhmymgv7b7zmcg09q5vs",
        "make": "Chevrolet",
        "model": "Sportage",
        "year": 2023,
        "colour": "أسود",
        "plateNumber": "DH 7203",
        "seats": 6,
        "approvedSeats": null,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "PENDING",
        "documents": [
          {
            "id": "01m6jfzhn1g8kctqy18a1exxfh",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### under_review

- **State:** `UNDER-REVIEW`
- **Phone:** `+201055557204`
- **Captured:** 2026-11-04 15:35:36 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Underreview",
    "verification": {
      "overall": "under_review",
      "completed": 2,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "under_review",
    "completed": 2,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "under_review",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "under_review",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "under_review",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "PENDING_REVIEW",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": null,
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfzhntf1gd1emy28z3a534",
        "make": "Kia",
        "model": "Sportage",
        "year": 2024,
        "colour": "أزرق",
        "plateNumber": "DH 7204",
        "seats": 5,
        "approvedSeats": null,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "PENDING",
        "documents": [
          {
            "id": "01m6jfzhnys2yns83ehq643jsd",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### verified, 5 of 5

- **State:** `VERIFIED`
- **Phone:** `+201055557025`
- **Captured:** 2026-11-04 15:35:37 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Verified",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfxws4gkb7ahbkmt6barrq",
      "tripId": "01m6jfxww1wmhv3fgk2d9hh98b",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 129,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 0,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxwx9y3cq84dv6qbmsqpy",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfxws4gkb7ahbkmt6barrq",
        "tripId": "01m6jfxww2kg4erc4bagf99s03",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxws4gkb7ahbkmt6barrq",
        "tripId": "01m6jfxww3k64fd9qy4a8zh87g",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxws4gkb7ahbkmt6barrq",
        "tripId": "01m6jfxww41d6qwp3negty9c6r",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxws4gkb7ahbkmt6barrq",
        "tripId": "01m6jfxww55baqget26gt9t79q",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfxws4gkb7ahbkmt6barrq",
        "tripId": "01m6jfxww69f4fn5cdbtypag23",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:37+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfxws2jb73gy65d0wq67h1",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7025",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxwwvqev6430a6b4xfzsh",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### expiring

- **State:** `EXPIRING`
- **Phone:** `+201055557013`
- **Captured:** 2026-11-04 15:35:39 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Expiring",
    "verification": {
      "overall": "expiring",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2026-11-14T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jbcgf55sjsn0whzk9srd0k",
      "tripId": "01m6jbcgyr6vj3stfn6xga3eq2",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 129,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 0,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfxwy4z1v7m9f7hxbbp0d5",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jbcgf55sjsn0whzk9srd0k",
        "tripId": "01m6jbcgyshc6wxb20rh1knme1",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jbcgf55sjsn0whzk9srd0k",
        "tripId": "01m6jbcgyt69ngdy8hvfp4fq1x",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jbcgf55sjsn0whzk9srd0k",
        "tripId": "01m6jbcgyv5e309bb6vkpn3asp",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jbcgf55sjsn0whzk9srd0k",
        "tripId": "01m6jbcgyv5e309bb6vkpn3asq",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jbcgf55sjsn0whzk9srd0k",
        "tripId": "01m6jbcgyww30vcraj57v82jvc",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "expiring",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2026-11-14T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2026-11-14T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2026-11-14",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:38+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcgf3es58zv80j7haj14z",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 711",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxwxpkpnn5tn3ccx6tr43",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### renewal_required, expired licence

- **State:** `RENEWAL-LICENCE`
- **Phone:** `+201055557014`
- **Captured:** 2026-11-04 15:35:40 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Renewal",
    "verification": {
      "overall": "renewal_required",
      "completed": 4,
      "of": 5,
      "licenceExpiresAt": "2026-11-03T00:00:00+00:00",
      "actionItem": {
        "key": "driving_licence",
        "status": "expired",
        "rejectionReason": null
      },
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 1,
    "pausedCommuteReasons": [
      "licence_expired"
    ],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "renewal_required",
    "completed": 4,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "expired",
        "rejectionReason": null,
        "expiresAt": "2026-11-03T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2026-11-03T00:00:00+00:00",
    "actionItem": {
      "key": "driving_licence",
      "status": "expired",
      "rejectionReason": null
    },
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "EXPIRED_DOCUMENTS",
    "licenceExpiry": "2026-11-03",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:39+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcgg1ec9qjew25202gs0n",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 712",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfxwyn2e65vgjm2ke6yx7p",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### renewal_required, expired car registration

- **State:** `RENEWAL-REGISTRATION`
- **Phone:** `+201055557026`
- **Captured:** 2026-11-04 15:35:41 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Renewalregistration",
    "verification": {
      "overall": "renewal_required",
      "completed": 4,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": {
        "key": "vehicle_registration",
        "status": "expired",
        "rejectionReason": null
      },
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": {
      "commuteId": "01m6jfzh4jnbs36zya7j761hx8",
      "tripId": "01m6jfzh7r6k4r4akq9cr6xzgs",
      "groupId": null,
      "status": "SCHEDULED",
      "phase": "scheduled",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-04T17:45:00+00:00",
      "departureLocal": "2026-11-04 19:45:00",
      "departsInMinutes": 129,
      "isToday": true,
      "promisedArrivalAt": "2026-11-04T18:55:00+00:00",
      "promisedArrivalLocal": "2026-11-04 20:55:00",
      "startedAt": null,
      "startedLocal": null,
      "canStart": false,
      "startOpensAt": "2026-11-04T16:15:00+00:00",
      "startOpensLocal": "2026-11-04 18:15:00",
      "startDeadlines": {
        "warningAt": "2026-11-04T17:50:00+00:00",
        "warningLocal": "2026-11-04 19:50:00",
        "lateAt": "2026-11-04T17:55:00+00:00",
        "lateLocal": "2026-11-04 19:55:00",
        "expiresAt": "2026-11-04T18:05:00+00:00",
        "expiresLocal": "2026-11-04 20:05:00"
      },
      "leaveBy": {
        "at": "2026-11-04T17:16:00+00:00",
        "local": "2026-11-04 19:16:00",
        "lateWarning": true
      },
      "attendance": {
        "coming": 0,
        "away": 0,
        "awaiting": 0,
        "total": 0
      },
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsOpen": 2,
      "waitlistCount": 0,
      "collectPiastres": 12500,
      "keepPiastres": 11551,
      "passengers": [
        {
          "bookingId": "01m6jfzh915vf5ny44d5pcz60m",
          "person": {
            "publicFirstName": "ليلى",
            "trustLevel": 2,
            "verifiedLevels": [],
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
        "commuteId": "01m6jfzh4jnbs36zya7j761hx8",
        "tripId": "01m6jfzh7sey96004qeynamv49",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-05T05:00:00+00:00",
        "departureLocal": "2026-11-05 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfzh4jnbs36zya7j761hx8",
        "tripId": "01m6jfzh7tn28p2pptb608cr8t",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-08T05:00:00+00:00",
        "departureLocal": "2026-11-08 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfzh4jnbs36zya7j761hx8",
        "tripId": "01m6jfzh7v7yrw2x22vw66trh9",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-09T05:00:00+00:00",
        "departureLocal": "2026-11-09 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfzh4jnbs36zya7j761hx8",
        "tripId": "01m6jfzh7wmby6k5y2y9h89esb",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-10T05:00:00+00:00",
        "departureLocal": "2026-11-10 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      },
      {
        "commuteId": "01m6jfzh4jnbs36zya7j761hx8",
        "tripId": "01m6jfzh7xrg1g75m8agdkh2tr",
        "originLabel": "Rehab Gate 2",
        "destinationLabel": "Smart Village B6",
        "departureAt": "2026-11-11T05:00:00+00:00",
        "departureLocal": "2026-11-11 07:00:00",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsOpen": 3,
        "waitlistCount": 0
      }
    ],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "renewal_required",
    "completed": 4,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "expired",
        "rejectionReason": null,
        "expiresAt": "2026-11-03T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": {
      "key": "vehicle_registration",
      "status": "expired",
      "rejectionReason": null
    },
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:40+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfzh4d9za1q2zzyfjkr9ye",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7026",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfzh8k5bhvyeqyjnp0dd5q",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2026-11-03"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

### action_required, rejected driving_licence

- **State:** `REJECTED-LICENCE`
- **Phone:** `+201055557205`
- **Captured:** 2026-11-04 15:35:43 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Rejectedlicence",
    "verification": {
      "overall": "action_required",
      "completed": 2,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": {
        "key": "driving_licence",
        "status": "action_needed",
        "rejectionReason": "The licence photo is too blurry to read. Please upload it again."
      },
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "action_required",
    "completed": 2,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "action_needed",
        "rejectionReason": "The licence photo is too blurry to read. Please upload it again.",
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "under_review",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "under_review",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": {
      "key": "driving_licence",
      "status": "action_needed",
      "rejectionReason": "The licence photo is too blurry to read. Please upload it again."
    },
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "PENDING_REVIEW",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": null,
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfzhq2xcd0njd65fdkn16m",
        "make": "Chevrolet",
        "model": "Optra",
        "year": 2016,
        "colour": "فضي",
        "plateNumber": "DH 7205",
        "seats": 5,
        "approvedSeats": null,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "PENDING",
        "documents": [
          {
            "id": "01m6jfzhq6pdkkpfvwehdr583c",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### action_required, rejected vehicle_registration

- **State:** `REJECTED-REGISTRATION`
- **Phone:** `+201055557206`
- **Captured:** 2026-11-04 15:35:44 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Rejectedregistration",
    "verification": {
      "overall": "action_required",
      "completed": 2,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": {
        "key": "vehicle_registration",
        "status": "action_needed",
        "rejectionReason": "The registration photo is too blurry to read."
      },
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "action_required",
    "completed": 2,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "action_needed",
        "rejectionReason": "The registration photo is too blurry to read.",
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": {
      "key": "vehicle_registration",
      "status": "action_needed",
      "rejectionReason": "The registration photo is too blurry to read."
    },
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "REJECTED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": null,
    "rejectionReason": "The registration photo is too blurry to read.",
    "missing": [
      "vehicle_registration"
    ],
    "vehicles": [
      {
        "id": "01m6jfzhrm7vz6nnkjx3a483cm",
        "make": "Nissan",
        "model": "Sportage",
        "year": 2018,
        "colour": "فضي",
        "plateNumber": "DH 7206",
        "seats": 6,
        "approvedSeats": null,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "REJECTED",
        "documents": [
          {
            "id": "01m6jfzhrrw0rqk61c1g1bnhv5",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### action_required, rejected vehicle_details

- **State:** `REJECTED-DETAILS`
- **Phone:** `+201055557207`
- **Captured:** 2026-11-04 15:35:45 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Rejecteddetails",
    "verification": {
      "overall": "action_required",
      "completed": 2,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": {
        "key": "vehicle_details",
        "status": "action_needed",
        "rejectionReason": "The plate number does not match the registration."
      },
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "action_required",
    "completed": 2,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "action_needed",
        "rejectionReason": "The plate number does not match the registration.",
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": {
      "key": "vehicle_details",
      "status": "action_needed",
      "rejectionReason": "The plate number does not match the registration."
    },
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "REJECTED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": null,
    "rejectionReason": "The plate number does not match the registration.",
    "missing": [
      "vehicle_details"
    ],
    "vehicles": [
      {
        "id": "01m6jfzht42ndzkdvh4sbm0yjb",
        "make": "Hyundai",
        "model": "Optra",
        "year": 2020,
        "colour": "أزرق",
        "plateNumber": "DH 7207",
        "seats": 4,
        "approvedSeats": null,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "REJECTED",
        "documents": [
          {
            "id": "01m6jfzht8cchn8qgsnwm5em02",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### action_required, whole application rejected

- **State:** `REJECTED-APPLICATION`
- **Phone:** `+201055557208`
- **Captured:** 2026-11-04 15:35:46 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Rejectedapplication",
    "verification": {
      "overall": "action_required",
      "completed": 2,
      "of": 5,
      "licenceExpiresAt": null,
      "actionItem": {
        "key": "application",
        "status": "action_needed",
        "rejectionReason": "The documents do not belong to the same person."
      },
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 0,
    "pausedCommuteReasons": [],
    "stats": {
      "onTimeRate": null,
      "completedTrips": 0,
      "avgDetourMinutes": null
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "action_required",
    "completed": 2,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "vehicle_registration",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "in_progress",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": null,
    "actionItem": {
      "key": "application",
      "status": "action_needed",
      "rejectionReason": "The documents do not belong to the same person."
    },
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "REJECTED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": null,
    "rejectionReason": "The documents do not belong to the same person.",
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfzhvrkd2edyfvy0zj86v4",
        "make": "Nissan",
        "model": "Optra",
        "year": 2017,
        "colour": "أسود",
        "plateNumber": "DH 7208",
        "seats": 4,
        "approvedSeats": null,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": true,
        "status": "PENDING",
        "documents": [
          {
            "id": "01m6jfzhvwzawejg01bhxztfx6",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "PASSENGER",
    "DRIVER"
  ],
  "registeredRole": "passenger",
  "nextStep": "HOME"
}
```

### suspended, with suspensionReason

- **State:** `SUSPENDED`
- **Phone:** `+201055557015`
- **Captured:** 2026-11-04 15:35:48 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Suspended",
    "verification": {
      "overall": "suspended",
      "completed": 3,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": "Repeated no-show reports."
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 2
    },
    "pausedCommutes": 1,
    "pausedCommuteReasons": [
      "driver_suspended"
    ],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "suspended",
    "completed": 3,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": "Repeated no-show reports."
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "SUSPENDED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:47+00:00",
    "rejectionReason": "Repeated no-show reports.",
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jbcggyth4fzy4p6mat2q9a",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "أبيض",
        "plateNumber": "د ه 713",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "automatic",
        "fuelType": "petrol",
        "isActive": false,
        "status": "SUSPENDED",
        "documents": [
          {
            "id": "01m6jf02p50ythefztg2yrn55z",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

**GET /auth/me, in full** → `200`

```json
{
  "success": true,
  "data": {
    "user": {
      "id": "01m6jbcggn5ahkycemndepqg6g",
      "phone": "+201055557015",
      "phoneVerified": true,
      "fullName": "Suspended Demo",
      "publicFirstName": "Suspended",
      "profilePhotoPath": null,
      "dateOfBirth": "1987-05-19",
      "email": "ena03@example.org",
      "accountStatus": "ACTIVE",
      "profileStatus": "BASIC_COMPLETE",
      "capabilities": [
        "DRIVER",
        "PASSENGER"
      ],
      "initialRoleSelected": true,
      "registeredRole": "driver",
      "orgType": "work",
      "organizationId": "01m6hf9f31te7x9wamvjqpjvyw",
      "preferredLanguage": "ar",
      "trustLevel": 3,
      "gender": "woman",
      "canChooseWomenOnly": true,
      "outstandingConsents": [
        "terms",
        "privacy"
      ]
    },
    "device": {
      "id": "01m6jgmjpv8sjv8482gw79w0zq",
      "platform": "android",
      "model": null,
      "osVersion": null,
      "appVersion": "1.0.0",
      "isTrusted": false,
      "biometricEnabled": false,
      "lastSeenAt": "2026-11-04T15:35:48+00:00",
      "revokedAt": null
    },
    "nextStep": "HOME"
  }
}
```

### Whole account suspended

- **State:** `ACCOUNT-SUSPENDED`
- **Phone:** `+201055557027`
- **Captured:** 2026-11-04 15:35:49 UTC

**GET /driver/home** → `200`

```json
{
  "success": true,
  "data": {
    "greetingName": "Accountsuspended",
    "verification": {
      "overall": "verified",
      "completed": 5,
      "of": 5,
      "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
      "actionItem": null,
      "suspensionReason": null
    },
    "expiredRun": null,
    "nextRun": null,
    "laterThisWeek": [],
    "notices": [],
    "pendingRequests": {
      "count": 0,
      "items": []
    },
    "waitlist": {
      "count": 0
    },
    "pausedCommutes": 1,
    "pausedCommuteReasons": [
      "account_suspended"
    ],
    "stats": {
      "onTimeRate": 95,
      "completedTrips": 40,
      "avgDetourMinutes": 0
    }
  }
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2029-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2027-11-04T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-04T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /driver/application** → `200`

```json
{
  "success": true,
  "data": {
    "status": "APPROVED",
    "licenceExpiry": "2029-11-04",
    "hasNationalId": true,
    "hasLicenceNumber": true,
    "verifiedAt": "2026-11-04T15:35:48+00:00",
    "rejectionReason": null,
    "missing": [],
    "vehicles": [
      {
        "id": "01m6jfzhbtpmrgv96s0zrgy9ep",
        "make": "Toyota",
        "model": "Corolla",
        "year": 2020,
        "colour": "White",
        "plateNumber": "DH 7027",
        "seats": 4,
        "approvedSeats": 3,
        "tier": "standard",
        "hasAc": true,
        "transmission": "manual",
        "fuelType": "petrol",
        "isActive": true,
        "status": "APPROVED",
        "documents": [
          {
            "id": "01m6jfzhfsfdn1nga9aaxk3z1p",
            "type": "registration",
            "status": "APPROVED",
            "expiresAt": "2027-11-04"
          }
        ]
      }
    ]
  }
}
```

**capabilities from GET /auth/me** → `200`

```json
{
  "capabilities": [
    "DRIVER",
    "PASSENGER"
  ],
  "registeredRole": "driver",
  "nextStep": "HOME"
}
```

**GET /auth/me, in full** → `200`

```json
{
  "success": true,
  "data": {
    "user": {
      "id": "01m6jfzhbf7rme2e0w4vmn5jj0",
      "phone": "+201055557027",
      "phoneVerified": true,
      "fullName": "Accountsuspended Demo",
      "publicFirstName": "Accountsuspended",
      "profilePhotoPath": null,
      "dateOfBirth": "1986-01-21",
      "email": "jflatley@example.net",
      "accountStatus": "SUSPENDED",
      "suspensionReason": "A safety report is under review, so new bookings are paused.",
      "suspension": {
        "caseNumber": "RF-672432",
        "reasonCode": "safety_report",
        "suspendedAt": "2026-11-04T15:35:48+00:00",
        "reviewDueAt": "2026-11-05T15:35:48+00:00",
        "permanent": false,
        "appeal": {
          "canAppeal": true,
          "appealBy": "2026-11-06T15:35:48+00:00",
          "needsNewEvidence": false,
          "appeals": []
        }
      },
      "profileStatus": "BASIC_COMPLETE",
      "capabilities": [
        "DRIVER",
        "PASSENGER"
      ],
      "initialRoleSelected": true,
      "registeredRole": "driver",
      "orgType": "work",
      "organizationId": "01m6hf9f31te7x9wamvjqpjvyw",
      "preferredLanguage": "ar",
      "trustLevel": 3,
      "gender": "woman",
      "canChooseWomenOnly": true,
      "outstandingConsents": [
        "terms",
        "privacy"
      ]
    },
    "device": {
      "id": "01m6jgmm0epeq06da3s6hpjsfk",
      "platform": "android",
      "model": null,
      "osVersion": null,
      "appVersion": "1.0.0",
      "isTrusted": false,
      "biometricEnabled": false,
      "lastSeenAt": "2026-11-04T15:35:49+00:00",
      "revokedAt": null
    },
    "nextStep": "HOME"
  }
}
```

## Errors

### Start refused, too early

- **State:** `F3D-01`
- **Phone:** `+201055557001`
- **Captured:** 2026-11-04 15:35:50 UTC

**POST /trips/01m6jbcgmnprbdmxbtvarc2xsh/start** → `422`

```json
{
  "success": false,
  "error": {
    "code": "TRIP_TOO_EARLY_TO_START",
    "message": "It is too early to start this run. Start it closer to the departure time.",
    "fields": {
      "departureAt": [
        "2026-11-04T17:45:00+00:00"
      ],
      "windowMinutes": [
        "90"
      ]
    }
  }
}
```

### Submit refused, incomplete

- **State:** `IN-PROGRESS`
- **Phone:** `+201055557202`
- **Captured:** 2026-11-04 15:35:51 UTC

**POST /driver/application/submit** → `422`

```json
{
  "success": false,
  "error": {
    "code": "DRIVER_APPLICATION_INCOMPLETE",
    "message": "Some required details or documents are still missing.",
    "fields": {
      "missing": [
        "licence_front",
        "licence_back",
        "vehicle"
      ]
    }
  }
}
```

