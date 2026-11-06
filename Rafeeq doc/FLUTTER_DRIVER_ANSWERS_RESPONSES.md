# Driver answers: captured responses

Real responses for the backend changes in PRs #45 and #46: the live-run fixes and the Driver Requests and Driver Home answers.

**Every response was captured locally** on 2026-11-06, against `demo:driver-commutes-states` and `demo:create-commute-states`. **None was captured on live.** Where a step can't be reached through the API, the note says how it was set up.

They come from `main` plus branch `fix/registration-expiring-copy`, which isn't merged yet. On `main`, the `driver_item_expiring` title for a car registration still says the driving licence, and the expired-registration sweep can fail on a test server when two cars expire on the same night.

- **Language:** a notification's `title` and `body` use the account's language, so some are in Arabic.
- **`data` in notifications:** the values are strings, including `seatedDays`.
- **Passengers:** Layla `+201055557607`, Dina `+201055557608`, Reem `+201055557609`, Salma `+201055557601`, Nour `+201055557602`.

Route distance, time and price come from straight-line routing, as on live until `RAFEEQ_GEO_ENGINE=google` is on. The sign-in code is `123456`.

## Not captured
- **`BOOKING_BREAKS_FROZEN_PROMISE` before the lock:** it can't happen. Before the lock the request is accepted and the departure is re-timed.
- **`skippedDays[].reason: unavailable`:** it needs a day refused for another reason, such as a same-time booking.
- **A proposal's `previousPickupLocal` and `newPickupLocal`:** in `FLUTTER_DRIVER_COMMUTES_RESPONSES.md`.

## 1. Arrival required on every day

### PUT /schedule on a draft

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-06 07:53:47 UTC, locally

**PUT /commutes/{commute}/schedule** with `{"daysMask": 62}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_ARRIVAL_REQUIRED",
    "message": "Choose when you need to arrive on every day. We work out when you leave.",
    "fields": {
      "perDay.2.promisedArrivalTime": [
        "The promisedArrivalTime field is required."
      ]
    }
  }
}
```

**PUT /commutes/{commute}/schedule** with `{"daysMask": 62, "departureTime": "07:05:00"}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_ARRIVAL_REQUIRED",
    "message": "Choose when you need to arrive on every day. We work out when you leave.",
    "fields": {
      "perDay.2.promisedArrivalTime": [
        "The promisedArrivalTime field is required."
      ]
    }
  }
}
```

**PUT /commutes/{commute}/schedule** with `{"daysMask": 6, "perDay": {"2": {"promisedArrivalTime": "09:00:00"}}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_ARRIVAL_REQUIRED",
    "message": "Choose when you need to arrive on every day. We work out when you leave.",
    "fields": {
      "perDay.4.promisedArrivalTime": [
        "The promisedArrivalTime field is required."
      ]
    }
  }
}
```

**PUT /commutes/{commute}/schedule** with `{"daysMask": 62, "promisedArrivalTime": "09:00:00", "departureTime": "05:00:00"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6qxjwqje9rakxq3vrj6tvtx",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6mga8j7zs96783vn0wwx4q0",
    "seatsTotal": 3,
    "seatPricePiastres": null,
    "pricePerSeatPiastres": null,
    "driverSharePiastres": null,
    "currency": "EGP",
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "any_verified",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "createdAt": "2026-11-06T07:53:45+00:00",
    "updatedAt": "2026-11-06T07:53:47+00:00",
    "formStep": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6qxjwqzrp80j4zhfsn6a9mf",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6qxjwqzrp80j4zhfsn6a9mg",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": {
      "daysMask": 62,
      "departureTime": "07:12:00",
      "timezone": "Africa/Cairo",
      "startDate": "2026-11-07",
      "endDate": "2027-11-07",
      "generatedUntil": null
    },
    "weekdays": [
      {
        "weekdayBit": 2,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:12:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 4,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:12:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 8,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:12:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 16,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:12:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 32,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:12:00",
        "departureIsComputed": true,
        "seatsTotal": null
      }
    ],
    "rules": {}
  }
}
```

## 2. Recurring approval

### One day full, the others seated

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-06 07:53:48 UTC, locally
- **Note:** The full day was set directly in the database.

**POST /commutes/{commute}/seat-requests** with `{"commitment": "recurring", "seats": 1, "meetingPreference": "gate", "pickup": {"lat": 30.0624, "lng": 31.3614, "label": "Ring Road, Rehab"}, "dropoff": {"lat": 30.0684, "lng": 31.1015, "label": "Mehwar, Sheikh Zayed"}, "paymentType": "cash", "agreedToRules": true, "requestedDaysMask": 62}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6qxk0f7q3fc0dyhvx334gyc",
    "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
    "tripId": null,
    "status": "PENDING",
    "commitment": "recurring",
    "kind": "seat_request",
    "requestedDuringRide": false,
    "requestedDaysMask": 62,
    "seats": 1,
    "meetingPreference": "gate",
    "pickup": {
      "lat": 30.0624,
      "lng": 31.3614,
      "label": "Ring Road, Rehab",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedAt": "2026-11-15T05:47:50+00:00",
      "estimatedLocal": "2026-11-15 07:47:50"
    },
    "dropoff": {
      "lat": 30.0684,
      "lng": 31.1015,
      "label": "Mehwar, Sheikh Zayed",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedArrivalAt": "2026-11-15T06:41:27+00:00",
      "estimatedArrivalLocal": "2026-11-15 08:41:27",
      "promisedArrivalLocal": "2026-11-15 09:00:00"
    },
    "introMessage": null,
    "paymentType": "cash",
    "agreedToRulesAt": "2026-11-06T07:53:49+00:00",
    "waitlistPosition": null,
    "availableToday": true,
    "responseNote": null,
    "respondedAt": null,
    "pausedAt": null,
    "pausedUntil": null,
    "joinedWaitlistAt": null,
    "expiresAt": null,
    "createdAt": "2026-11-06T07:53:49+00:00",
    "commute": {
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
      "tripId": "01m6phc12p1qbrw8kszyh4kma2",
      "tripDate": "2026-11-15",
      "departureAt": "2026-11-15T05:21:00+00:00",
      "departureLocal": "2026-11-15 07:21:00",
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driver": {
        "publicFirstName": "Dcnobodybooked",
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
        "completedTrips": 40,
        "onTimeRate": 95
      },
      "vehicle": {
        "make": "Toyota",
        "model": "Corolla",
        "colour": "White",
        "tier": "standard",
        "hasAc": true
      }
    }
  }
}
```

**POST /driver/seat-requests/{seatRequest}/approve** → `201`

