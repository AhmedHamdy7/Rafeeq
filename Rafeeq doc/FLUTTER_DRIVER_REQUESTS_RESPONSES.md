# Driver Requests: real responses

Captured from the running API on the `fix/driver-requests-gaps` branch. Reset any state with `php artisan demo:driver-requests-states --state=<STATE>`. The sign-in code is `RAFEEQ_DEV_OTP_CODE`.

The local server measures routes in straight lines, so the minutes here are smaller than Google's. The `pickup`, `dropoff` and `impact` shapes are the same.

Not captured: `DRIVER_COMMISSION_OVERDUE` and `BOOKING_WAITLIST_FULL`, which need a week's unpaid commission and ten people waiting. Both use the standard error shape.

## States

| State | What it shows | Phone |
|---|---|---|
| `REQ-EMPTY` | Empty inbox | `+201055557301` |
| `REQ-INBOX` | Recurring, trial, waiting-list application, suggested and custom points, new passenger with no rating, hasOtherConfirmedCommute true and false | `+201055557302` |
| `REQ-DECIDED` | Every decided status, one row each | `+201055557303` |
| `REQ-BREAKS-PROMISE` | Would break a promise: canAccept false | `+201055557304` |
| `REQ-INBOX` | Within the limit | `+201055557302` |
| `REQ-OVER-LIMIT` | Over the limit | `+201055557305` |
| `REQ-INBOX` | Pickups and drop-offs with their cost | `+201055557302` |
| `REQ-INBOX` | POST /counteroffer with a suggested pickup | `+201055557302` |
| `REQ-INBOX` | POST /reject | `+201055557302` |
| `REQ-INBOX` | POST /waitlist on a waiting-list application | `+201055557302` |
| `REQ-INBOX` | POST /approve on a trial | `+201055557302` |
| `REQ-RECURRING-SOME-FULL` | POST /approve on a recurring request, with skippedDays | `+201055557306` |
| `REQ-TRIAL-FULL` | SEAT_UNAVAILABLE | `+201055557307` |
| `REQ-DECIDED` | SEAT_REQUEST_NOT_PENDING | `+201055557303` |
| `REQ-INBOX` | Counteroffer refusals | `+201055557302` |
| `REQ-INBOX` | BOOKING_ALREADY_BOOKED | `+201055557302` |
| `REQ-INBOX` | BOOKING_DEADLINE_PASSED | `+201055557302` |
| `REQ-INBOX` | Another driver's request | `+201055557302` |
| `REQ-EMPTY` | Empty | `+201055557301` |
| `WL-DAYS` | Several days, a full day, a member not available today, hasOtherConfirmedCommute | `+201055557311` |
| `WL-OFFER-OPEN` | Open offer, F3D-12 | `+201055557312` |
| `WL-OFFER-ACCEPTED` | Offer accepted, others closed, F3D-13 | `+201055557313` |
| `NOT-STARTED` | Driver who has not started verification, F3U-02 | `+201055557201` |

## Inbox

### Empty inbox

- **State:** `REQ-EMPTY`
- **Phone:** `+201055557301`
- **Captured:** 2026-11-04 18:35:44 UTC

**GET /driver/seat-requests?status=pending,counteroffered** → `200`

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 0,
    "lastPage": 1,
    "hasMore": false
  }
}
```

### Recurring, trial, waiting-list application, suggested and custom points, new passenger with no rating, hasOtherConfirmedCommute true and false

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:35:45 UTC

**GET /driver/seat-requests?status=pending,counteroffered** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6kxgz361d8tr8smfjbs3fc2",
      "commuteId": "01m6kxfwtby8t515jsw761qj6e",
      "tripId": "01m6kxfwxnm3t5jjvtdwj8z1tf",
      "status": "PENDING",
      "commitment": "trial",
      "kind": "waitlist_application",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": {
        "tripId": "01m6kxfwxnm3t5jjvtdwj8z1tf",
        "tripDate": "2026-11-08",
        "addedMinutes": 0,
        "maxDetourMinutes": 10,
        "withinLimit": true,
        "promisedArrivalLocal": "2026-11-08 10:00:00",
        "projectedArrivalLocal": "2026-11-08 08:39:00",
        "canAccept": true,
        "blockReason": null
      },
      "hasOtherConfirmedCommute": true,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:45+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": null,
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:45+00:00",
      "passenger": {
        "publicFirstName": "Hana",
        "trustLevel": 4,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.9,
        "ratingCount": 12,
        "completedTrips": 12,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxgz32e6gv9wqygyx5kzw2",
      "commuteId": "01m6kxfwtby8t515jsw761qj6e",
      "tripId": "01m6kxfwxnm3t5jjvtdwj8z1tf",
      "status": "PENDING",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": {
        "tripId": "01m6kxfwxnm3t5jjvtdwj8z1tf",
        "tripDate": "2026-11-08",
        "addedMinutes": 0,
        "maxDetourMinutes": 10,
        "withinLimit": true,
        "promisedArrivalLocal": "2026-11-08 10:00:00",
        "projectedArrivalLocal": "2026-11-08 08:39:00",
        "canAccept": true,
        "blockReason": null
      },
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Rehab Gate 2",
        "source": "driver_suggestion",
        "suggestionId": "01m6kxfwz6e8t81e69vzz83kw9",
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:45+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": null,
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:45+00:00",
      "passenger": {
        "publicFirstName": "Mona",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxgz2ydrvqmytgfmsyhtpm",
      "commuteId": "01m6kxfwtby8t515jsw761qj6e",
      "tripId": null,
      "status": "PENDING",
      "commitment": "recurring",
      "kind": "seat_request",
      "tripDate": "2026-11-05",
      "departureLocal": "2026-11-05 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": {
        "tripId": "01m6kxfwxmjf4tw8438esq0y8f",
        "tripDate": "2026-11-05",
        "addedMinutes": 0,
        "maxDetourMinutes": 10,
        "withinLimit": true,
        "promisedArrivalLocal": "2026-11-05 10:00:00",
        "projectedArrivalLocal": "2026-11-05 08:39:00",
        "canAccept": true,
        "blockReason": null
      },
      "hasOtherConfirmedCommute": true,
      "requestedDuringRide": false,
      "requestedDaysMask": 127,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:45+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": null,
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:45+00:00",
      "passenger": {
        "publicFirstName": "Rana",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.7,
        "ratingCount": 32,
        "completedTrips": 32,
        "onTimeRate": null
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 3,
    "lastPage": 1,
    "hasMore": false
  }
}
```

### Every decided status, one row each

- **State:** `REQ-DECIDED`
- **Phone:** `+201055557303`
- **Captured:** 2026-11-04 18:35:47 UTC

**GET /driver/seat-requests?perPage=20** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6kxh0cem1m75ecnppy4qxej",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "WAITLISTED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": 1,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T07:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": "2026-11-04T07:35:46+00:00",
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "Waitlisted",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0c8fk03qhyeztbg1prj",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "ENDED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T08:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "Ended",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0c3kw0btgpq50pc6ymy",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "TERMS_CHANGED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T09:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "TermsChanged",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0byt46k5xjwj23cgnm8",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "COUNTEROFFER_DECLINED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T10:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "CounterofferDeclined",
        "trustLevel": 4,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0bq06j38k22sdr5b09x",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "COUNTEROFFERED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "counteroffer": {
        "pickup": {
          "lat": 30.06264,
          "lng": 31.39552,
          "label": "Rehab Gate 2"
        },
        "dropoff": null,
        "note": null,
        "at": "2026-11-04T17:35:46+00:00",
        "answeredAt": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T11:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "Counteroffered",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0bj5b8zd3r752gp6t9n",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "COMMUTE_ARCHIVED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T12:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "CommuteArchived",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0bdkfhwzj3vbr8vasjp",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "CLOSED_DATE_PASSED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T13:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "ClosedDatePassed",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0b8yrtygbdftd334qj1",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "EXPIRED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T14:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "Expired",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0b36n1xsk8eqrb79288",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "WITHDRAWN",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T15:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "Withdrawn",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0ay24e7qhr4nwfy5w6p",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "REJECTED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T16:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "Rejected",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    },
    {
      "id": "01m6kxh0ar72y2cgp5mapwk15f",
      "commuteId": "01m6kxfybfq6fjadb6cq9qy7td",
      "tripId": "01m6kxfyek0d1n0cx87qq1e360",
      "status": "APPROVED",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": null,
      "hasOtherConfirmedCommute": false,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:46+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T17:35:46+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:46+00:00",
      "passenger": {
        "publicFirstName": "Approved",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 8,
        "completedTrips": 8,
        "onTimeRate": null
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 11,
    "lastPage": 1,
    "hasMore": false
  }
}
```

### Would break a promise: canAccept false

- **State:** `REQ-BREAKS-PROMISE`
- **Phone:** `+201055557304`
- **Captured:** 2026-11-04 18:35:48 UTC

**GET /driver/seat-requests** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6kxh1pwkss3sgk0cadkmnsv",
      "commuteId": "01m6kxfzpemjq1taypjk0sxxfk",
      "tripId": "01m6kxfzsyy5wadncygqa6q20r",
      "status": "PENDING",
      "commitment": "trial",
      "kind": "seat_request",
      "tripDate": "2026-11-04",
      "departureLocal": "2026-11-04 23:35:00",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "impact": {
        "tripId": "01m6kxfzsyy5wadncygqa6q20r",
        "tripDate": "2026-11-04",
        "addedMinutes": 0,
        "maxDetourMinutes": 10,
        "withinLimit": true,
        "promisedArrivalLocal": "2026-11-04 23:36:00",
        "projectedArrivalLocal": "2026-11-05 01:14:00",
        "canAccept": false,
        "blockReason": "promised_arrival"
      },
      "hasOtherConfirmedCommute": true,
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:47+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": null,
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:47+00:00",
      "passenger": {
        "publicFirstName": "Rana",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.7,
        "ratingCount": 32,
        "completedTrips": 32,
        "onTimeRate": null
      }
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

**POST /driver/seat-requests/{id}/approve** → `409`

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_BREAKS_FROZEN_PROMISE",
    "message": "Adding this seat would make the commute arrive later than the passengers on board were promised.",
    "fields": {
      "promisedArrivalAt": [
        "2026-11-04T21:36:00+00:00"
      ],
      "projectedArrivalAt": [
        "2026-11-04T23:14:00+00:00"
      ],
      "lockedFrom": [
        "2026-11-04T05:35:00+00:00"
      ]
    }
  }
}
```

## Route preview

### Within the limit

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:35:49 UTC

**GET /driver/seat-requests/{id}/route-preview** → `200`

```json
{
  "success": true,
  "data": {
    "status": "PREVIEW_NOT_CONFIRMED",
    "stops": [
      {
        "id": null,
        "kind": "DRIVER_START",
        "sequence": 0,
        "bookingId": null,
        "isProposed": false,
        "passengerFirstName": null,
        "lat": 30.0602,
        "lng": 31.4915,
        "label": "الرحاب، بوابة ٢",
        "etaAt": "2026-11-05T05:00:00+00:00",
        "legDurationSeconds": 0,
        "legDistanceMeters": 0,
        "reachedAt": null
      },
      {
        "id": null,
        "kind": "PASSENGER_PICKUP",
        "sequence": 1,
        "bookingId": null,
        "isProposed": true,
        "passengerFirstName": null,
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-05T05:29:43+00:00",
        "legDurationSeconds": 1783,
        "legDistanceMeters": 13869,
        "reachedAt": null
      },
      {
        "id": null,
        "kind": "PASSENGER_DROPOFF",
        "sequence": 2,
        "bookingId": null,
        "isProposed": true,
        "passengerFirstName": null,
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "etaAt": "2026-11-05T06:19:13+00:00",
        "legDurationSeconds": 2970,
        "legDistanceMeters": 23101,
        "reachedAt": null
      },
      {
        "id": null,
        "kind": "DRIVER_END",
        "sequence": 3,
        "bookingId": null,
        "isProposed": false,
        "passengerFirstName": null,
        "lat": 30.0724,
        "lng": 31.0116,
        "label": "القرية الذكية، مبنى B6",
        "etaAt": "2026-11-05T06:39:00+00:00",
        "legDurationSeconds": 1187,
        "legDistanceMeters": 9236,
        "reachedAt": null
      }
    ],
    "pointCount": 4,
    "polyline": "gcnvD{te_Ekb@xb[ce@tzm@wAzvQ",
    "distanceMeters": 46206,
    "durationSeconds": 5940,
    "googleMapsDirectionsUrl": "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.065860%2C31.347530%7C30.071960%2C31.107580",
    "exceedsWaypointLimit": false,
    "waypointLimit": 9,
    "segments": [
      "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.065860%2C31.347530%7C30.071960%2C31.107580"
    ],
    "impact": {
      "tripId": "01m6kxfwxmjf4tw8438esq0y8f",
      "tripDate": "2026-11-05",
      "addedMinutes": 0,
      "maxDetourMinutes": 10,
      "withinLimit": true,
      "promisedArrivalLocal": "2026-11-05 10:00:00",
      "projectedArrivalLocal": "2026-11-05 08:39:00",
      "canAccept": true,
      "blockReason": null
    }
  }
}
```

### Over the limit

- **State:** `REQ-OVER-LIMIT`
- **Phone:** `+201055557305`
- **Captured:** 2026-11-04 18:35:51 UTC

**GET /driver/seat-requests/{id}/route-preview** → `200`

```json
{
  "success": true,
  "data": {
    "status": "PREVIEW_NOT_CONFIRMED",
    "stops": [
      {
        "id": null,
        "kind": "DRIVER_START",
        "sequence": 0,
        "bookingId": null,
        "isProposed": false,
        "passengerFirstName": null,
        "lat": 30.0602,
        "lng": 31.4915,
        "label": "الرحاب، بوابة ٢",
        "etaAt": "2026-11-08T05:00:00+00:00",
        "legDurationSeconds": 0,
        "legDistanceMeters": 0,
        "reachedAt": null
      },
      {
        "id": null,
        "kind": "PASSENGER_PICKUP",
        "sequence": 1,
        "bookingId": null,
        "isProposed": true,
        "passengerFirstName": null,
        "lat": 30.3163,
        "lng": 31.25155,
        "label": "Far from the route",
        "etaAt": "2026-11-08T06:18:32+00:00",
        "legDurationSeconds": 4712,
        "legDistanceMeters": 36645,
        "reachedAt": null
      },
      {
        "id": null,
        "kind": "PASSENGER_DROPOFF",
        "sequence": 2,
        "bookingId": null,
        "isProposed": true,
        "passengerFirstName": null,
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T07:23:52+00:00",
        "legDurationSeconds": 3920,
        "legDistanceMeters": 30490,
        "reachedAt": null
      },
      {
        "id": null,
        "kind": "DRIVER_END",
        "sequence": 3,
        "bookingId": null,
        "isProposed": false,
        "passengerFirstName": null,
        "lat": 30.0724,
        "lng": 31.0116,
        "label": "القرية الذكية، مبنى B6",
        "etaAt": "2026-11-08T07:43:39+00:00",
        "legDurationSeconds": 1187,
        "legDistanceMeters": 9236,
        "reachedAt": null
      }
    ],
    "pointCount": 4,
    "polyline": "gcnvD{te_Es_q@tzm@bvn@xb[wAzvQ",
    "distanceMeters": 76371,
    "durationSeconds": 9819,
    "googleMapsDirectionsUrl": "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.316300%2C31.251550%7C30.071960%2C31.107580",
    "exceedsWaypointLimit": false,
    "waypointLimit": 9,
    "segments": [
      "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.316300%2C31.251550%7C30.071960%2C31.107580"
    ],
    "impact": {
      "tripId": "01m6kxg2jcexjcw0gnehv1zqqc",
      "tripDate": "2026-11-08",
      "addedMinutes": 65,
      "maxDetourMinutes": 10,
      "withinLimit": false,
      "promisedArrivalLocal": "2026-11-08 10:00:00",
      "projectedArrivalLocal": "2026-11-08 09:43:39",
      "canAccept": true,
      "blockReason": null
    }
  }
}
```

## Counteroffer options

### Pickups and drop-offs with their cost

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:35:52 UTC

**GET /driver/seat-requests/{id}/counteroffer-options** → `200`

```json
{
  "success": true,
  "data": {
    "tripDate": "2026-11-05",
    "departureLocal": "2026-11-05 07:00:00",
    "maxDetourMinutes": 10,
    "canDropPin": true,
    "requested": {
      "pickup": {
        "label": "Al Rehab Mall entrance",
        "lat": 30.06586,
        "lng": 31.34753
      },
      "dropoff": {
        "label": "Smart Village B12",
        "lat": 30.07196,
        "lng": 31.10758
      },
      "addedMinutes": 0,
      "withinLimit": true
    },
    "route": {
      "polyline": "gcnvD{te_EgkAjv|A",
      "distanceMeters": 46202,
      "durationSeconds": 5940
    },
    "pickups": [
      {
        "suggestionId": "01m6kxfwz6e8t81e69vzz83kw9",
        "label": "Rehab Gate 2",
        "lat": 30.06264,
        "lng": 31.39552,
        "addedMinutes": 0,
        "changeFromRequestedMinutes": 0,
        "walkMeters": 4632,
        "withinLimit": true,
        "refusalCode": null
      },
      {
        "suggestionId": "01m6kxfwz8bkb8tvynt10q1b87",
        "label": "Al Rehab Mall service road",
        "lat": 30.06447,
        "lng": 31.323535,
        "addedMinutes": 0,
        "changeFromRequestedMinutes": 0,
        "walkMeters": 2314,
        "withinLimit": true,
        "refusalCode": null
      }
    ],
    "dropoffs": [
      {
        "suggestionId": "01m6kxfwzah48jzr8nysye0q7s",
        "label": "Smart Village B6",
        "lat": 30.07057,
        "lng": 31.083585,
        "addedMinutes": 0,
        "changeFromRequestedMinutes": 0,
        "walkMeters": 2314,
        "withinLimit": true,
        "refusalCode": null
      }
    ]
  }
}
```

## Answers

### POST /counteroffer with a suggested pickup

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:35:52 UTC

**POST /driver/seat-requests/{id}/counteroffer** with `{"pickup": {"suggestionId": "01m6kxfwz6e8t81e69vzz83kw9"}, "note": "Easier for me to stop here."}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6kxh5fmn0z8hge1rbg73d6j",
    "commuteId": "01m6kxfwtby8t515jsw761qj6e",
    "tripId": null,
    "status": "COUNTEROFFERED",
    "commitment": "recurring",
    "kind": "seat_request",
    "requestedDuringRide": false,
    "requestedDaysMask": 127,
    "seats": 1,
    "meetingPreference": "gate",
    "pickup": {
      "lat": 30.06586,
      "lng": 31.34753,
      "label": "Al Rehab Mall entrance",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedAt": null,
      "estimatedLocal": null
    },
    "dropoff": {
      "lat": 30.07196,
      "lng": 31.10758,
      "label": "Smart Village B12",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedArrivalAt": null,
      "estimatedArrivalLocal": null,
      "promisedArrivalLocal": null
    },
    "counteroffer": {
      "pickup": {
        "lat": 30.06264,
        "lng": 31.39552,
        "label": "Rehab Gate 2"
      },
      "dropoff": null,
      "note": "Easier for me to stop here.",
      "at": "2026-11-04T18:35:52+00:00",
      "answeredAt": null
    },
    "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
    "paymentType": "cash",
    "agreedToRulesAt": "2026-11-04T18:35:51+00:00",
    "waitlistPosition": null,
    "availableToday": true,
    "responseNote": null,
    "respondedAt": "2026-11-04T18:35:52+00:00",
    "pausedAt": null,
    "pausedUntil": null,
    "joinedWaitlistAt": null,
    "expiresAt": null,
    "createdAt": "2026-11-04T18:35:51+00:00",
    "passenger": {
      "publicFirstName": "Rana",
      "trustLevel": 3,
      "verifiedLevels": [],
      "sameOrganisation": false,
      "rating": 4.7,
      "ratingCount": 32,
      "completedTrips": 32,
      "onTimeRate": null
    }
  }
}
```

### POST /reject

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:35:53 UTC

**POST /driver/seat-requests/{id}/reject** with `{"responseNote": null}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6kxh6waja7jw8ywfpqkbmh3",
    "commuteId": "01m6kxfwtby8t515jsw761qj6e",
    "tripId": null,
    "status": "REJECTED",
    "commitment": "recurring",
    "kind": "seat_request",
    "requestedDuringRide": false,
    "requestedDaysMask": 127,
    "seats": 1,
    "meetingPreference": "gate",
    "pickup": {
      "lat": 30.06586,
      "lng": 31.34753,
      "label": "Al Rehab Mall entrance",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedAt": null,
      "estimatedLocal": null
    },
    "dropoff": {
      "lat": 30.07196,
      "lng": 31.10758,
      "label": "Smart Village B12",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedArrivalAt": null,
      "estimatedArrivalLocal": null,
      "promisedArrivalLocal": null
    },
    "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
    "paymentType": "cash",
    "agreedToRulesAt": "2026-11-04T18:35:53+00:00",
    "waitlistPosition": null,
    "availableToday": true,
    "responseNote": null,
    "respondedAt": "2026-11-04T18:35:54+00:00",
    "pausedAt": null,
    "pausedUntil": null,
    "joinedWaitlistAt": null,
    "expiresAt": null,
    "createdAt": "2026-11-04T18:35:53+00:00",
    "passenger": {
      "publicFirstName": "Rana",
      "trustLevel": 3,
      "verifiedLevels": [],
      "sameOrganisation": false,
      "rating": 4.7,
      "ratingCount": 32,
      "completedTrips": 32,
      "onTimeRate": null
    }
  }
}
```

### POST /waitlist on a waiting-list application

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:35:55 UTC

**POST /driver/seat-requests/{id}/waitlist** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6kxh855x9j60pnnrawb9xb6",
    "commuteId": "01m6kxfwtby8t515jsw761qj6e",
    "tripId": "01m6kxfwxnm3t5jjvtdwj8z1tf",
    "status": "WAITLISTED",
    "commitment": "trial",
    "kind": "waitlist_application",
    "requestedDuringRide": false,
    "requestedDaysMask": null,
    "seats": 1,
    "meetingPreference": "gate",
    "pickup": {
      "lat": 30.06586,
      "lng": 31.34753,
      "label": "Al Rehab Mall entrance",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedAt": null,
      "estimatedLocal": null
    },
    "dropoff": {
      "lat": 30.07196,
      "lng": 31.10758,
      "label": "Smart Village B12",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedArrivalAt": null,
      "estimatedArrivalLocal": null,
      "promisedArrivalLocal": null
    },
    "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
    "paymentType": "cash",
    "agreedToRulesAt": "2026-11-04T18:35:54+00:00",
    "waitlistPosition": 1,
    "availableToday": true,
    "responseNote": null,
    "respondedAt": "2026-11-04T18:35:55+00:00",
    "pausedAt": null,
    "pausedUntil": null,
    "joinedWaitlistAt": "2026-11-04T18:35:55+00:00",
    "expiresAt": null,
    "createdAt": "2026-11-04T18:35:54+00:00"
  }
}
```

### POST /approve on a trial

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:35:56 UTC

**POST /driver/seat-requests/{id}/approve** → `201`

```json
{
  "success": true,
  "data": {
    "seatRequest": {
      "id": "01m6kxh9e6asd01t8mb6ebe75x",
      "commuteId": "01m6kxfwtby8t515jsw761qj6e",
      "tripId": "01m6kxfwxnm3t5jjvtdwj8z1tf",
      "status": "APPROVED",
      "commitment": "trial",
      "kind": "seat_request",
      "requestedDuringRide": false,
      "requestedDaysMask": null,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Rehab Gate 2",
        "source": "driver_suggestion",
        "suggestionId": "01m6kxfwz6e8t81e69vzz83kw9",
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:55+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T18:35:56+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:55+00:00",
      "passenger": {
        "publicFirstName": "Mona",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    },
    "membership": {
      "id": "01m6kxg8r4vf8evyg9qzhfc4cv",
      "role": "trial",
      "status": "ACTIVE",
      "committedDaysMask": null,
      "joinedAt": "2026-11-04T18:35:22+00:00",
      "leftAt": null,
      "leavesOn": null
    },
    "bookings": [
      {
        "id": "01m6kxhaet9nf0jyw8we91afbh",
        "tripId": "01m6kxfwxnm3t5jjvtdwj8z1tf",
        "commuteGroupId": "01m6kxg8qjm9mhf4a3pkwvzr9m",
        "membership": {
          "committedDaysMask": null,
          "commitment": "trial",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6531",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Rehab Gate 2",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:56+00:00"
      }
    ],
    "seatedDays": 1,
    "skippedDays": []
  }
}
```

### POST /approve on a recurring request, with skippedDays

- **State:** `REQ-RECURRING-SOME-FULL`
- **Phone:** `+201055557306`
- **Captured:** 2026-11-04 18:35:57 UTC

**POST /driver/seat-requests/{id}/approve** → `201`

```json
{
  "success": true,
  "data": {
    "seatRequest": {
      "id": "01m6kxhaqxdtap3k3e6vr1g5wb",
      "commuteId": "01m6kxg8zye3gy2hsnah2c98am",
      "tripId": null,
      "status": "APPROVED",
      "commitment": "recurring",
      "kind": "seat_request",
      "requestedDuringRide": false,
      "requestedDaysMask": 127,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.06586,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedAt": null,
        "estimatedLocal": null
      },
      "dropoff": {
        "lat": 30.07196,
        "lng": 31.10758,
        "label": "Smart Village B12",
        "source": "custom_map",
        "suggestionId": null,
        "estimatedArrivalAt": null,
        "estimatedArrivalLocal": null,
        "promisedArrivalLocal": null
      },
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-04T18:35:57+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-04T18:35:58+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-04T18:35:57+00:00",
      "passenger": {
        "publicFirstName": "Rana",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.7,
        "ratingCount": 32,
        "completedTrips": 32,
        "onTimeRate": null
      }
    },
    "membership": {
      "id": "01m6kxga909fwrmvcxc4m1m0tn",
      "role": "member",
      "status": "ACTIVE",
      "committedDaysMask": 127,
      "joinedAt": "2026-11-04T18:35:23+00:00",
      "leftAt": null,
      "leavesOn": null
    },
    "bookings": [
      {
        "id": "01m6kxhbqt4xxmftsv0pkebc04",
        "tripId": "01m6kxg93e22jzr1ccfgs4mnrg",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "9357",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbqz9zppmrrx3tfwbhdm",
        "tripId": "01m6kxg93fmeh6hgj0fk2cxs13",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6540",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbr4vb6pjpa5bx2t6t9y",
        "tripId": "01m6kxg93g5kf16f8cfb1k68en",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "7039",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbrahfch1zjv67fg3qds",
        "tripId": "01m6kxg93h9q0g2pf63bd2xp5y",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6320",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbrf3rbvqmvstk210md0",
        "tripId": "01m6kxg93j5g9v3d23qfc98nsd",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "0132",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbrm0wpvsda2gythb5qd",
        "tripId": "01m6kxg93ka9yep2r3fcm5jvb1",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "8524",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbrr1m9m03fz0z3x7ewg",
        "tripId": "01m6kxg93m7g722tp9443gjcyp",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6986",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbrxj51pqcx209jwnm41",
        "tripId": "01m6kxg93nj724v474y5e2zht8",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "4477",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbs2rdkb6k2e3rh8kn9y",
        "tripId": "01m6kxg93pyt24kgs7khba6286",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "1695",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbs744vmv2jmee6ndte0",
        "tripId": "01m6kxg93pyt24kgs7khba6287",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "0453",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbsaae6nxcm0hykh0evt",
        "tripId": "01m6kxg93qkjnv2cyrx5wxq4q2",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "3643",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbsf6n1mzbct91vmqhre",
        "tripId": "01m6kxg93rfjw3ha8x7hr2vnfe",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "7178",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbsm8cfr9pygdyfvx4jp",
        "tripId": "01m6kxg93sg07s3zakmf55aj8b",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "5683",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbssggcf70w63jeygprm",
        "tripId": "01m6kxg93thwwtm7qjz98grej4",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "4600",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbsy6m4d1cv0nnj02chd",
        "tripId": "01m6kxg93vbksv856tbrt03s3t",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "8836",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbt26ys1gj23gjsqtqqp",
        "tripId": "01m6kxg93w5rbsmkj41ay6qz19",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6684",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbt6s39ejff6p711nw5m",
        "tripId": "01m6kxg93w5rbsmkj41ay6qz1a",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "4420",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbtbt0wx43kkykxceyq3",
        "tripId": "01m6kxg93x0rcvpk4f129ax5np",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "8911",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbtga2c39tr2erebvszq",
        "tripId": "01m6kxg93yxfbmczkmhafe6vz2",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "8946",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      },
      {
        "id": "01m6kxhbtn19960f1bhxjz8gpc",
        "tripId": "01m6kxg93z7e17jjwrt3gac1ga",
        "commuteGroupId": "01m6kxga5da6qjt5fkhmfn2w36",
        "membership": {
          "committedDaysMask": 127,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "9553",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.065859999999997,
          "lng": 31.34753,
          "label": "Al Rehab Mall entrance",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.071959999999997,
          "lng": 31.107580000000002,
          "label": "Smart Village B12",
          "isExact": true
        },
        "otherPartyUserId": null,
        "cancelledAt": null,
        "cancelledReason": null,
        "outcomeSeenAt": null,
        "pickupNote": null,
        "cancellationFeePiastres": null,
        "feeLinePiastres": 0,
        "feeLineReason": null,
        "earlyDrop": {
          "askedAt": null,
          "distanceMeters": null,
          "answer": null
        },
        "createdAt": "2026-11-04T18:35:58+00:00"
      }
    ],
    "seatedDays": 20,
    "skippedDays": [
      {
        "tripDate": "2026-11-05",
        "reason": "SEAT_UNAVAILABLE"
      }
    ]
  }
}
```

## Refusals

### SEAT_UNAVAILABLE

- **State:** `REQ-TRIAL-FULL`
- **Phone:** `+201055557307`
- **Captured:** 2026-11-04 18:35:59 UTC

**POST /driver/seat-requests/{id}/approve** → `409`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_UNAVAILABLE",
    "message": "The seats on that day have just been taken.",
    "fields": {
      "seatsAvailable": [
        "0"
      ]
    }
  }
}
```

### SEAT_REQUEST_NOT_PENDING

- **State:** `REQ-DECIDED`
- **Phone:** `+201055557303`
- **Captured:** 2026-11-04 18:36:00 UTC

**POST /driver/seat-requests/{id}/approve** → `409`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_REQUEST_NOT_PENDING",
    "message": "That request has already been answered.",
    "fields": null
  }
}
```

**POST /driver/seat-requests/{id}/reject** → `409`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_REQUEST_NOT_PENDING",
    "message": "That request has already been answered.",
    "fields": null
  }
}
```