```json
{
  "success": true,
  "data": {
    "seatRequest": {
      "id": "01m6qxk0f7q3fc0dyhvx334gyc",
      "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
      "tripId": null,
      "status": "APPROVED",
      "commitment": "recurring",
      "kind": "seat_request",
      "requestedDuringRide": false,
      "requestedDaysMask": 62,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.0624,
        "lng": 31.3614,
        "label": "Ring Road, Rehab",
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
      "introMessage": null,
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-06T07:53:49+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-06T07:53:49+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-06T07:53:49+00:00",
      "passenger": {
        "publicFirstName": "Layla",
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
      "id": "01m6qx4nvhjckz4mc0ts1axf1f",
      "role": "member",
      "status": "ACTIVE",
      "committedDaysMask": 62,
      "joinedAt": "2026-11-06T07:46:00+00:00",
      "leftAt": null,
      "leavesOn": null
    },
    "bookings": [
      {
        "id": "01m6qxk0hz7av651fbhy4k2m4m",
        "tripId": "01m6phc12qvym1evd55xcympfr",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "4115",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-16T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-16 07:47:50",
        "trip": {
          "id": "01m6phc12qvym1evd55xcympfr",
          "tripDate": "2026-11-16",
          "departureAt": "2026-11-16T05:21:00+00:00",
          "departureLocal": "2026-11-16 07:21:00",
          "promisedArrivalAt": "2026-11-16T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-16 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0jbmznc0zvc0xrgvqfj",
        "tripId": "01m6phc12r44cx1z3fx27vq2r4",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "1674",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-17T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-17 07:47:50",
        "trip": {
          "id": "01m6phc12r44cx1z3fx27vq2r4",
          "tripDate": "2026-11-17",
          "departureAt": "2026-11-17T05:21:00+00:00",
          "departureLocal": "2026-11-17 07:21:00",
          "promisedArrivalAt": "2026-11-17T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-17 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0jm5dhkwsyvcwqfmjcq",
        "tripId": "01m6phc12sd17xyxd67yfvp2y5",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "5548",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-18T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-18 07:47:50",
        "trip": {
          "id": "01m6phc12sd17xyxd67yfvp2y5",
          "tripDate": "2026-11-18",
          "departureAt": "2026-11-18T05:21:00+00:00",
          "departureLocal": "2026-11-18 07:21:00",
          "promisedArrivalAt": "2026-11-18T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-18 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0jyxn98tmmz1kd7ah34",
        "tripId": "01m6phc12t8mbeke22f8ekrx4q",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "1362",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-19T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-19 07:47:50",
        "trip": {
          "id": "01m6phc12t8mbeke22f8ekrx4q",
          "tripDate": "2026-11-19",
          "departureAt": "2026-11-19T05:21:00+00:00",
          "departureLocal": "2026-11-19 07:21:00",
          "promisedArrivalAt": "2026-11-19T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-19 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0k596c57vrj1b1xcex0",
        "tripId": "01m6phc12vc7epjb6aspg8khhh",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "9434",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-22T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-22 07:47:50",
        "trip": {
          "id": "01m6phc12vc7epjb6aspg8khhh",
          "tripDate": "2026-11-22",
          "departureAt": "2026-11-22T05:21:00+00:00",
          "departureLocal": "2026-11-22 07:21:00",
          "promisedArrivalAt": "2026-11-22T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-22 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0kehm6bkzp8emsthesm",
        "tripId": "01m6phc12wt1z7s75xrkgg4gek",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6154",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-23T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-23 07:47:50",
        "trip": {
          "id": "01m6phc12wt1z7s75xrkgg4gek",
          "tripDate": "2026-11-23",
          "departureAt": "2026-11-23T05:21:00+00:00",
          "departureLocal": "2026-11-23 07:21:00",
          "promisedArrivalAt": "2026-11-23T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-23 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0kqrswzthb1fa2aed9f",
        "tripId": "01m6phc12wt1z7s75xrkgg4gem",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "3032",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-24T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-24 07:47:50",
        "trip": {
          "id": "01m6phc12wt1z7s75xrkgg4gem",
          "tripDate": "2026-11-24",
          "departureAt": "2026-11-24T05:21:00+00:00",
          "departureLocal": "2026-11-24 07:21:00",
          "promisedArrivalAt": "2026-11-24T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-24 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0m10nsvst83cr3bdgbw",
        "tripId": "01m6phc12xf5cea6kfy3mdpyn9",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6747",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-25T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-25 07:47:50",
        "trip": {
          "id": "01m6phc12xf5cea6kfy3mdpyn9",
          "tripDate": "2026-11-25",
          "departureAt": "2026-11-25T05:21:00+00:00",
          "departureLocal": "2026-11-25 07:21:00",
          "promisedArrivalAt": "2026-11-25T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-25 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0m9bm7x82br49g5x243",
        "tripId": "01m6phc12y60tvsky7gx3nrbch",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "9273",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-26T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-26 07:47:50",
        "trip": {
          "id": "01m6phc12y60tvsky7gx3nrbch",
          "tripDate": "2026-11-26",
          "departureAt": "2026-11-26T05:21:00+00:00",
          "departureLocal": "2026-11-26 07:21:00",
          "promisedArrivalAt": "2026-11-26T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-26 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0mjakhy39wpf1za5csb",
        "tripId": "01m6phc12y60tvsky7gx3nrbcj",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "4293",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-29T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-29 07:47:50",
        "trip": {
          "id": "01m6phc12y60tvsky7gx3nrbcj",
          "tripDate": "2026-11-29",
          "departureAt": "2026-11-29T05:21:00+00:00",
          "departureLocal": "2026-11-29 07:21:00",
          "promisedArrivalAt": "2026-11-29T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-29 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0mvxr938586dwbfywd3",
        "tripId": "01m6phc12zkf1mz088tm3debqh",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "5804",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-30T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-30 07:47:50",
        "trip": {
          "id": "01m6phc12zkf1mz088tm3debqh",
          "tripDate": "2026-11-30",
          "departureAt": "2026-11-30T05:21:00+00:00",
          "departureLocal": "2026-11-30 07:21:00",
          "promisedArrivalAt": "2026-11-30T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-30 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0n6gb6953k890sm7n6w",
        "tripId": "01m6phc130mqqf0spyac1rrgxm",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "5440",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:49+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-01T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-01 07:47:50",
        "trip": {
          "id": "01m6phc130mqqf0spyac1rrgxm",
          "tripDate": "2026-12-01",
          "departureAt": "2026-12-01T05:21:00+00:00",
          "departureLocal": "2026-12-01 07:21:00",
          "promisedArrivalAt": "2026-12-01T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-01 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0ng7h949gvbza3t1jcv",
        "tripId": "01m6phc13170svacy28vyhwc06",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "7665",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:50+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-02T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-02 07:47:50",
        "trip": {
          "id": "01m6phc13170svacy28vyhwc06",
          "tripDate": "2026-12-02",
          "departureAt": "2026-12-02T05:21:00+00:00",
          "departureLocal": "2026-12-02 07:21:00",
          "promisedArrivalAt": "2026-12-02T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-02 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0nr4n6sz6d5fjevcq4g",
        "tripId": "01m6phc1329q089prt7rd1w72a",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "9369",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:50+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-03T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-03 07:47:50",
        "trip": {
          "id": "01m6phc1329q089prt7rd1w72a",
          "tripDate": "2026-12-03",
          "departureAt": "2026-12-03T05:21:00+00:00",
          "departureLocal": "2026-12-03 07:21:00",
          "promisedArrivalAt": "2026-12-03T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-03 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk0p24wv7e9vpz1ywx2k3",
        "tripId": "01m6qx9qkgvzf19w6s3tc9dk9w",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "2868",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:50+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-06T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-06 07:47:50",
        "trip": {
          "id": "01m6qx9qkgvzf19w6s3tc9dk9w",
          "tripDate": "2026-12-06",
          "departureAt": "2026-12-06T05:21:00+00:00",
          "departureLocal": "2026-12-06 07:21:00",
          "promisedArrivalAt": "2026-12-06T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-06 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      }
    ],
    "seatedDays": 15,
    "skippedDays": [
      {
        "date": "2026-11-15",
        "tripDate": "2026-11-15",
        "reason": "full"
      }
    ]
  }
}
```

**GET /notifications** → `200`