**POST /driver/seat-requests/{id}/counteroffer** with `{"pickup": {"lat": 30.05, "lng": 31.4, "label": "Somewhere else"}}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_REQUEST_NOT_PENDING",
    "message": "That request has already been answered.",
    "fields": null
  }
}
```

**GET /driver/seat-requests/{id}/counteroffer-options** → `409`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_REQUEST_NOT_PENDING",
    "message": "That request has already been answered.",
    "fields": null
  }
}
```

### Counteroffer refusals

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:36:01 UTC

**POST /driver/seat-requests/{id}/counteroffer** with `{"pickup": {"lat": 30.07196, "lng": 31.10758, "label": "On top of the drop-off"}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_REQUEST_POINTS_SAME",
    "message": "Your pickup and drop-off are the same place. Choose two different points.",
    "fields": null
  }
}
```

**POST /driver/seat-requests/{id}/counteroffer** with `{"pickup": {"lat": 30.06196, "lng": 31.15758, "label": "After the drop-off"}, "dropoff": {"lat": 30.06586, "lng": 31.34753, "label": "Before the pickup"}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_REQUEST_POINTS_OUT_OF_ORDER",
    "message": "This commute reaches your drop-off before your pickup. Check which way round they are.",
    "fields": null
  }
}
```

**POST /driver/seat-requests/{id}/counteroffer** with `{"pickup": {"suggestionId": "01KAAAAAAAAAAAAAAAAAAAAAAA"}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_REQUEST_SUGGESTION_UNKNOWN",
    "message": "That meeting point is not one this commute offers. Pick another, or choose a point on the map.",
    "fields": {
      "pickup": [
        "01KAAAAAAAAAAAAAAAAAAAAAAA"
      ]
    }
  }
}
```

**POST /driver/seat-requests/{id}/counteroffer** with `{"pickup": {"lat": 30.36586, "lng": 31.34753, "label": "Far away"}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "PICKUP_DETOUR_TOO_LONG",
    "message": "That pickup point is further off the driver’s route than they accept.",
    "fields": {
      "addedMinutes": [
        "130.1"
      ],
      "runTotalMinutes": [
        "130.1"
      ],
      "maxDetourMinutes": [
        "10"
      ],
      "runTotalKm": [
        "60.7"
      ],
      "maxDetourMeters": [
        "5000"
      ]
    }
  }
}
```

**POST /driver/seat-requests/{id}/counteroffer** with `{"pickup": {"lat": 30.06586, "lng": 31.34753, "label": "Same place"}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "pickup": [
        "Suggest a different pickup or drop-off — otherwise simply accept the request."
      ]
    }
  }
}
```

### BOOKING_ALREADY_BOOKED

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:36:03 UTC

**POST /driver/seat-requests/{id}/approve** → `409`

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_ALREADY_BOOKED",
    "message": "You already have a seat on that day.",
    "fields": null
  }
}
```