Only the `seat_approved_partly` rows are shown.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6qxk0pe3fqszvnz300acr5f",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 15 يوم",
      "body": "اتأكدتي في 15 يوم. يوم 15/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "15",
        "skippedDays": "2026-11-15",
        "seatRequestId": "01m6qxk0f7q3fc0dyhvx334gyc"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:50+00:00"
    },
    {
      "id": "01m6qxhq3axrzvptmbkwarngpz",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 15 يوم",
      "body": "اتأكدتي في 15 يوم. يوم 15/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "15",
        "skippedDays": "2026-11-15",
        "seatRequestId": "01m6qxhpw4gy70s537skchrwky"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:07+00:00"
    },
    {
      "id": "01m6qxgjgb82tkjgdwhywrjppj",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 15 يوم",
      "body": "اتأكدتي في 15 يوم. يوم 15/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "15",
        "skippedDays": "2026-11-15",
        "seatRequestId": "01m6qxgj91c292tap7k0mswsmg"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:29+00:00"
    },
    {
      "id": "01m6qxfnr50qc7z9p6rz1m345m",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 15 يوم",
      "body": "اتأكدتي في 15 يوم. يوم 15/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "15",
        "skippedDays": "2026-11-15",
        "seatRequestId": "01m6qxfng3x59phwa4j9ayfet0"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:00+00:00"
    },
    {
      "id": "01m6qx9vw9xed41py8abhrz969",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 15 يوم",
      "body": "اتأكدتي في 15 يوم. يوم 15/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "15",
        "skippedDays": "2026-11-15",
        "seatRequestId": "01m6qx9vmwdbqwas0m4zqkk0pv"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:48:50+00:00"
    },
    {
      "id": "01m6qx91msep8vevjf61ajeav9",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 15 يوم",
      "body": "اتأكدتي في 15 يوم. يوم 12/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "15",
        "skippedDays": "2026-11-12",
        "seatRequestId": "01m6qx91d61k1e538bnghd8xf5"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:48:23+00:00"
    },
    {
      "id": "01m6qx8mrej111hcp2f9xjmkce",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 16 يوم",
      "body": "اتأكدتي في 16 يوم. يوم 11/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "16",
        "skippedDays": "2026-11-11",
        "seatRequestId": "01m6qx8mgwkykfpq8q9wq0yq8y"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:48:10+00:00"
    },
    {
      "id": "01m6qx7zmza54a4ydafjdg04nm",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 17 يوم",
      "body": "اتأكدتي في 17 يوم. يوم 10/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "17",
        "skippedDays": "2026-11-10",
        "seatRequestId": "01m6qx7zcrqn0zn0k3k84yts2x"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:47:48+00:00"
    },
    {
      "id": "01m6qx61ayae4y42f0b8tjyxw6",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 18 يوم",
      "body": "اتأكدتي في 18 يوم. يوم 9/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "18",
        "skippedDays": "2026-11-09",
        "seatRequestId": "01m6qx6128ppsm48rk6cwb5bgh"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:46:44+00:00"
    },
    {
      "id": "01m6qx4nvq81b2fzx8rv1x7542",
      "type": "seat_approved_partly",
      "category": "booking",
      "title": "اتأكدتي في 19 يوم",
      "body": "اتأكدتي في 19 يوم. يوم 8/11 مكانش فيهم مكان، فضفناكي لقايمة الانتظار فيهم. هنبلغك لو مكان فضي.",
      "data": {
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "seatedDays": "19",
        "skippedDays": "2026-11-08",
        "seatRequestId": "01m6qx4nhw96mc859mvxjncwm4"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:46:00+00:00"
    }
  ]
}
```

**GET /driver/commutes/{commute}/waitlist** → `200`

```json
{
  "success": true,
  "data": [
    {
      "seatRequestId": "01m6qxk0pbqcj1fh48cq29etwk",
      "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6phc12p1qbrw8kszyh4kma2",
      "seats": 1,
      "waitlistPosition": 1,
      "joinedWaitlistAt": "2026-11-06T07:53:50+00:00",
      "tripDate": "2026-11-15",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "pickup": {
        "label": "Ring Road, Rehab"
      },
      "dropoff": {
        "label": "Mehwar, Sheikh Zayed"
      },
      "availableToday": true,
      "offer": null,
      "isPreviousPassenger": true,
      "displacedAt": null,
      "displacedReason": null,
      "hasOtherConfirmedCommute": false,
      "introMessage": null,
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Layla",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
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
    "hasMore": false
  }
}
```

### One day inside the lock would break the arrival

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-06 07:53:51 UTC, locally
- **Note:** The locked day was set directly in the database: 5 hours away, with a promised arrival 1 minute after departure.

**POST /commutes/{commute}/seat-requests** with `{"commitment": "recurring", "seats": 1, "meetingPreference": "gate", "pickup": {"lat": 30.0624, "lng": 31.3614, "label": "Ring Road, Rehab"}, "dropoff": {"lat": 30.0684, "lng": 31.1015, "label": "Mehwar, Sheikh Zayed"}, "paymentType": "cash", "agreedToRules": true, "requestedDaysMask": 62}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6qxk3chrkr7c529davjfafq",
    "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
    "tripId": null,
    "status": "PENDING",
    "commitment": "recurring",
    "kind": "seat_request",
    "requestedDuringRide": false,
    "requestedDaysMask": 62,
    "seats": 1,
    "meetingPreference": "gate",
    "pickup": {
      "lat": 30.0624,
      "lng": 31.3614,
      "label": "Ring Road, Rehab",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedAt": "2026-11-06T13:19:50+00:00",
      "estimatedLocal": "2026-11-06 15:19:50"
    },
    "dropoff": {
      "lat": 30.0684,
      "lng": 31.1015,
      "label": "Mehwar, Sheikh Zayed",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedArrivalAt": "2026-11-06T14:13:27+00:00",
      "estimatedArrivalLocal": "2026-11-06 16:13:27",
      "promisedArrivalLocal": "2026-11-06 14:54:00"
    },
    "introMessage": null,
    "paymentType": "cash",
    "agreedToRulesAt": "2026-11-06T07:53:52+00:00",
    "waitlistPosition": null,
    "availableToday": true,
    "responseNote": null,
    "respondedAt": null,
    "pausedAt": null,
    "pausedUntil": null,
    "joinedWaitlistAt": null,
    "expiresAt": null,
    "createdAt": "2026-11-06T07:53:52+00:00",
    "commute": {
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
      "tripId": "01m6phc12p1qbrw8kszyh4kma2",
      "tripDate": "2026-11-15",
      "departureAt": "2026-11-06T12:53:00+00:00",
      "departureLocal": "2026-11-06 14:53:00",
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driver": {
        "publicFirstName": "Dcnobodybooked",
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
        "completedTrips": 40,
        "onTimeRate": 95
      },
      "vehicle": {
        "make": "Toyota",
        "model": "Corolla",
        "colour": "White",
        "tier": "standard",
        "hasAc": true
      }
    }
  }
}
```

**POST /driver/seat-requests/{seatRequest}/approve** → `201`

```json
{
  "success": true,
  "data": {
    "seatRequest": {
      "id": "01m6qxk3chrkr7c529davjfafq",
      "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
      "tripId": null,
      "status": "APPROVED",
      "commitment": "recurring",
      "kind": "seat_request",
      "requestedDuringRide": false,
      "requestedDaysMask": 62,
      "seats": 1,
      "meetingPreference": "gate",
      "pickup": {
        "lat": 30.0624,
        "lng": 31.3614,
        "label": "Ring Road, Rehab",
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
      "introMessage": null,
      "paymentType": "cash",
      "agreedToRulesAt": "2026-11-06T07:53:52+00:00",
      "waitlistPosition": null,
      "availableToday": true,
      "responseNote": null,
      "respondedAt": "2026-11-06T07:53:52+00:00",
      "pausedAt": null,
      "pausedUntil": null,
      "joinedWaitlistAt": null,
      "expiresAt": null,
      "createdAt": "2026-11-06T07:53:52+00:00",
      "passenger": {
        "publicFirstName": "Dina",
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
      "id": "01m6qx4rvvqsy2cempbjb2w7m7",
      "role": "member",
      "status": "ACTIVE",
      "committedDaysMask": 62,
      "joinedAt": "2026-11-06T07:46:03+00:00",
      "leftAt": null,
      "leavesOn": null
    },
    "bookings": [
      {
        "id": "01m6qxk3fff0aspmme019kjfbe",
        "tripId": "01m6phc12qvym1evd55xcympfr",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "4741",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-16T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-16 07:47:50",
        "trip": {
          "id": "01m6phc12qvym1evd55xcympfr",
          "tripDate": "2026-11-16",
          "departureAt": "2026-11-16T05:21:00+00:00",
          "departureLocal": "2026-11-16 07:21:00",
          "promisedArrivalAt": "2026-11-16T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-16 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3ftpbnzxts5eykt03b2",
        "tripId": "01m6phc12r44cx1z3fx27vq2r4",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "2898",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-17T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-17 07:47:50",
        "trip": {
          "id": "01m6phc12r44cx1z3fx27vq2r4",
          "tripDate": "2026-11-17",
          "departureAt": "2026-11-17T05:21:00+00:00",
          "departureLocal": "2026-11-17 07:21:00",
          "promisedArrivalAt": "2026-11-17T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-17 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3g4615109ewfdqn8jys",
        "tripId": "01m6phc12sd17xyxd67yfvp2y5",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6647",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-18T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-18 07:47:50",
        "trip": {
          "id": "01m6phc12sd17xyxd67yfvp2y5",
          "tripDate": "2026-11-18",
          "departureAt": "2026-11-18T05:21:00+00:00",
          "departureLocal": "2026-11-18 07:21:00",
          "promisedArrivalAt": "2026-11-18T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-18 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3gemjarg9h7stpc1q43",
        "tripId": "01m6phc12t8mbeke22f8ekrx4q",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "0484",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-19T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-19 07:47:50",
        "trip": {
          "id": "01m6phc12t8mbeke22f8ekrx4q",
          "tripDate": "2026-11-19",
          "departureAt": "2026-11-19T05:21:00+00:00",
          "departureLocal": "2026-11-19 07:21:00",
          "promisedArrivalAt": "2026-11-19T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-19 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3gq97sq77kn4cpmj2pm",
        "tripId": "01m6phc12vc7epjb6aspg8khhh",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6838",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-22T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-22 07:47:50",
        "trip": {
          "id": "01m6phc12vc7epjb6aspg8khhh",
          "tripDate": "2026-11-22",
          "departureAt": "2026-11-22T05:21:00+00:00",
          "departureLocal": "2026-11-22 07:21:00",
          "promisedArrivalAt": "2026-11-22T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-22 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3h0cr8h8397g6gzbkv4",
        "tripId": "01m6phc12wt1z7s75xrkgg4gek",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "2633",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-23T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-23 07:47:50",
        "trip": {
          "id": "01m6phc12wt1z7s75xrkgg4gek",
          "tripDate": "2026-11-23",
          "departureAt": "2026-11-23T05:21:00+00:00",
          "departureLocal": "2026-11-23 07:21:00",
          "promisedArrivalAt": "2026-11-23T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-23 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3h9xejgn58bz5vthzje",
        "tripId": "01m6phc12wt1z7s75xrkgg4gem",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "4621",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-24T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-24 07:47:50",
        "trip": {
          "id": "01m6phc12wt1z7s75xrkgg4gem",
          "tripDate": "2026-11-24",
          "departureAt": "2026-11-24T05:21:00+00:00",
          "departureLocal": "2026-11-24 07:21:00",
          "promisedArrivalAt": "2026-11-24T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-24 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3hm2r3jfpp60xnpwwwk",
        "tripId": "01m6phc12xf5cea6kfy3mdpyn9",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "3362",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-25T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-25 07:47:50",
        "trip": {
          "id": "01m6phc12xf5cea6kfy3mdpyn9",
          "tripDate": "2026-11-25",
          "departureAt": "2026-11-25T05:21:00+00:00",
          "departureLocal": "2026-11-25 07:21:00",
          "promisedArrivalAt": "2026-11-25T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-25 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3hxzm803z3db601gsdp",
        "tripId": "01m6phc12y60tvsky7gx3nrbch",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "0895",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-26T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-26 07:47:50",
        "trip": {
          "id": "01m6phc12y60tvsky7gx3nrbch",
          "tripDate": "2026-11-26",
          "departureAt": "2026-11-26T05:21:00+00:00",
          "departureLocal": "2026-11-26 07:21:00",
          "promisedArrivalAt": "2026-11-26T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-26 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3j6108b6j1tf8ba7m3z",
        "tripId": "01m6phc12y60tvsky7gx3nrbcj",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "7747",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-29T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-29 07:47:50",
        "trip": {
          "id": "01m6phc12y60tvsky7gx3nrbcj",
          "tripDate": "2026-11-29",
          "departureAt": "2026-11-29T05:21:00+00:00",
          "departureLocal": "2026-11-29 07:21:00",
          "promisedArrivalAt": "2026-11-29T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-29 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3jh3tezrt9c00prwe21",
        "tripId": "01m6phc12zkf1mz088tm3debqh",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "2154",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-11-30T05:47:50+00:00",
        "pickupEtaLocal": "2026-11-30 07:47:50",
        "trip": {
          "id": "01m6phc12zkf1mz088tm3debqh",
          "tripDate": "2026-11-30",
          "departureAt": "2026-11-30T05:21:00+00:00",
          "departureLocal": "2026-11-30 07:21:00",
          "promisedArrivalAt": "2026-11-30T07:00:00+00:00",
          "promisedArrivalLocal": "2026-11-30 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3jz4tj13xkxv02vjsdd",
        "tripId": "01m6phc130mqqf0spyac1rrgxm",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "5183",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:52+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-01T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-01 07:47:50",
        "trip": {
          "id": "01m6phc130mqqf0spyac1rrgxm",
          "tripDate": "2026-12-01",
          "departureAt": "2026-12-01T05:21:00+00:00",
          "departureLocal": "2026-12-01 07:21:00",
          "promisedArrivalAt": "2026-12-01T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-01 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3k9p1vd8s2xb0bb1br5",
        "tripId": "01m6phc13170svacy28vyhwc06",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "6898",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:53+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-02T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-02 07:47:50",
        "trip": {
          "id": "01m6phc13170svacy28vyhwc06",
          "tripDate": "2026-12-02",
          "departureAt": "2026-12-02T05:21:00+00:00",
          "departureLocal": "2026-12-02 07:21:00",
          "promisedArrivalAt": "2026-12-02T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-02 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3khzgjzsjd82ejx0jck",
        "tripId": "01m6phc1329q089prt7rd1w72a",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "7459",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:53+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-03T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-03 07:47:50",
        "trip": {
          "id": "01m6phc1329q089prt7rd1w72a",
          "tripDate": "2026-12-03",
          "departureAt": "2026-12-03T05:21:00+00:00",
          "departureLocal": "2026-12-03 07:21:00",
          "promisedArrivalAt": "2026-12-03T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-03 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      },
      {
        "id": "01m6qxk3kv65kj49jefftgtr21",
        "tripId": "01m6qx9qkgvzf19w6s3tc9dk9w",
        "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
        "membership": {
          "committedDaysMask": 62,
          "commitment": "recurring",
          "status": "ACTIVE"
        },
        "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
        "originLabel": "الرحاب، بوابة ٢",
        "destinationLabel": "القرية الذكية، مبنى B6",
        "status": "CONFIRMED",
        "seatsReserved": 1,
        "boardingCode": "5275",
        "price": {
          "seatPricePiastres": 12500,
          "totalPiastres": 12500,
          "driverSharePiastres": 11551,
          "driverShareTotalPiastres": 11551
        },
        "paymentType": "cash",
        "paymentStatus": "NOT_DUE",
        "meetingPoint": {
          "lat": 30.0624,
          "lng": 31.3614,
          "label": "Ring Road, Rehab",
          "isExact": true
        },
        "dropoffPoint": {
          "lat": 30.0684,
          "lng": 31.1015,
          "label": "Mehwar, Sheikh Zayed",
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
        "createdAt": "2026-11-06T07:53:53+00:00",
        "driverDelay": null,
        "pickupEta": "2026-12-06T05:47:50+00:00",
        "pickupEtaLocal": "2026-12-06 07:47:50",
        "trip": {
          "id": "01m6qx9qkgvzf19w6s3tc9dk9w",
          "tripDate": "2026-12-06",
          "departureAt": "2026-12-06T05:21:00+00:00",
          "departureLocal": "2026-12-06 07:21:00",
          "promisedArrivalAt": "2026-12-06T07:00:00+00:00",
          "promisedArrivalLocal": "2026-12-06 09:00:00",
          "status": "SCHEDULED",
          "seatsTotal": 3,
          "seatsTaken": 0,
          "seatsAvailable": 3,
          "seatPricePiastres": 12500,
          "pricePerSeatPiastres": 12500,
          "driverSharePiastres": 11551
        }
      }
    ],
    "seatedDays": 15,
    "skippedDays": [
      {
        "date": "2026-11-15",
        "tripDate": "2026-11-15",
        "reason": "breaks_arrival"
      }
    ]
  }
}
```

### A one-day request on that day: refused, with the times for the message

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-06 07:53:53 UTC, locally

**POST /commutes/{commute}/seat-requests** with `{"commitment": "trial", "seats": 1, "meetingPreference": "gate", "pickup": {"lat": 30.0624, "lng": 31.3614, "label": "Ring Road, Rehab"}, "dropoff": {"lat": 30.0684, "lng": 31.1015, "label": "Mehwar, Sheikh Zayed"}, "paymentType": "cash", "agreedToRules": true, "scheduledTripId": "01m6phc12p1qbrw8kszyh4kma2"}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6qxk4p4yfxm1pcxwrefe40t",
    "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
    "tripId": "01m6phc12p1qbrw8kszyh4kma2",
    "status": "PENDING",
    "commitment": "trial",
    "kind": "seat_request",
    "requestedDuringRide": false,
    "requestedDaysMask": null,
    "seats": 1,
    "meetingPreference": "gate",
    "pickup": {
      "lat": 30.0624,
      "lng": 31.3614,
      "label": "Ring Road, Rehab",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedAt": "2026-11-06T13:19:50+00:00",
      "estimatedLocal": "2026-11-06 15:19:50"
    },
    "dropoff": {
      "lat": 30.0684,
      "lng": 31.1015,
      "label": "Mehwar, Sheikh Zayed",
      "source": "custom_map",
      "suggestionId": null,
      "estimatedArrivalAt": "2026-11-06T14:13:27+00:00",
      "estimatedArrivalLocal": "2026-11-06 16:13:27",
      "promisedArrivalLocal": "2026-11-06 14:54:00"
    },
    "introMessage": null,
    "paymentType": "cash",
    "agreedToRulesAt": "2026-11-06T07:53:54+00:00",
    "waitlistPosition": null,
    "availableToday": true,
    "responseNote": null,
    "respondedAt": null,
    "pausedAt": null,
    "pausedUntil": null,
    "joinedWaitlistAt": null,
    "expiresAt": null,
    "createdAt": "2026-11-06T07:53:54+00:00",
    "commute": {
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "commuteGroupId": "01m6qx4nmsy9d51s55zk5qdzgy",
      "tripId": "01m6phc12p1qbrw8kszyh4kma2",
      "tripDate": "2026-11-15",
      "departureAt": "2026-11-06T12:53:00+00:00",
      "departureLocal": "2026-11-06 14:53:00",
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driver": {
        "publicFirstName": "Dcnobodybooked",
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
        "completedTrips": 40,
        "onTimeRate": 95
      },
      "vehicle": {
        "make": "Toyota",
        "model": "Corolla",
        "colour": "White",
        "tier": "standard",
        "hasAc": true
      }
    }
  }
}
```

**POST /driver/seat-requests/{seatRequest}/approve** → `409`

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_BREAKS_FROZEN_PROMISE",
    "message": "Adding this seat would make the commute arrive later than the passengers on board were promised.",
    "fields": {
      "promisedArrivalAt": [
        "2026-11-06T12:54:00+00:00"
      ],
      "projectedArrivalAt": [
        "2026-11-06T14:32:01+00:00"
      ],
      "lockedFrom": [
        "2026-11-06T00:53:00+00:00"
      ]
    }
  }
}
```

## 3. pickup_time_changed

### A confirmed passenger's pickup moves

- **State:** `DC-PASSENGERS`
- **Phone:** `+201055557501`
- **Captured:** 2026-11-06 07:53:55 UTC, locally
- **Note:** The re-time normally runs in a background job after a passenger joins. Here the day was moved 40 minutes later in the database and the job's action was run directly, so the departure moves earlier.

**GET /my-bookings?scope=upcoming&perPage=1** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pj9z7exaxpn943qwdk33bj",
      "tripId": "01m6phc5ywbcetz8x0vz78cgwm",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phc5r8pp9qfrrwd521dwqc",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "driver": {
        "publicFirstName": "Dctripstarted",
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
        "completedTrips": 40,
        "onTimeRate": 95
      },
      "status": "CONFIRMED",
      "seatsReserved": 1,
      "boardingCode": null,
      "price": {
        "seatPricePiastres": 12500,
        "totalPiastres": 12500
      },
      "paymentType": "cash",
      "paymentStatus": "NOT_DUE",
      "meetingPoint": {
        "lat": 30.06264,
        "lng": 31.395519999999998,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.06996,
        "lng": 31.107580000000002,
        "label": "Smart Village B12",
        "isExact": true
      },
      "otherPartyUserId": null,
      "cancelledAt": null,
      "cancelledReason": null,
      "outcomeSeenAt": null,
      "pickupNote": null,
      "cancellationFeePiastres": 0,
      "feeLinePiastres": 0,
      "feeLineReason": null,
      "earlyDrop": {
        "askedAt": null,
        "distanceMeters": null,
        "answer": null
      },
      "createdAt": "2026-11-05T19:17:24+00:00",
      "driverDelay": "late_for_pickup",
      "pickupEta": "2026-11-05T19:41:48+00:00",
      "pickupEtaLocal": "2026-11-05 21:41:48",
      "myRating": null,
      "trip": {
        "id": "01m6phc5ywbcetz8x0vz78cgwm",
        "tripDate": "2026-11-05",
        "departureAt": "2026-11-05T19:22:00+00:00",
        "departureLocal": "2026-11-05 21:22:00",
        "promisedArrivalAt": "2026-11-05T21:22:00+00:00",
        "promisedArrivalLocal": "2026-11-05 23:22:00",
        "status": "PREPARING",
        "seatsTotal": 3,
        "seatsTaken": 1,
        "seatsAvailable": 2,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 1,
    "total": 13,
    "lastPage": 13,
    "hasMore": true
  }
}
```

**GET /notifications** → `200`

Only the `pickup_time_changed` rows are shown.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6qxk7f12swt2a9t6pn7088z",
      "type": "pickup_time_changed",
      "category": "booking",
      "title": "ميعاد ركوبك اتغيّر",
      "body": "ميعاد ركوبك يوم 2026-11-18 بقى الساعة 07:40.",
      "data": {
        "bookingId": "01m6qxk54yvxc3g7jtccx567pq",
        "pickupEta": "2026-11-18T05:40:48+00:00",
        "pickupEtaLocal": "2026-11-18 07:40:48",
        "scheduledTripId": "01m6phbr69z5tabfgfhyr7yz0m"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:56+00:00"
    },
    {
      "id": "01m6qxhy5gf6tm82zj1d5jfebw",
      "type": "pickup_time_changed",
      "category": "booking",
      "title": "ميعاد ركوبك اتغيّر",
      "body": "ميعاد ركوبك يوم 2026-11-17 بقى الساعة 07:40.",
      "data": {
        "bookingId": "01m6qxhvjz37k1tsytsq07et4q",
        "pickupEta": "2026-11-17T05:40:48+00:00",
        "pickupEtaLocal": "2026-11-17 07:40:48",
        "scheduledTripId": "01m6phbr686phy5xyv85x9eter"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:14+00:00"
    },
    {
      "id": "01m6qxgs8x81zgrfmc14jxp75m",
      "type": "pickup_time_changed",
      "category": "booking",
      "title": "ميعاد ركوبك اتغيّر",
      "body": "ميعاد ركوبك يوم 2026-11-16 بقى الساعة 07:40.",
      "data": {
        "bookingId": "01m6qxgpy29g81p696nvd8t68g",
        "pickupEta": "2026-11-16T05:40:48+00:00",
        "pickupEtaLocal": "2026-11-16 07:40:48",
        "scheduledTripId": "01m6phbr66b3zshq7bw8875xtj"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:36+00:00"
    },
    {
      "id": "01m6qxfwd897s56s6c157b63mt",
      "type": "pickup_time_changed",
      "category": "booking",
      "title": "ميعاد ركوبك اتغيّر",
      "body": "ميعاد ركوبك يوم 2026-11-15 بقى الساعة 07:40.",
      "data": {
        "bookingId": "01m6qxft3qgqb65yvew0mm0fnx",
        "pickupEta": "2026-11-15T05:40:48+00:00",
        "pickupEtaLocal": "2026-11-15 07:40:48",
        "scheduledTripId": "01m6phbr65ff13e2yfrwpcpa3a"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:07+00:00"
    }
  ]
}
```

## 4. Car registration: expiring, expired, renewal

### Expiring: warned from 30 days before

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-06 07:53:58 UTC, locally
- **Note:** The expiry date was set to 20 days ahead, then `registrations:warn-expiring` was run.

**GET /notifications** → `200`

Only the `driver_item_expiring` rows are shown.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6qxk9c9f7d2wm6b9dq6raj2",
      "type": "driver_item_expiring",
      "category": "safety",
      "title": "Car registration expires soon",
      "body": "It expires on 2026-11-26. Upload the renewed one to keep driving.",
      "data": {
        "itemKey": "vehicle_registration",
        "expiresAt": "2026-11-26T00:00:00+00:00",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:58+00:00"
    },
    {
      "id": "01m6qxj0922g3m758sb03efs10",
      "type": "driver_item_expiring",
      "category": "safety",
      "title": "Car registration expires soon",
      "body": "It expires on 2026-11-26. Upload the renewed one to keep driving.",
      "data": {
        "itemKey": "vehicle_registration",
        "expiresAt": "2026-11-26T00:00:00+00:00",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:16+00:00"
    },
    {
      "id": "01m6qxgv43zmzr38ewrk1hyzv9",
      "type": "driver_item_expiring",
      "category": "safety",
      "title": "Car registration expires soon",
      "body": "It expires on 2026-11-26. Upload the renewed one to keep driving.",
      "data": {
        "itemKey": "vehicle_registration",
        "expiresAt": "2026-11-26T00:00:00+00:00",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:38+00:00"
    },
    {
      "id": "01m6qxfy81bqan95tpfkw7shgb",
      "type": "driver_item_expiring",
      "category": "safety",
      "title": "Car registration expires soon",
      "body": "It expires on 2026-11-26. Upload the renewed one to keep driving.",
      "data": {
        "itemKey": "vehicle_registration",
        "expiresAt": "2026-11-26T00:00:00+00:00",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:09+00:00"
    }
  ]
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
        "expiresAt": "2029-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": "2026-11-26T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "approved",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-06T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

### Expired: the car is suspended and its commutes pause

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-06 07:53:59 UTC, locally
- **Note:** The expiry date was set to yesterday, then `registrations:stop-expired` was run.

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc0x8v4yme6xf47y8mvhz",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7503",
      "seats": 4,
      "approvedSeats": 3,
      "tier": "standard",
      "hasAc": true,
      "transmission": "automatic",
      "fuelType": "petrol",
      "isActive": true,
      "status": "SUSPENDED",
      "suspensionReason": "registration_expired",
      "registrationRenewalSubmittedAt": null,
      "rejectionReason": null,
      "documents": [
        {
          "id": "01m6qxj1aqa7ey0mc6rrwqdnqa",
          "type": "registration",
          "status": "PENDING",
          "expiresAt": "2026-11-05"
        }
      ]
    }
  ]
}
```

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc0xa5qh8tyg2wy3s0aw4",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz",
      "seatsTotal": 3,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driverSharePiastres": 11551,
      "currency": "EGP",
      "maxDetourMinutes": 30,
      "maxWalkMinutes": 18,
      "audience": "any_verified",
      "minTrustLevel": 0,
      "allowsCustomPickup": true,
      "routePolyline": "gcnvD{te_EgkAjv|A",
      "routeDistanceMeters": 46202,
      "routeDurationSeconds": 5940,
      "corridorId": "01m6hf9f3h286exdz6ttbzt92n",
      "createdAt": "2026-11-05T19:01:03+00:00",
      "updatedAt": "2026-11-06T07:53:59+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:03+00:00",
      "pausedAt": "2026-11-06T07:53:59+00:00",
      "pausedReason": "vehicle_suspended",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc0xr3y17s1r79hycb4tv",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc0xtym1s1jqecb9gp0vj",
          "type": "destination",
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "placeId": "01m6hf9f3ckmpvgs75rye4hwee",
          "sequence": 999
        }
      ],
      "schedule": {
        "daysMask": 62,
        "departureTime": "06:51:00",
        "timezone": "Africa/Cairo",
        "startDate": "2026-11-05",
        "endDate": "2027-03-05",
        "generatedUntil": "2026-12-06"
      },
      "weekdays": [
        {
          "weekdayBit": 2,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 4,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 8,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 16,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 32,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        }
      ],
      "rules": {}
    }
  ]
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "renewal_required",
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
        "expiresAt": "2029-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "expired",
        "rejectionReason": null,
        "expiresAt": "2026-11-05T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-06T00:00:00+00:00",
    "actionItem": {
      "key": "vehicle_registration",
      "status": "expired",
      "rejectionReason": null
    },
    "suspensionReason": null
  }
}
```

**GET /notifications** → `200`

Only the `vehicle_registration_expired` rows are shown.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6qxka0ne6bcsefb8sv5se8e",
      "type": "vehicle_registration_expired",
      "category": "booking",
      "title": "Your car registration has expired",
      "body": "Your commutes are paused and your bookings were cancelled. Upload the new registration, and you can resume once we approve it.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:59+00:00"
    },
    {
      "id": "01m6qxj0wn6wmtcnnzfg0y5kzb",
      "type": "vehicle_registration_expired",
      "category": "booking",
      "title": "Your car registration has expired",
      "body": "Your commutes are paused and your bookings were cancelled. Upload the new registration, and you can resume once we approve it.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:17+00:00"
    },
    {
      "id": "01m6qxgvqjnawn05xfjcty7ja6",
      "type": "vehicle_registration_expired",
      "category": "booking",
      "title": "Your car registration has expired",
      "body": "Your commutes are paused and your bookings were cancelled. Upload the new registration, and you can resume once we approve it.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:39+00:00"
    },
    {
      "id": "01m6qxfyvz5k2f663emtjtnzkk",
      "type": "vehicle_registration_expired",
      "category": "booking",
      "title": "Your car registration has expired",
      "body": "Your commutes are paused and your bookings were cancelled. Upload the new registration, and you can resume once we approve it.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:09+00:00"
    }
  ]
}
```

**POST /commutes/{commute}/resume** → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_VEHICLE_UNAVAILABLE",
    "message": "That vehicle is not approved and active, so it cannot be used for a commute.",
    "fields": null
  }
}
```

### Renewal uploaded: under review

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-06 07:53:59 UTC, locally

**POST /driver/vehicles/{vehicle}/documents** → `201`

Multipart: `type=registration`, `file`.

```json
{
  "success": true,
  "data": {
    "id": "01m6phc0x8v4yme6xf47y8mvhz",
    "make": "Toyota",
    "model": "Corolla",
    "year": 2020,
    "colour": "White",
    "plateNumber": "DH 7503",
    "seats": 4,
    "approvedSeats": 3,
    "tier": "standard",
    "hasAc": true,
    "transmission": "automatic",
    "fuelType": "petrol",
    "isActive": true,
    "status": "SUSPENDED",
    "suspensionReason": "registration_expired",
    "registrationRenewalSubmittedAt": "2026-11-06T07:54:00+00:00",
    "rejectionReason": null,
    "documents": [
      {
        "id": "01m6qxkafpfbk3chrctaedww80",
        "type": "registration",
        "status": "PENDING",
        "expiresAt": "2026-11-05"
      }
    ]
  }
}
```

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc0x8v4yme6xf47y8mvhz",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7503",
      "seats": 4,
      "approvedSeats": 3,
      "tier": "standard",
      "hasAc": true,
      "transmission": "automatic",
      "fuelType": "petrol",
      "isActive": true,
      "status": "SUSPENDED",
      "suspensionReason": "registration_expired",
      "registrationRenewalSubmittedAt": "2026-11-06T07:54:00+00:00",
      "rejectionReason": null,
      "documents": [
        {
          "id": "01m6qxkafpfbk3chrctaedww80",
          "type": "registration",
          "status": "PENDING",
          "expiresAt": "2026-11-05"
        }
      ]
    }
  ]
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
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
        "expiresAt": "2029-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "under_review",
        "rejectionReason": null,
        "expiresAt": "2026-11-05T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-06T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

### Renewal approved

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-06 07:54:00 UTC, locally
- **Note:** The admin's approval was run directly, with a new date one year ahead.

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc0x8v4yme6xf47y8mvhz",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7503",
      "seats": 4,
      "approvedSeats": 3,
      "tier": "standard",
      "hasAc": true,
      "transmission": "automatic",
      "fuelType": "petrol",
      "isActive": true,
      "status": "APPROVED",
      "suspensionReason": null,
      "registrationRenewalSubmittedAt": null,
      "rejectionReason": null,
      "documents": [
        {
          "id": "01m6qxkafpfbk3chrctaedww80",
          "type": "registration",
          "status": "PENDING",
          "expiresAt": "2027-11-06"
        }
      ]
    }
  ]
}
```

**GET /notifications** → `200`

Only the `vehicle_reinstated` rows are shown.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6qxkav01mytn7vvxfcd5r1z",
      "type": "vehicle_reinstated",
      "category": "booking",
      "title": "Your car is approved again",
      "body": "You can resume your commutes now. Cancelled bookings do not come back automatically.",
      "data": {
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:54:00+00:00"
    },
    {
      "id": "01m6qxj1p3y05rma6mtnsdhxt0",
      "type": "vehicle_reinstated",
      "category": "booking",
      "title": "Your car is approved again",
      "body": "You can resume your commutes now. Cancelled bookings do not come back automatically.",
      "data": {
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:18+00:00"
    },
    {
      "id": "01m6qxgwhr7nb0230h29sx9bcg",
      "type": "vehicle_reinstated",
      "category": "booking",
      "title": "Your car is approved again",
      "body": "You can resume your commutes now. Cancelled bookings do not come back automatically.",
      "data": {
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:40+00:00"
    },
    {
      "id": "01m6qxfzmrhd8cqvrrbz4rvfgn",
      "type": "vehicle_reinstated",
      "category": "booking",
      "title": "Your car is approved again",
      "body": "You can resume your commutes now. Cancelled bookings do not come back automatically.",
      "data": {
        "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:10+00:00"
    }
  ]
}
```

**POST /commutes/{commute}/resume** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6phc0xa5qh8tyg2wy3s0aw4",
    "status": "PUBLISHED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6phc0x8v4yme6xf47y8mvhz",
    "seatsTotal": 3,
    "seatPricePiastres": 12500,
    "pricePerSeatPiastres": 12500,
    "driverSharePiastres": 11551,
    "currency": "EGP",
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "any_verified",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": "gcnvD{te_EgkAjv|A",
    "routeDistanceMeters": 46202,
    "routeDurationSeconds": 5940,
    "corridorId": "01m6hf9f3h286exdz6ttbzt92n",
    "createdAt": "2026-11-05T19:01:03+00:00",
    "updatedAt": "2026-11-06T07:54:00+00:00",
    "formStep": null,
    "publishedAt": "2026-11-05T19:01:03+00:00",
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6phc0xr3y17s1r79hycb4tv",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6phc0xtym1s1jqecb9gp0vj",
        "type": "destination",
        "lat": 30.0724,
        "lng": 31.0116,
        "address": "القرية الذكية، مبنى B6",
        "placeId": "01m6hf9f3ckmpvgs75rye4hwee",
        "sequence": 999
      }
    ],
    "schedule": {
      "daysMask": 62,
      "departureTime": "06:51:00",
      "timezone": "Africa/Cairo",
      "startDate": "2026-11-05",
      "endDate": "2027-03-05",
      "generatedUntil": "2026-12-06"
    },
    "weekdays": [
      {
        "weekdayBit": 2,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:21:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 4,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:21:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 8,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:21:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 16,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:21:00",
        "departureIsComputed": true,
        "seatsTotal": null
      },
      {
        "weekdayBit": 32,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:21:00",
        "departureIsComputed": true,
        "seatsTotal": null
      }
    ],
    "rules": {}
  }
}
```

## 5. Car suspended after a report

### Suspended by an admin

- **State:** `DC-REPLACE`
- **Phone:** `+201055557571`
- **Captured:** 2026-11-06 07:54:02 UTC, locally
- **Note:** The admin's action was run directly.

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phcabpe0tcnvmtbsv706td",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7571",
      "seats": 4,
      "approvedSeats": 3,
      "tier": "standard",
      "hasAc": true,
      "transmission": "automatic",
      "fuelType": "petrol",
      "isActive": true,
      "status": "SUSPENDED",
      "suspensionReason": "report",
      "registrationRenewalSubmittedAt": null,
      "rejectionReason": null,
      "documents": [
        {
          "id": "01m6phcan9z7kh4vbhsf8mxa5b",
          "type": "registration",
          "status": "APPROVED",
          "expiresAt": "2027-11-06"
        }
      ]
    }
  ]
}
```

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phcabr69s361b84dcac6v8",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phcabpe0tcnvmtbsv706td",
      "seatsTotal": 3,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driverSharePiastres": 11551,
      "currency": "EGP",
      "maxDetourMinutes": 30,
      "maxWalkMinutes": 18,
      "audience": "any_verified",
      "minTrustLevel": 0,
      "allowsCustomPickup": true,
      "routePolyline": "gcnvD{te_EgkAjv|A",
      "routeDistanceMeters": 46202,
      "routeDurationSeconds": 5940,
      "corridorId": "01m6hf9f3h286exdz6ttbzt92n",
      "createdAt": "2026-11-05T19:01:13+00:00",
      "updatedAt": "2026-11-06T07:54:02+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:13+00:00",
      "pausedAt": "2026-11-06T07:54:02+00:00",
      "pausedReason": "vehicle_suspended",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phcackmzmgcmqb19yn0em8",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phcacmhkca3zsqrx8tq3yf",
          "type": "destination",
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "placeId": "01m6hf9f3ckmpvgs75rye4hwee",
          "sequence": 999
        }
      ],
      "schedule": {
        "daysMask": 62,
        "departureTime": "07:00:00",
        "timezone": "Africa/Cairo",
        "startDate": "2026-11-05",
        "endDate": "2027-03-05",
        "generatedUntil": "2026-12-06"
      },
      "weekdays": [
        {
          "weekdayBit": 2,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 4,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 8,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 16,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        },
        {
          "weekdayBit": 32,
          "promisedArrivalTime": "09:00:00",
          "departureTime": "07:21:00",
          "departureIsComputed": true,
          "seatsTotal": null
        }
      ],
      "rules": {}
    }
  ]
}
```

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "verified",
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
        "expiresAt": "2029-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": "2027-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-06T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

**GET /notifications** → `200`

Only the `vehicle_suspended` rows are shown.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6qxkcqyex2v2jejqcda08e9",
      "type": "vehicle_suspended",
      "category": "booking",
      "title": "Your car was suspended",
      "body": "Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phcabpe0tcnvmtbsv706td"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:54:02+00:00"
    },
    {
      "id": "01m6qxj3k8at0nxk9x3r3ahgfp",
      "type": "vehicle_suspended",
      "category": "booking",
      "title": "Your car was suspended",
      "body": "Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phcabpe0tcnvmtbsv706td"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:53:20+00:00"
    },
    {
      "id": "01m6qxgycax5h7k5zkx2t8tt30",
      "type": "vehicle_suspended",
      "category": "booking",
      "title": "Your car was suspended",
      "body": "Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phcabpe0tcnvmtbsv706td"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:42+00:00"
    },
    {
      "id": "01m6qxg1dwen9a1ac3765d4cj7",
      "type": "vehicle_suspended",
      "category": "booking",
      "title": "Your car was suspended",
      "body": "Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phcabpe0tcnvmtbsv706td"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:52:12+00:00"
    },
    {
      "id": "01m6qxa7t1cb313sa99b1ekn4j",
      "type": "vehicle_suspended",
      "category": "booking",
      "title": "Your car was suspended",
      "body": "Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phcabpe0tcnvmtbsv706td"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:49:02+00:00"
    },
    {
      "id": "01m6qx6d649aa8wkvzp7z0ve8j",
      "type": "vehicle_suspended",
      "category": "booking",
      "title": "Your car was suspended",
      "body": "Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phcabpe0tcnvmtbsv706td"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:46:56+00:00"
    },
    {
      "id": "01m6qx51v7g1mwatkdxkhkc6mw",
      "type": "vehicle_suspended",
      "category": "booking",
      "title": "Your car was suspended",
      "body": "Your commutes are paused after we reviewed a report about your car. Contact support to find out what is needed.",
      "data": {
        "itemKey": "vehicle_registration",
        "vehicleId": "01m6phcabpe0tcnvmtbsv706td"
      },
      "isRead": false,
      "readAt": null,
      "createdAt": "2026-11-06T07:46:12+00:00"
    }
  ]
}
```

**POST /driver/vehicles/{vehicle}/documents** → `409`

Multipart: `type=registration`, `file`. Refused, because the driver can't upload their way out of this.

```json
{
  "success": false,
  "error": {
    "code": "DRIVER_APPLICATION_LOCKED",
    "message": "Your application is being reviewed and cannot be changed. Withdraw it first if you need to make a correction.",
    "fields": null
  }
}
```

## 6. Verification items when the driver or account is suspended

### Driver suspended: all items

- **State:** `DC-PAUSED-DRIVER`
- **Phone:** `+201055557516`
- **Captured:** 2026-11-06 07:54:04 UTC, locally

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
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": "2029-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": "2027-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-06T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": "Repeated no-show reports."
  }
}
```

### Account suspended: all items

- **State:** `DC-PAUSED-ACCOUNT`
- **Phone:** `+201055557515`
- **Captured:** 2026-11-06 07:54:05 UTC, locally

**GET /driver/verification** → `200`

```json
{
  "success": true,
  "data": {
    "overall": "suspended",
    "completed": 5,
    "of": 5,
    "items": [
      {
        "key": "government_id",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "selfie",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      },
      {
        "key": "driving_licence",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": "2029-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_registration",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": "2027-11-06T00:00:00+00:00"
      },
      {
        "key": "vehicle_details",
        "status": "suspended",
        "rejectionReason": null,
        "expiresAt": null
      }
    ],
    "licenceExpiresAt": "2029-11-06T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  }
}
```

## 7. Live run

### Call moves to the next passenger after a check-in

- **State:** `DC-ITINERARY`
- **Phone:** `+201055557551`
- **Captured:** 2026-11-06 07:54:09 UTC, locally
- **Note:** The departure was set 4 hours ago in the database, so the second passenger is entitled to leave.

**GET /bookings/{secondBooking}/call** → `409`

```json
{
  "success": false,
  "error": {
    "code": "CALL_NOT_AVAILABLE",
    "message": "A phone number is not available for this booking right now.",
    "fields": {
      "reason": [
        "not_next_pickup"
      ]
    }
  }
}
```

**POST /trips/{trip}/check-in** with `{"bookingId": "01m6qxkgcjezbs9j1myyw5kmcw", "lat": 30.0654, "lng": 31.2314, "recordedAt": "2026-11-06T07:54:09+00:00", "accuracyMeters": 12, "speedKmh": 48}` → `200`

```json
{
  "success": true,
  "data": {
    "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
    "status": "PRESENT",
    "boardingCode": null,
    "travelled": true,
    "checkedInAt": "2026-11-06T07:54:09+00:00",
    "checkedOutAt": null,
    "confirmedBy": "driver",
    "confirmedAt": "2026-11-06T07:54:09+00:00",
    "gpsCorroborated": false,
    "gpsConfidence": 0,
    "disputedAt": null,
    "disputeReason": null,
    "disputeResolution": null,
    "passengerUserId": null
  }
}
```

**GET /driver/commute-occurrences/{trip}/navigation-route** → `200`

```json
{
  "success": true,
  "data": {
    "status": "CONFIRMED",
    "stops": [
      {
        "id": "01M6QXKHV1V120VK68FS46WX9F",
        "kind": "DRIVER_START",
        "sequence": 0,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0602,
        "lng": 31.4915,
        "label": "الرحاب، بوابة ٢",
        "etaAt": "2026-11-06T03:54:00+00:00",
        "legDurationSeconds": 0,
        "legDistanceMeters": 0,
        "reachedAt": null
      },
      {
        "id": "01M6QXKHV3PV39DHWGKMS0Y3AJ",
        "kind": "PASSENGER_PICKUP",
        "sequence": 1,
        "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
        "passengerFirstName": "Salma",
        "lat": 30.06264,
        "lng": 31.39552,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-06T04:13:48+00:00",
        "legDurationSeconds": 1188,
        "legDistanceMeters": 9241,
        "reachedAt": "2026-11-06T07:54:09+00:00"
      },
      {
        "id": "01M6QXKHV4F606E4P645KFFMQ5",
        "kind": "PASSENGER_PICKUP",
        "sequence": 2,
        "bookingId": "01m6qxkgd0t1qc9bppr8xefjtr",
        "passengerFirstName": "Nour",
        "lat": 30.06508,
        "lng": 31.29954,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-06T04:33:36+00:00",
        "legDurationSeconds": 1188,
        "legDistanceMeters": 9241,
        "reachedAt": null
      },
      {
        "id": "01M6QXKHV5SGK8T5465MCSN7Z9",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 3,
        "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
        "passengerFirstName": "Salma",
        "lat": 30.06874,
        "lng": 31.15557,
        "label": "Smart Village B12",
        "etaAt": "2026-11-06T05:03:18+00:00",
        "legDurationSeconds": 1782,
        "legDistanceMeters": 13861,
        "reachedAt": null
      },
      {
        "id": "01M6QXKHV6V2B0HWHMFCPV1XXF",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 4,
        "bookingId": "01m6qxkgd0t1qc9bppr8xefjtr",
        "passengerFirstName": "Nour",
        "lat": 30.07118,
        "lng": 31.05959,
        "label": "Smart Village B12",
        "etaAt": "2026-11-06T05:23:06+00:00",
        "legDurationSeconds": 1188,
        "legDistanceMeters": 9240,
        "reachedAt": null
      },
      {
        "id": "01M6QXKHV7GZSH838Z1VBG116E",
        "kind": "DRIVER_END",
        "sequence": 5,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0724,
        "lng": 31.0116,
        "label": "القرية الذكية، مبنى B6",
        "etaAt": "2026-11-06T05:33:00+00:00",
        "legDurationSeconds": 594,
        "legDistanceMeters": 4620,
        "reachedAt": null
      }
    ],
    "pointCount": 6,
    "polyline": "gcnvD{te_EgNzvQgNzvQ{Uxb[gNzvQsF|jH",
    "distanceMeters": 46203,
    "durationSeconds": 5940,
    "googleMapsDirectionsUrl": "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.062640%2C31.395520%7C30.065080%2C31.299540%7C30.068740%2C31.155570%7C30.071180%2C31.059590",
    "exceedsWaypointLimit": false,
    "waypointLimit": 9,
    "segments": [
      "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.062640%2C31.395520%7C30.065080%2C31.299540%7C30.068740%2C31.155570%7C30.071180%2C31.059590"
    ],
    "receivesRequestsDuringRide": true
  }
}
```

**GET /bookings/{secondBooking}/call** → `200`

```json
{
  "success": true,
  "data": {
    "phone": "+201055557602",
    "name": "Nour"
  }
}
```

### A passenger already aboard can't exit without a fee

- **State:** `DC-ITINERARY`
- **Phone:** `+201055557551`
- **Captured:** 2026-11-06 07:54:09 UTC, locally

**POST /bookings/{firstBooking}/exit-without-fee** → `409`

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
        "2026-11-06T03:54:00+00:00"
      ]
    }
  }
}
```

### Late exit: attendance, route and Call

- **State:** `DC-ITINERARY`
- **Phone:** `+201055557551`
- **Captured:** 2026-11-06 07:54:09 UTC, locally

**POST /bookings/{secondBooking}/exit-without-fee** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6qxkgd0t1qc9bppr8xefjtr",
    "tripId": "01m6phc8jtq5v593fwfn4q85kb",
    "commuteGroupId": null,
    "membership": null,
    "status": "EXITED_DUE_TO_DRIVER_DELAY",
    "seatsReserved": 1,
    "boardingCode": null,
    "price": {
      "seatPricePiastres": 12500,
      "totalPiastres": 12500
    },
    "paymentType": "cash",
    "paymentStatus": "NOT_DUE",
    "meetingPoint": {
      "lat": 30.06508,
      "lng": 31.29954,
      "label": "Al Rehab Mall entrance",
      "isExact": true
    },
    "dropoffPoint": {
      "lat": 30.07118,
      "lng": 31.05959,
      "label": "Smart Village B12",
      "isExact": true
    },
    "otherPartyUserId": "01m6phc8brv4zq6qb8pyr5hy6d",
    "cancelledAt": "2026-11-06T07:54:09+00:00",
    "cancelledReason": "late_for_pickup",
    "outcomeSeenAt": null,
    "pickupNote": null,
    "cancellationFeePiastres": 0,
    "feeLinePiastres": 0,
    "feeLineReason": null,
    "earlyDrop": {
      "askedAt": null,
      "distanceMeters": null,
      "answer": null
    },
    "createdAt": "2026-11-06T07:54:06+00:00"
  }
}
```

**GET /trips/{trip}/attendance** → `200`

```json
{
  "success": true,
  "data": [
    {
      "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
      "status": "PRESENT",
      "bookingStatus": "CONFIRMED",
      "boardingCode": null,
      "travelled": true,
      "checkedInAt": "2026-11-06T07:54:09+00:00",
      "checkedOutAt": null,
      "confirmedBy": "driver",
      "confirmedAt": "2026-11-06T07:54:09+00:00",
      "gpsCorroborated": false,
      "gpsConfidence": 0,
      "disputedAt": null,
      "disputeReason": null,
      "disputeResolution": null,
      "passengerUserId": null,
      "person": {
        "publicFirstName": "Salma",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    },
    {
      "bookingId": "01m6qxkgd0t1qc9bppr8xefjtr",
      "status": "CANCELLED",
      "bookingStatus": "EXITED_DUE_TO_DRIVER_DELAY",
      "boardingCode": null,
      "travelled": false,
      "checkedInAt": null,
      "checkedOutAt": null,
      "confirmedBy": null,
      "confirmedAt": null,
      "gpsCorroborated": false,
      "gpsConfidence": null,
      "disputedAt": null,
      "disputeReason": null,
      "disputeResolution": null,
      "passengerUserId": "01m6phbrbtjadxh6d4725qkhyg",
      "person": {
        "publicFirstName": "Nour",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    }
  ]
}
```

**GET /driver/commute-occurrences/{trip}/navigation-route** → `200`

```json
{
  "success": true,
  "data": {
    "status": "CONFIRMED",
    "stops": [
      {
        "id": "01M6QXKKWGPBK89C33C588BETH",
        "kind": "DRIVER_START",
        "sequence": 0,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0602,
        "lng": 31.4915,
        "label": "الرحاب، بوابة ٢",
        "etaAt": "2026-11-06T03:54:00+00:00",
        "legDurationSeconds": 0,
        "legDistanceMeters": 0,
        "reachedAt": null
      },
      {
        "id": "01M6QXKKWH9E8864X3FG3SP0HB",
        "kind": "PASSENGER_PICKUP",
        "sequence": 1,
        "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
        "passengerFirstName": "Salma",
        "lat": 30.06264,
        "lng": 31.39552,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-06T04:13:48+00:00",
        "legDurationSeconds": 1188,
        "legDistanceMeters": 9241,
        "reachedAt": "2026-11-06T07:54:09+00:00"
      },
      {
        "id": "01M6QXKKWJ607PT1M1BRBEV565",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 2,
        "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
        "passengerFirstName": "Salma",
        "lat": 30.06874,
        "lng": 31.15557,
        "label": "Smart Village B12",
        "etaAt": "2026-11-06T05:03:18+00:00",
        "legDurationSeconds": 2970,
        "legDistanceMeters": 23101,
        "reachedAt": null
      },
      {
        "id": "01M6QXKKWK4P39K0G7GNYCEFHJ",
        "kind": "DRIVER_END",
        "sequence": 3,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0724,
        "lng": 31.0116,
        "label": "القرية الذكية، مبنى B6",
        "etaAt": "2026-11-06T05:33:00+00:00",
        "legDurationSeconds": 1782,
        "legDistanceMeters": 13860,
        "reachedAt": null
      }
    ],
    "pointCount": 4,
    "polyline": "gcnvD{te_EgNzvQce@tzm@{Uxb[",
    "distanceMeters": 46202,
    "durationSeconds": 5940,
    "googleMapsDirectionsUrl": "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.062640%2C31.395520%7C30.068740%2C31.155570",
    "exceedsWaypointLimit": false,
    "waypointLimit": 9,
    "segments": [
      "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.062640%2C31.395520%7C30.068740%2C31.155570"
    ],
    "receivesRequestsDuringRide": true
  }
}
```

**POST /trips/{trip}/check-in** with `{"bookingId": "01m6qxkgd0t1qc9bppr8xefjtr", "lat": 30.0654, "lng": 31.2314, "recordedAt": "2026-11-06T07:54:09+00:00", "accuracyMeters": 12, "speedKmh": 48}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "ATTENDANCE_NOT_CONFIRMABLE",
    "message": "This passenger cannot be confirmed right now.",
    "fields": {
      "currentStatus": [
        "CANCELLED"
      ]
    }
  }
}
```

**GET /driver/bookings?tripId={trip}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6qxkgd0t1qc9bppr8xefjtr",
      "tripId": "01m6phc8jtq5v593fwfn4q85kb",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phc8c9we3xn7x8mdtcd4g5",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": null,
      "status": "EXITED_DUE_TO_DRIVER_DELAY",
      "seatsReserved": 1,
      "boardingCode": null,
      "price": {
        "seatPricePiastres": 12500,
        "totalPiastres": 12500,
        "driverSharePiastres": 11551,
        "driverShareTotalPiastres": 11551
      },
      "paymentType": "cash",
      "paymentStatus": "NOT_DUE",
      "meetingPoint": {
        "lat": 30.06508,
        "lng": 31.29954,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.07118,
        "lng": 31.05959,
        "label": "Smart Village B12",
        "isExact": true
      },
      "otherPartyUserId": "01m6phbrbtjadxh6d4725qkhyg",
      "cancelledAt": "2026-11-06T07:54:09+00:00",
      "cancelledReason": "late_for_pickup",
      "outcomeSeenAt": null,
      "pickupNote": null,
      "cancellationFeePiastres": 0,
      "feeLinePiastres": 0,
      "feeLineReason": null,
      "earlyDrop": {
        "askedAt": null,
        "distanceMeters": null,
        "answer": null
      },
      "createdAt": "2026-11-06T07:54:06+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-06T04:33:36+00:00",
      "pickupEtaLocal": "2026-11-06 06:33:36",
      "trip": {
        "id": "01m6phc8jtq5v593fwfn4q85kb",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-06T03:54:00+00:00",
        "departureLocal": "2026-11-06 05:54:00",
        "promisedArrivalAt": "2026-11-06T06:54:00+00:00",
        "promisedArrivalLocal": "2026-11-06 08:54:00",
        "status": "EN_ROUTE",
        "seatsTotal": 3,
        "seatsTaken": 1,
        "seatsAvailable": 2,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6qxkgcjezbs9j1myyw5kmcw",
      "tripId": "01m6phc8jtq5v593fwfn4q85kb",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phc8c9we3xn7x8mdtcd4g5",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": null,
      "status": "CONFIRMED",
      "seatsReserved": 1,
      "boardingCode": null,
      "price": {
        "seatPricePiastres": 12500,
        "totalPiastres": 12500,
        "driverSharePiastres": 11551,
        "driverShareTotalPiastres": 11551
      },
      "paymentType": "cash",
      "paymentStatus": "NOT_DUE",
      "meetingPoint": {
        "lat": 30.06264,
        "lng": 31.395519999999998,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.06874,
        "lng": 31.15557,
        "label": "Smart Village B12",
        "isExact": true
      },
      "otherPartyUserId": null,
      "cancelledAt": null,
      "cancelledReason": null,
      "outcomeSeenAt": null,
      "pickupNote": null,
      "cancellationFeePiastres": 0,
      "feeLinePiastres": 0,
      "feeLineReason": null,
      "earlyDrop": {
        "askedAt": null,
        "distanceMeters": null,
        "answer": null
      },
      "createdAt": "2026-11-06T07:54:06+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-06T04:13:48+00:00",
      "pickupEtaLocal": "2026-11-06 06:13:48",
      "trip": {
        "id": "01m6phc8jtq5v593fwfn4q85kb",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-06T03:54:00+00:00",
        "departureLocal": "2026-11-06 05:54:00",
        "promisedArrivalAt": "2026-11-06T06:54:00+00:00",
        "promisedArrivalLocal": "2026-11-06 08:54:00",
        "status": "EN_ROUTE",
        "seatsTotal": 3,
        "seatsTaken": 1,
        "seatsAvailable": 2,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6qxabk92wr5ym5kysjmddjd",
      "tripId": "01m6phc8jtq5v593fwfn4q85kb",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phc8c9we3xn7x8mdtcd4g5",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": null,
      "status": "CANCELLED_BY_DRIVER",
      "seatsReserved": 1,
      "boardingCode": null,
      "price": {
        "seatPricePiastres": 12500,
        "totalPiastres": 12500,
        "driverSharePiastres": 11551,
        "driverShareTotalPiastres": 11551
      },
      "paymentType": "cash",
      "paymentStatus": "PAID",
      "meetingPoint": {
        "lat": 30.063,
        "lng": 31.396,
        "label": null,
        "isExact": false
      },
      "dropoffPoint": {
        "lat": 30.069,
        "lng": 31.156,
        "label": null,
        "isExact": false
      },
      "otherPartyUserId": null,
      "cancelledAt": "2026-11-03T07:54:06+00:00",
      "cancelledReason": "demo_reset",
      "outcomeSeenAt": null,
      "pickupNote": null,
      "cancellationFeePiastres": 0,
      "feeLinePiastres": 0,
      "feeLineReason": null,
      "earlyDrop": {
        "askedAt": null,
        "distanceMeters": null,
        "answer": null
      },
      "createdAt": "2026-11-06T07:49:06+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-06T04:13:48+00:00",
      "pickupEtaLocal": "2026-11-06 06:13:48",
      "trip": {
        "id": "01m6phc8jtq5v593fwfn4q85kb",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-06T03:54:00+00:00",
        "departureLocal": "2026-11-06 05:54:00",
        "promisedArrivalAt": "2026-11-06T06:54:00+00:00",
        "promisedArrivalLocal": "2026-11-06 08:54:00",
        "status": "EN_ROUTE",
        "seatsTotal": 3,
        "seatsTaken": 1,
        "seatsAvailable": 2,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
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

### Ended early: attendance and pending ratings

- **State:** `DC-ITINERARY`
- **Phone:** `+201055557551`
- **Captured:** 2026-11-06 07:54:09 UTC, locally

**POST /bookings/{firstBooking}/end-early** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6qxkgcjezbs9j1myyw5kmcw",
    "tripId": "01m6phc8jtq5v593fwfn4q85kb",
    "commuteGroupId": null,
    "membership": null,
    "status": "ENDED_EARLY",
    "seatsReserved": 1,
    "boardingCode": null,
    "price": {
      "seatPricePiastres": 12500,
      "totalPiastres": 12500
    },
    "paymentType": "cash",
    "paymentStatus": "PAID",
    "meetingPoint": {
      "lat": 30.06264,
      "lng": 31.395519999999998,
      "label": "Al Rehab Mall entrance",
      "isExact": true
    },
    "dropoffPoint": {
      "lat": 30.06874,
      "lng": 31.15557,
      "label": "Smart Village B12",
      "isExact": true
    },
    "otherPartyUserId": "01m6phc8brv4zq6qb8pyr5hy6d",
    "cancelledAt": "2026-11-06T07:54:09+00:00",
    "cancelledReason": null,
    "outcomeSeenAt": null,
    "pickupNote": null,
    "cancellationFeePiastres": 0,
    "feeLinePiastres": 0,
    "feeLineReason": null,
    "earlyDrop": {
      "askedAt": null,
      "distanceMeters": null,
      "answer": null
    },
    "createdAt": "2026-11-06T07:54:06+00:00"
  }
}
```