### BOOKING_DEADLINE_PASSED

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:36:04 UTC

**POST /driver/seat-requests/{id}/approve** → `422`

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_DEADLINE_PASSED",
    "message": "Bookings for that day have closed.",
    "fields": null
  }
}
```

### Another driver's request

- **State:** `REQ-INBOX`
- **Phone:** `+201055557302`
- **Captured:** 2026-11-04 18:36:06 UTC

**GET /driver/seat-requests/{id}/counteroffer-options** → `404`

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

## Waiting list

### Empty

- **State:** `REQ-EMPTY`
- **Phone:** `+201055557301`
- **Captured:** 2026-11-04 18:36:08 UTC

**GET /driver/waitlist** → `200`

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 0,
    "lastPage": 1,
    "hasMore": false,
    "waitingCount": 0,
    "days": []
  }
}
```

**GET /driver/seat-offers** → `200`

```json
{
  "success": true,
  "data": []
}
```

### Several days, a full day, a member not available today, hasOtherConfirmedCommute

- **State:** `WL-DAYS`
- **Phone:** `+201055557311`
- **Captured:** 2026-11-04 18:36:10 UTC

**GET /driver/waitlist** → `200`

```json
{
  "success": true,
  "data": [
    {
      "seatRequestId": "01m6kxhphcma82vr7xy2fcw2gc",
      "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6kxhpegk5w4mcjpwz019zc1",
      "seats": 1,
      "waitlistPosition": 1,
      "joinedWaitlistAt": "2026-11-03T18:36:09+00:00",
      "tripDate": "2026-11-05",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": true,
      "offer": null,
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": false,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Laila",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.9,
        "ratingCount": 15,
        "completedTrips": 15,
        "onTimeRate": null
      }
    },
    {
      "seatRequestId": "01m6kxhphgyxs6kbbt6j18jqnq",
      "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6kxhpehzkcr49k4v5h23byb",
      "seats": 1,
      "waitlistPosition": 2,
      "joinedWaitlistAt": "2026-11-02T18:36:09+00:00",
      "tripDate": "2026-11-08",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": true,
      "offer": null,
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": false,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Mona",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    },
    {
      "seatRequestId": "01m6kxhphp0m9w9gaa1h6bfbjs",
      "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
      "commitment": "recurring",
      "requestedDaysMask": 127,
      "tripId": null,
      "seats": 1,
      "waitlistPosition": 3,
      "joinedWaitlistAt": "2026-11-01T18:36:09+00:00",
      "tripDate": null,
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": false,
      "offer": null,
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": true,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Youssra",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 6,
        "completedTrips": 6,
        "onTimeRate": null
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 3,
    "lastPage": 1,
    "hasMore": false,
    "waitingCount": 3,
    "days": [
      {
        "date": "2026-11-05",
        "tripId": "01m6kxhpegk5w4mcjpwz019zc1",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-05 07:00:00",
        "isFull": true,
        "waitingCount": 2
      },
      {
        "date": "2026-11-08",
        "tripId": "01m6kxhpehzkcr49k4v5h23byb",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-08 07:00:00",
        "isFull": false,
        "waitingCount": 2
      },
      {
        "date": "2026-11-09",
        "tripId": "01m6kxhpehzkcr49k4v5h23byc",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-09 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-10",
        "tripId": "01m6kxhpejegsmz6q6x4bbze65",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-10 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-11",
        "tripId": "01m6kxhpekwhzs3evgd2p7txbt",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-11 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-12",
        "tripId": "01m6kxhpemmsmcafm3870mrcej",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-12 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-15",
        "tripId": "01m6kxhpena2n89z3v3strcm94",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-15 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-16",
        "tripId": "01m6kxhpepnd26ze076y9qgsnb",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-16 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-17",
        "tripId": "01m6kxhpeqyheqe5rshkn6qcw5",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-17 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-18",
        "tripId": "01m6kxhpergzqr3kdpxr1c5rbk",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-18 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-19",
        "tripId": "01m6kxhpergzqr3kdpxr1c5rbm",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-19 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-22",
        "tripId": "01m6kxhpes5tns5gcwgsgwpbap",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-22 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-23",
        "tripId": "01m6kxhpetn2h2949hqc07f6gt",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-23 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-24",
        "tripId": "01m6kxhpev2xpk5cqng0060nbj",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-24 07:00:00",
        "isFull": false,
        "waitingCount": 1
      }
    ]
  }
}
```