**GET /trips/{trip}/attendance** → `200`

```json
{
  "success": true,
  "data": [
    {
      "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
      "status": "PRESENT",
      "bookingStatus": "ENDED_EARLY",
      "boardingCode": null,
      "travelled": true,
      "checkedInAt": "2026-11-06T07:54:09+00:00",
      "checkedOutAt": "2026-11-06T07:54:09+00:00",
      "confirmedBy": "driver",
      "confirmedAt": "2026-11-06T07:54:09+00:00",
      "gpsCorroborated": false,
      "gpsConfidence": 0,
      "disputedAt": null,
      "disputeReason": null,
      "disputeResolution": null,
      "passengerUserId": "01m6phbrb4e40vhtmyvb23rrqg",
      "person": {
        "publicFirstName": "Salma",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    },
    {
      "bookingId": "01m6qxkgd0t1qc9bppr8xefjtr",
      "status": "CANCELLED",
      "bookingStatus": "EXITED_DUE_TO_DRIVER_DELAY",
      "boardingCode": null,
      "travelled": false,
      "checkedInAt": null,
      "checkedOutAt": null,
      "confirmedBy": null,
      "confirmedAt": null,
      "gpsCorroborated": false,
      "gpsConfidence": null,
      "disputedAt": null,
      "disputeReason": null,
      "disputeResolution": null,
      "passengerUserId": "01m6phbrbtjadxh6d4725qkhyg",
      "person": {
        "publicFirstName": "Nour",
        "trustLevel": 1,
        "verifiedLevels": [
          "phone"
        ],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    }
  ]
}
```

**GET /ratings/pending** → `200`

```json
{
  "success": true,
  "data": [
    {
      "bookingId": "01m6qxkgcjezbs9j1myyw5kmcw",
      "tripDate": "2026-11-09T00:00:00.000000Z",
      "person": {
        "publicFirstName": "Dcitinerary",
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
        "onTimeRate": 95
      },
      "direction": "passenger_to_driver",
      "rateableUntil": "2026-11-13T03:54:00+00:00"
    }
  ]
}
```

**GET /ratings/pending** → `200`

```json
{
  "success": true,
  "data": []
}
```