**GET /driver/waitlist?tripId={tripId}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "seatRequestId": "01m6kxhphcma82vr7xy2fcw2gc",
      "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6kxhpegk5w4mcjpwz019zc1",
      "seats": 1,
      "waitlistPosition": 1,
      "joinedWaitlistAt": "2026-11-03T18:36:09+00:00",
      "tripDate": "2026-11-05",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": true,
      "offer": null,
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": false,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Laila",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.9,
        "ratingCount": 15,
        "completedTrips": 15,
        "onTimeRate": null
      }
    },
    {
      "seatRequestId": "01m6kxhphp0m9w9gaa1h6bfbjs",
      "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
      "commitment": "recurring",
      "requestedDaysMask": 127,
      "tripId": null,
      "seats": 1,
      "waitlistPosition": 3,
      "joinedWaitlistAt": "2026-11-01T18:36:09+00:00",
      "tripDate": null,
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": false,
      "offer": null,
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": true,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Youssra",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.5,
        "ratingCount": 6,
        "completedTrips": 6,
        "onTimeRate": null
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false,
    "waitingCount": 3,
    "days": [
      {
        "date": "2026-11-05",
        "tripId": "01m6kxhpegk5w4mcjpwz019zc1",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-05 07:00:00",
        "isFull": true,
        "waitingCount": 2
      },
      {
        "date": "2026-11-08",
        "tripId": "01m6kxhpehzkcr49k4v5h23byb",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-08 07:00:00",
        "isFull": false,
        "waitingCount": 2
      },
      {
        "date": "2026-11-09",
        "tripId": "01m6kxhpehzkcr49k4v5h23byc",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-09 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-10",
        "tripId": "01m6kxhpejegsmz6q6x4bbze65",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-10 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-11",
        "tripId": "01m6kxhpekwhzs3evgd2p7txbt",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-11 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-12",
        "tripId": "01m6kxhpemmsmcafm3870mrcej",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-12 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-15",
        "tripId": "01m6kxhpena2n89z3v3strcm94",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-15 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-16",
        "tripId": "01m6kxhpepnd26ze076y9qgsnb",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-16 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-17",
        "tripId": "01m6kxhpeqyheqe5rshkn6qcw5",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-17 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-18",
        "tripId": "01m6kxhpergzqr3kdpxr1c5rbk",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-18 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-19",
        "tripId": "01m6kxhpergzqr3kdpxr1c5rbm",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-19 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-22",
        "tripId": "01m6kxhpes5tns5gcwgsgwpbap",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-22 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-23",
        "tripId": "01m6kxhpetn2h2949hqc07f6gt",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-23 07:00:00",
        "isFull": false,
        "waitingCount": 1
      },
      {
        "date": "2026-11-24",
        "tripId": "01m6kxhpev2xpk5cqng0060nbj",
        "commuteId": "01m6kxhpapwzy3tkg5n3b66n9c",
        "departureLocal": "2026-11-24 07:00:00",
        "isFull": false,
        "waitingCount": 1
      }
    ]
  }
}
```

### Open offer, F3D-12

- **State:** `WL-OFFER-OPEN`
- **Phone:** `+201055557312`
- **Captured:** 2026-11-04 18:36:11 UTC

**GET /driver/waitlist** → `200`

```json
{
  "success": true,
  "data": [
    {
      "seatRequestId": "01m6kxhqy12sj6tccmp77sz9w3",
      "commuteId": "01m6kxhqpxk8zjakg8dw612vgz",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6kxhqv55an87atjjx40k24r",
      "seats": 1,
      "waitlistPosition": 1,
      "joinedWaitlistAt": "2026-11-03T18:36:10+00:00",
      "tripDate": "2026-11-08",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": true,
      "offer": {
        "id": "01m6kxhqyac9aqhg9nd3nqdz8d",
        "tripId": "01m6kxhqv55an87atjjx40k24r",
        "date": "2026-11-08",
        "status": "OFFERED"
      },
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": false,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Laila",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": 4.9,
        "ratingCount": 15,
        "completedTrips": 15,
        "onTimeRate": null
      }
    },
    {
      "seatRequestId": "01m6kxhqy5a9cqjgv167zb483s",
      "commuteId": "01m6kxhqpxk8zjakg8dw612vgz",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6kxhqv55an87atjjx40k24r",
      "seats": 1,
      "waitlistPosition": 2,
      "joinedWaitlistAt": "2026-11-02T18:36:10+00:00",
      "tripDate": "2026-11-08",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": true,
      "offer": {
        "id": "01m6kxhqyb7xwqwhhak42d9adn",
        "tripId": "01m6kxhqv55an87atjjx40k24r",
        "date": "2026-11-08",
        "status": "OFFERED"
      },
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": false,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Mona",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false,
    "waitingCount": 2,
    "days": [
      {
        "date": "2026-11-08",
        "tripId": "01m6kxhqv55an87atjjx40k24r",
        "commuteId": "01m6kxhqpxk8zjakg8dw612vgz",
        "departureLocal": "2026-11-08 07:00:00",
        "isFull": false,
        "waitingCount": 2
      }
    ]
  }
}
```

**GET /driver/seat-offers?status=open** → `200`

```json
{
  "success": true,
  "data": [
    {
      "releaseEventId": "01M6KXHQYAC9AQHG9ND3NQDZ8C",
      "tripId": "01m6kxhqv55an87atjjx40k24r",
      "commuteId": "01m6kxhqpxk8zjakg8dw612vgz",
      "date": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "status": "OPEN",
      "recipientsCount": 2,
      "acceptedBy": null,
      "members": [
        {
          "offerId": "01m6kxhqyac9aqhg9nd3nqdz8d",
          "seatRequestId": "01m6kxhqy12sj6tccmp77sz9w3",
          "publicFirstName": "Laila",
          "status": "OFFERED"
        },
        {
          "offerId": "01m6kxhqyb7xwqwhhak42d9adn",
          "seatRequestId": "01m6kxhqy5a9cqjgv167zb483s",
          "publicFirstName": "Mona",
          "status": "OFFERED"
        }
      ]
    }
  ]
}
```

### Offer accepted, others closed, F3D-13

- **State:** `WL-OFFER-ACCEPTED`
- **Phone:** `+201055557313`
- **Captured:** 2026-11-04 18:36:12 UTC

**GET /driver/waitlist** → `200`

```json
{
  "success": true,
  "data": [
    {
      "seatRequestId": "01m6kxhs93aw79dpne6f7n1tkd",
      "commuteId": "01m6kxhs2q5g3syx7xpqks8m43",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6kxhs6c9vtzwqcce71qwfa3",
      "seats": 1,
      "waitlistPosition": 2,
      "joinedWaitlistAt": "2026-11-02T18:36:11+00:00",
      "tripDate": "2026-11-08",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Al Rehab Mall entrance"
      },
      "dropoff": {
        "label": "Smart Village B12"
      },
      "availableToday": true,
      "offer": {
        "id": "01m6kxhs968nraxkgkedz9w7dw",
        "tripId": "01m6kxhs6c9vtzwqcce71qwfa3",
        "date": "2026-11-08",
        "status": "OFFER_CLOSED"
      },
      "isPreviousPassenger": false,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": false,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Mona",
        "trustLevel": 1,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "lastPage": 1,
    "hasMore": false,
    "waitingCount": 1,
    "days": [
      {
        "date": "2026-11-08",
        "tripId": "01m6kxhs6c9vtzwqcce71qwfa3",
        "commuteId": "01m6kxhs2q5g3syx7xpqks8m43",
        "departureLocal": "2026-11-08 07:00:00",
        "isFull": false,
        "waitingCount": 1
      }
    ]
  }
}
```

**GET /driver/seat-offers?status=accepted** → `200`

```json
{
  "success": true,
  "data": [
    {
      "releaseEventId": "01M6KXHS95R35FW2134NS8F4XM",
      "tripId": "01m6kxhs6c9vtzwqcce71qwfa3",
      "commuteId": "01m6kxhs2q5g3syx7xpqks8m43",
      "date": "2026-11-08",
      "departureLocal": "2026-11-08 07:00:00",
      "status": "ACCEPTED",
      "recipientsCount": 2,
      "acceptedBy": {
        "seatRequestId": "01m6kxhs909375ma7ftnh3c4cn",
        "publicFirstName": "Laila"
      },
      "members": [
        {
          "offerId": "01m6kxhs95r35fw2134ns8f4xn",
          "seatRequestId": "01m6kxhs909375ma7ftnh3c4cn",
          "publicFirstName": "Laila",
          "status": "CONFIRMED"
        },
        {
          "offerId": "01m6kxhs968nraxkgkedz9w7dw",
          "seatRequestId": "01m6kxhs93aw79dpne6f7n1tkd",
          "publicFirstName": "Mona",
          "status": "OFFER_CLOSED"
        }
      ]
    }
  ]
}
```

## Unverified driver

### Driver who has not started verification, F3U-02

- **State:** `NOT-STARTED`
- **Phone:** `+201055557201`
- **Captured:** 2026-11-04 18:36:13 UTC

**GET /driver/seat-requests** → `200`

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 0,
    "lastPage": 1,
    "hasMore": false
  }
}
```

**GET /driver/waitlist** → `200`

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 0,
    "lastPage": 1,
    "hasMore": false,
    "waitingCount": 0,
    "days": []
  }
}
```

**GET /driver/seat-offers** → `200`

```json
{
  "success": true,
  "data": []
}
```

**GET /commutes** → `403`

```json
{
  "success": false,
  "error": {
    "code": "VERIFICATION_REQUIRED",
    "message": "Please complete verification to continue.",
    "fields": {
      "verification": [
        "government_id"
      ]
    }
  }
}
```

