# Driver Commutes: captured responses

Every response below is real and was **captured locally** on the `fix/driver-commutes-gaps` branch on 2026-11-05, against `demo:driver-commutes-states`. **None of this was captured on live**, because this code isn't deployed yet. The same accounts exist on live once it's deployed and seeded. The sign-in code is `123456`.

Route distance, time and price come from straight-line routing, as on live until `RAFEEQ_GEO_ENGINE=google` is on.

## Commutes list

### Published, paused and archived

- **State:** `DC-MIXED-LIST`
- **Phone:** `+201055557502`
- **Captured:** 2026-11-05 19:17:04 UTC, locally

**GET /commutes?status=published,paused,archived** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc0q9gwsfb6yzdqyxc7g1",
      "status": "ARCHIVED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "corridorId": null,
      "createdAt": "2026-11-05T19:01:03+00:00",
      "updatedAt": "2026-11-05T19:17:03+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:03+00:00",
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": "2026-11-05T19:17:03+00:00",
      "missing": [],
      "locations": [
        {
          "id": "01m6phc0qf2mhz8c9pk2xc6jbg",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": null,
          "sequence": 0
        },
        {
          "id": "01m6phc0qgc1hjkpq2qsb58xvn",
          "type": "destination",
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "placeId": null,
          "sequence": 999
        }
      ],
      "schedule": {
        "daysMask": 62,
        "departureTime": "16:21:00",
        "timezone": "Africa/Cairo",
        "startDate": "2026-11-06",
        "endDate": "2027-11-06",
        "generatedUntil": "2026-12-05"
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
    },
    {
      "id": "01m6phc0h4wy5z2ptydn3zeh15",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "corridorId": null,
      "createdAt": "2026-11-05T19:01:03+00:00",
      "updatedAt": "2026-11-05T19:17:03+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:03+00:00",
      "pausedAt": "2026-11-05T19:17:03+00:00",
      "pausedReason": "by_driver",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc0hd4s5s4vb2071gasjh",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": null,
          "sequence": 0
        },
        {
          "id": "01m6phc0he4z06148fgbz594nm",
          "type": "destination",
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "placeId": null,
          "sequence": 999
        }
      ],
      "schedule": {
        "daysMask": 62,
        "departureTime": "10:21:00",
        "timezone": "Africa/Cairo",
        "startDate": "2026-11-06",
        "endDate": "2027-11-06",
        "generatedUntil": "2026-12-05"
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
    },
    {
      "id": "01m6phc07w7bdn337kygeqsxe6",
      "status": "PUBLISHED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "createdAt": "2026-11-05T19:01:02+00:00",
      "updatedAt": "2026-11-05T19:01:02+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:02+00:00",
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc08fe8tdcw9n61kys5pv",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc08hs78ns7bmyw1sprp8",
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
        "generatedUntil": "2026-12-05"
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

**GET /commutes** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc0q9gwsfb6yzdqyxc7g1",
      "status": "ARCHIVED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "corridorId": null,
      "createdAt": "2026-11-05T19:01:03+00:00",
      "updatedAt": "2026-11-05T19:17:03+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:03+00:00",
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": "2026-11-05T19:17:03+00:00",
      "missing": [],
      "locations": [
        {
          "id": "01m6phc0qf2mhz8c9pk2xc6jbg",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": null,
          "sequence": 0
        },
        {
          "id": "01m6phc0qgc1hjkpq2qsb58xvn",
          "type": "destination",
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "placeId": null,
          "sequence": 999
        }
      ],
      "schedule": {
        "daysMask": 62,
        "departureTime": "16:21:00",
        "timezone": "Africa/Cairo",
        "startDate": "2026-11-06",
        "endDate": "2027-11-06",
        "generatedUntil": "2026-12-05"
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
    },
    {
      "id": "01m6phc0h4wy5z2ptydn3zeh15",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "corridorId": null,
      "createdAt": "2026-11-05T19:01:03+00:00",
      "updatedAt": "2026-11-05T19:17:03+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:03+00:00",
      "pausedAt": "2026-11-05T19:17:03+00:00",
      "pausedReason": "by_driver",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc0hd4s5s4vb2071gasjh",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": null,
          "sequence": 0
        },
        {
          "id": "01m6phc0he4z06148fgbz594nm",
          "type": "destination",
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "placeId": null,
          "sequence": 999
        }
      ],
      "schedule": {
        "daysMask": 62,
        "departureTime": "10:21:00",
        "timezone": "Africa/Cairo",
        "startDate": "2026-11-06",
        "endDate": "2027-11-06",
        "generatedUntil": "2026-12-05"
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
    },
    {
      "id": "01m6phc07w7bdn337kygeqsxe6",
      "status": "PUBLISHED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "createdAt": "2026-11-05T19:01:02+00:00",
      "updatedAt": "2026-11-05T19:01:02+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:02+00:00",
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc08fe8tdcw9n61kys5pv",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc08hs78ns7bmyw1sprp8",
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
        "generatedUntil": "2026-12-05"
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

**GET /commutes?status=published,paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc0h4wy5z2ptydn3zeh15",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "corridorId": null,
      "createdAt": "2026-11-05T19:01:03+00:00",
      "updatedAt": "2026-11-05T19:17:03+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:03+00:00",
      "pausedAt": "2026-11-05T19:17:03+00:00",
      "pausedReason": "by_driver",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc0hd4s5s4vb2071gasjh",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": null,
          "sequence": 0
        },
        {
          "id": "01m6phc0he4z06148fgbz594nm",
          "type": "destination",
          "lat": 30.0724,
          "lng": 31.0116,
          "address": "القرية الذكية، مبنى B6",
          "placeId": null,
          "sequence": 999
        }
      ],
      "schedule": {
        "daysMask": 62,
        "departureTime": "10:21:00",
        "timezone": "Africa/Cairo",
        "startDate": "2026-11-06",
        "endDate": "2027-11-06",
        "generatedUntil": "2026-12-05"
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
    },
    {
      "id": "01m6phc07w7bdn337kygeqsxe6",
      "status": "PUBLISHED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc07t1fty1m7pykfnjfd4",
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
      "createdAt": "2026-11-05T19:01:02+00:00",
      "updatedAt": "2026-11-05T19:01:02+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:02+00:00",
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc08fe8tdcw9n61kys5pv",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc08hs78ns7bmyw1sprp8",
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
        "generatedUntil": "2026-12-05"
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

**GET /commutes?status=nonsense** → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "status": [
        "The selected status is invalid."
      ]
    }
  }
}
```

### Empty: no commutes

- **State:** `CC-NO-CAR`
- **Phone:** `+201055557405`
- **Captured:** 2026-11-05 19:17:05 UTC, locally

**GET /commutes** → `200`

```json
{
  "success": true,
  "data": []
}
```

**GET /driver/trips?scope=upcoming** → `200`

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

**GET /driver/trips?scope=past** → `200`

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

## Paused, one commute for each pausedReason

### pausedReason by_driver

- **State:** `DC-PAUSED-BY-DRIVER`
- **Phone:** `+201055557511`
- **Captured:** 2026-11-05 19:17:06 UTC, locally

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc15wszetvfmz5ywv7kzk",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc15t6h81n7rqvbzr5jqz",
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
      "updatedAt": "2026-11-05T19:17:05+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:03+00:00",
      "pausedAt": "2026-11-05T19:17:05+00:00",
      "pausedReason": "by_driver",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc1666qd9qcmjzm237rda",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc168rhtf0je27b2y0kk6",
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
        "generatedUntil": "2026-12-05"
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

### pausedReason vehicle_suspended

- **State:** `DC-PAUSED-VEHICLE`
- **Phone:** `+201055557512`
- **Captured:** 2026-11-05 19:17:07 UTC, locally

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc1h1zg8sq3afhbbdr41e",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc1h0j7x586pbnyehvejm",
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
      "createdAt": "2026-11-05T19:01:04+00:00",
      "updatedAt": "2026-11-05T19:17:06+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:04+00:00",
      "pausedAt": "2026-11-05T19:17:06+00:00",
      "pausedReason": "vehicle_suspended",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc1hhckevb3th2skd7tvt",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc1hk6sp6ywtkva1fh4wh",
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
        "generatedUntil": "2026-12-05"
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

### pausedReason licence_expired

- **State:** `DC-PAUSED-LICENCE`
- **Phone:** `+201055557513`
- **Captured:** 2026-11-05 19:17:09 UTC, locally

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc20v6knjwam4pha3gyx4",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc20sqwtsv503qxsp45x7",
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
      "createdAt": "2026-11-05T19:01:04+00:00",
      "updatedAt": "2026-11-05T19:17:08+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:04+00:00",
      "pausedAt": "2026-11-05T19:17:08+00:00",
      "pausedReason": "licence_expired",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc21a6490qntpsjw6dx9s",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc21c3hdh9dam8s8vrg0j",
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
        "generatedUntil": "2026-12-05"
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

**POST /commutes/{commute}/resume** → `422`

```json
{
  "success": false,
  "error": {
    "code": "DRIVER_LICENCE_EXPIRED",
    "message": "Your licence isn't valid. Renew it to continue.",
    "fields": null
  }
}
```

### pausedReason commission_unpaid

- **State:** `DC-PAUSED-COMMISSION`
- **Phone:** `+201055557514`
- **Captured:** 2026-11-05 19:17:10 UTC, locally

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc2ebjan021n092db6zx7",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc2e93x56qjc3sae3a2zs",
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
      "createdAt": "2026-11-05T19:01:05+00:00",
      "updatedAt": "2026-11-05T19:17:09+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:05+00:00",
      "pausedAt": "2026-11-05T19:17:09+00:00",
      "pausedReason": "commission_unpaid",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc2exx70s66snx97c5j24",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc2ezfprypbd0tvjsg189",
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
        "generatedUntil": "2026-12-05"
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

**POST /commutes/{commute}/resume** → `403`

```json
{
  "success": false,
  "error": {
    "code": "DRIVER_COMMISSION_OVERDUE",
    "message": "Your commission for a past week is still unpaid, so you cannot publish a commute or accept new riders until it is paid. Your booked rides continue. Pay through InstaPay from the Payments screen.",
    "fields": {
      "amountOwedPiastres": [
        "18000"
      ],
      "dueAt": [
        "2026-10-30T21:59:00+00:00"
      ],
      "weekStart": [
        "2026-10-24"
      ]
    }
  }
}
```

### pausedReason account_suspended

- **State:** `DC-PAUSED-ACCOUNT`
- **Phone:** `+201055557515`
- **Captured:** 2026-11-05 19:17:11 UTC, locally

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc2v6ws8qd0c9e4z0haag",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc2v4a5mqptjqmjgqyht3",
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
      "createdAt": "2026-11-05T19:01:05+00:00",
      "updatedAt": "2026-11-05T19:17:11+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:05+00:00",
      "pausedAt": "2026-11-05T19:17:11+00:00",
      "pausedReason": "account_suspended",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc2vqmadxp7rnr39paxrr",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc2vsy94ppjar6yd9g9sa",
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
        "generatedUntil": "2026-12-05"
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

**POST /commutes/{commute}/resume** → `403`

```json
{
  "success": false,
  "error": {
    "code": "ACCOUNT_SUSPENDED",
    "message": "Your account is currently suspended.",
    "fields": {
      "caseNumber": [
        "RF-544756"
      ],
      "reasonCode": [
        "safety_report"
      ],
      "suspendedAt": [
        "2026-11-05T19:17:11+00:00"
      ],
      "reviewDueAt": [
        "2026-11-06T19:17:11+00:00"
      ]
    }
  }
}
```

### pausedReason driver_suspended

- **State:** `DC-PAUSED-DRIVER`
- **Phone:** `+201055557516`
- **Captured:** 2026-11-05 19:17:13 UTC, locally

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc382sp8dvfkqe5p521ef",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc38004n0nw6j170gb45w",
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
      "createdAt": "2026-11-05T19:01:05+00:00",
      "updatedAt": "2026-11-05T19:17:12+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:05+00:00",
      "pausedAt": "2026-11-05T19:17:12+00:00",
      "pausedReason": "driver_suspended",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc38ejv6g0caff5zf61d9",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc38gkzc97w1p6d0ykkt3",
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
        "generatedUntil": "2026-12-05"
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

**POST /commutes/{commute}/resume** → `403`

```json
{
  "success": false,
  "error": {
    "code": "DRIVER_NOT_ELIGIBLE",
    "message": "Your driving is suspended, so you can't do this right now.",
    "fields": {
      "driver": [
        "SUSPENDED"
      ]
    }
  }
}
```

### pausedReason gender_review

- **State:** `DC-PAUSED-GENDER`
- **Phone:** `+201055557517`
- **Captured:** 2026-11-05 19:17:14 UTC, locally

**GET /commutes?status=paused** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc3p15nnynnzwxpf036d8",
      "status": "PAUSED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc3nypm7r2b3xthyzt2qj",
      "seatsTotal": 3,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driverSharePiastres": 11551,
      "currency": "EGP",
      "maxDetourMinutes": 30,
      "maxWalkMinutes": 18,
      "audience": "women_only",
      "minTrustLevel": 0,
      "allowsCustomPickup": true,
      "routePolyline": "gcnvD{te_EgkAjv|A",
      "routeDistanceMeters": 46202,
      "routeDurationSeconds": 5940,
      "corridorId": "01m6hf9f3h286exdz6ttbzt92n",
      "createdAt": "2026-11-05T19:01:06+00:00",
      "updatedAt": "2026-11-05T19:17:13+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:06+00:00",
      "pausedAt": "2026-11-05T19:17:13+00:00",
      "pausedReason": "gender_review",
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6phc3pgmxsek3bfettg6a36",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc3pjnbwgrm9jxr3w58p8",
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
        "generatedUntil": "2026-12-05"
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

**POST /commutes/{commute}/resume** → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_WOMEN_ONLY_NOT_ALLOWED",
    "message": "Only a woman driver can make a commute Women-only.",
    "fields": {
      "audience": [
        "Only a woman driver can make a commute Women-only."
      ]
    }
  }
}
```

## Coming days (G1)

### Full, part-full and empty days, and a day inside the lock

- **State:** `DC-PASSENGERS`
- **Phone:** `+201055557501`
- **Captured:** 2026-11-05 19:17:16 UTC, locally

**GET /driver/trips?scope=upcoming&perPage=6** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phbr5ym09dznhyn5sy04vm",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-06",
      "departureLocal": "2026-11-06 02:17:00",
      "promisedArrivalLocal": "2026-11-06 04:17:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 3,
      "seatsAvailable": 0,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 3,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6phbr5zzvyrgyjtkbjf20aq",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-09",
      "departureLocal": "2026-11-09 07:21:00",
      "promisedArrivalLocal": "2026-11-09 09:00:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 2,
      "seatsAvailable": 1,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 2,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6phbr61489wwcp0x7ef3zhe",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-10",
      "departureLocal": "2026-11-10 07:21:00",
      "promisedArrivalLocal": "2026-11-10 09:00:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsAvailable": 2,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 1,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6phbr62eqm31f1tp49yy3nj",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-11",
      "departureLocal": "2026-11-11 07:21:00",
      "promisedArrivalLocal": "2026-11-11 09:00:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsAvailable": 3,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 0,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6phbr63n2sbsr1e1qgcr608",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-12",
      "departureLocal": "2026-11-12 07:21:00",
      "promisedArrivalLocal": "2026-11-12 09:00:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsAvailable": 3,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 0,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6phbr65ff13e2yfrwpcpa3a",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-15",
      "departureLocal": "2026-11-15 07:21:00",
      "promisedArrivalLocal": "2026-11-15 09:00:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsAvailable": 3,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 0,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 6,
    "total": 20,
    "lastPage": 4,
    "hasMore": true
  }
}
```

**GET /driver/trips?scope=upcoming&commuteId={commute}&perPage=2** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phbr5ym09dznhyn5sy04vm",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-06",
      "departureLocal": "2026-11-06 02:17:00",
      "promisedArrivalLocal": "2026-11-06 04:17:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 3,
      "seatsAvailable": 0,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 3,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6phbr5zzvyrgyjtkbjf20aq",
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "tripDate": "2026-11-09",
      "departureLocal": "2026-11-09 07:21:00",
      "promisedArrivalLocal": "2026-11-09 09:00:00",
      "status": "SCHEDULED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 2,
      "seatsAvailable": 1,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 2,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 2,
    "total": 20,
    "lastPage": 10,
    "hasMore": true
  }
}
```

**GET /driver/trips** → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "scope": [
        "The scope field is required."
      ]
    }
  }
}
```

## Detail and passengers on a day (G2)

### Passengers on several days, first day inside the 16-hour lock

- **State:** `DC-PASSENGERS`
- **Phone:** `+201055557501`
- **Captured:** 2026-11-05 19:17:16 UTC, locally

**GET /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6phbqttk5s6tpr7h8jrmjcx",
    "status": "PUBLISHED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6phbqtj1m4nc2yqjkxq27pe",
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
    "createdAt": "2026-11-05T19:00:54+00:00",
    "updatedAt": "2026-11-05T19:00:54+00:00",
    "formStep": null,
    "publishedAt": "2026-11-05T19:00:54+00:00",
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6phbqvwefdeqmygnr6vj203",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6phbqvzz4dgeggwqg1pb4ym",
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
      "generatedUntil": "2026-12-05"
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
    "rules": {},
    "upcomingTrips": [
      {
        "id": "01m6phbr5ym09dznhyn5sy04vm",
        "tripDate": "2026-11-06",
        "departureAt": "2026-11-06T00:17:00+00:00",
        "departureLocal": "2026-11-06 02:17:00",
        "promisedArrivalAt": "2026-11-06T02:17:00+00:00",
        "promisedArrivalLocal": "2026-11-06 04:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 3,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phbr5zzvyrgyjtkbjf20aq",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-09T05:21:00+00:00",
        "departureLocal": "2026-11-09 07:21:00",
        "promisedArrivalAt": "2026-11-09T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-09 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 2,
        "seatsAvailable": 1,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phbr61489wwcp0x7ef3zhe",
        "tripDate": "2026-11-10",
        "departureAt": "2026-11-10T05:21:00+00:00",
        "departureLocal": "2026-11-10 07:21:00",
        "promisedArrivalAt": "2026-11-10T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-10 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 1,
        "seatsAvailable": 2,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phbr62eqm31f1tp49yy3nj",
        "tripDate": "2026-11-11",
        "departureAt": "2026-11-11T05:21:00+00:00",
        "departureLocal": "2026-11-11 07:21:00",
        "promisedArrivalAt": "2026-11-11T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-11 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phbr63n2sbsr1e1qgcr608",
        "tripDate": "2026-11-12",
        "departureAt": "2026-11-12T05:21:00+00:00",
        "departureLocal": "2026-11-12 07:21:00",
        "promisedArrivalAt": "2026-11-12T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-12 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phbr65ff13e2yfrwpcpa3a",
        "tripDate": "2026-11-15",
        "departureAt": "2026-11-15T05:21:00+00:00",
        "departureLocal": "2026-11-15 07:21:00",
        "promisedArrivalAt": "2026-11-15T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-15 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phbr66b3zshq7bw8875xtj",
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
      },
      {
        "id": "01m6phbr686phy5xyv85x9eter",
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
      },
      {
        "id": "01m6phbr69z5tabfgfhyr7yz0m",
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
      },
      {
        "id": "01m6phbr6bhp8248m0eh952tyz",
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
      },
      {
        "id": "01m6phbr6d2v7839p1egyg2qty",
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
      },
      {
        "id": "01m6phbr6fft0ke5sensbcth2v",
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
      },
      {
        "id": "01m6phbr6gxv0rg5ez5psyfjm2",
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
      },
      {
        "id": "01m6phbr6h70ehdc7hz6n80e5t",
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
      },
      {
        "id": "01m6phbr6jy6pzkrsd1n88wj72",
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
      },
      {
        "id": "01m6phbr6kbqvby8yg2s85r7qb",
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
      },
      {
        "id": "01m6phbr6nwhtttg0bk7kq4rc7",
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
      },
      {
        "id": "01m6phbr6p19g8x9yf4xaq27rn",
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
      },
      {
        "id": "01m6phbr6q49athg1s7najks3b",
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
      },
      {
        "id": "01m6phbr6rwwvqeshe343jts6c",
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
    ]
  }
}
```

**GET /driver/bookings?commuteId={commute}&status=confirmed&perPage=10** → `200`

First 2 of 6 rows shown.

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pj9nne12nkvybnevhf3vvy",
      "tripId": "01m6phbr61489wwcp0x7ef3zhe",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Mariam",
        "trustLevel": 1,
        "verifiedLevels": [],
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
        "lat": 30.063249999999996,
        "lng": 31.371525,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.070569999999996,
        "lng": 31.083585,
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
      "createdAt": "2026-11-05T19:17:15+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-10T05:45:45+00:00",
      "pickupEtaLocal": "2026-11-10 07:45:45",
      "trip": {
        "id": "01m6phbr61489wwcp0x7ef3zhe",
        "tripDate": "2026-11-10",
        "departureAt": "2026-11-10T05:21:00+00:00",
        "departureLocal": "2026-11-10 07:21:00",
        "promisedArrivalAt": "2026-11-10T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-10 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 1,
        "seatsAvailable": 2,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pj9nn31v91tvwqn5bdzjps",
      "tripId": "01m6phbr5zzvyrgyjtkbjf20aq",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 2,
        "verifiedLevels": [],
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
        "lat": 30.06386,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.07118,
        "lng": 31.05959,
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
      "createdAt": "2026-11-05T19:17:15+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-09T05:50:42+00:00",
      "pickupEtaLocal": "2026-11-09 07:50:42",
      "trip": {
        "id": "01m6phbr5zzvyrgyjtkbjf20aq",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-09T05:21:00+00:00",
        "departureLocal": "2026-11-09 07:21:00",
        "promisedArrivalAt": "2026-11-09T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-09 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 2,
        "seatsAvailable": 1,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 10,
    "total": 6,
    "lastPage": 1,
    "hasMore": false
  }
}
```

**GET /driver/bookings?tripId={partDay}&status=confirmed** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pj9nn31v91tvwqn5bdzjps",
      "tripId": "01m6phbr5zzvyrgyjtkbjf20aq",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 2,
        "verifiedLevels": [],
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
        "lat": 30.06386,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.07118,
        "lng": 31.05959,
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
      "createdAt": "2026-11-05T19:17:15+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-09T05:50:42+00:00",
      "pickupEtaLocal": "2026-11-09 07:50:42",
      "trip": {
        "id": "01m6phbr5zzvyrgyjtkbjf20aq",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-09T05:21:00+00:00",
        "departureLocal": "2026-11-09 07:21:00",
        "promisedArrivalAt": "2026-11-09T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-09 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 2,
        "seatsAvailable": 1,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pj9nmrkt9q61swyje16xt5",
      "tripId": "01m6phbr5zzvyrgyjtkbjf20aq",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phbqttk5s6tpr7h8jrmjcx",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 2,
        "verifiedLevels": [],
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
      "createdAt": "2026-11-05T19:17:15+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-09T05:40:48+00:00",
      "pickupEtaLocal": "2026-11-09 07:40:48",
      "trip": {
        "id": "01m6phbr5zzvyrgyjtkbjf20aq",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-09T05:21:00+00:00",
        "departureLocal": "2026-11-09 07:21:00",
        "promisedArrivalAt": "2026-11-09T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-09 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 2,
        "seatsAvailable": 1,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false
  }
}
```

**GET /driver/bookings?status=nonsense** → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "status": [
        "The selected status is invalid."
      ]
    }
  }
}
```

## Past days (G3)

### Completed, cancelled by the driver, cancelled by the platform, expired

- **State:** `DC-PAST-DAYS`
- **Phone:** `+201055557521`
- **Captured:** 2026-11-05 19:17:17 UTC, locally

**GET /driver/trips?scope=past** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pj9q5j88qtbrwfj4dq68cj",
      "commuteId": "01m6phc45mwykhh8tkd8pn38mv",
      "tripDate": "2026-11-03",
      "departureLocal": "2026-11-03 07:00:00",
      "promisedArrivalLocal": "2026-11-03 09:00:00",
      "status": "COMPLETED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 2,
      "seatsAvailable": 1,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 2,
      "durationSeconds": 3840,
      "ratingAverage": 4.5,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6pj9q6xr7ga06br9he289kz",
      "commuteId": "01m6phc45mwykhh8tkd8pn38mv",
      "tripDate": "2026-11-02",
      "departureLocal": "2026-11-02 07:00:00",
      "promisedArrivalLocal": "2026-11-02 09:00:00",
      "status": "CANCELLED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsAvailable": 2,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 0,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": "DRIVER",
      "passengersNotified": 1,
      "expiredAt": null
    },
    {
      "id": "01m6pj9q7g8037ywkvzq7w4h16",
      "commuteId": "01m6phc45mwykhh8tkd8pn38mv",
      "tripDate": "2026-11-01",
      "departureLocal": "2026-11-01 07:00:00",
      "promisedArrivalLocal": "2026-11-01 09:00:00",
      "status": "CANCELLED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsAvailable": 3,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 0,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": "PLATFORM",
      "passengersNotified": 0,
      "expiredAt": null
    },
    {
      "id": "01m6pj9q7mfytq83ye2nwn383t",
      "commuteId": "01m6phc45mwykhh8tkd8pn38mv",
      "tripDate": "2026-10-31",
      "departureLocal": "2026-10-31 07:00:00",
      "promisedArrivalLocal": "2026-10-31 09:00:00",
      "status": "EXPIRED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsAvailable": 3,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 0,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": null,
      "passengersNotified": 0,
      "expiredAt": "2026-10-31T05:20:00+00:00"
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 4,
    "lastPage": 1,
    "hasMore": false
  }
}
```

**GET /trips/{trip}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6pj9q6qsvtzmr5md02h17wj",
    "tripId": "01m6pj9q5j88qtbrwfj4dq68cj",
    "status": "COMPLETED",
    "startedAt": "2026-11-03T04:57:00+00:00",
    "departedAt": "2026-11-03T05:00:00+00:00",
    "completedAt": "2026-11-03T06:04:00+00:00",
    "durationSeconds": null,
    "distanceTravelledMeters": null,
    "isUnderway": false,
    "lastLocationAt": null,
    "autoFinishCheckAt": null,
    "stillDrivingCount": 0,
    "deviationDetectedAt": null,
    "deviationDistanceMeters": null,
    "trip": {
      "id": "01m6pj9q5j88qtbrwfj4dq68cj",
      "tripDate": "2026-11-03",
      "departureAt": "2026-11-03T05:00:00+00:00",
      "departureLocal": "2026-11-03 07:00:00",
      "promisedArrivalAt": "2026-11-03T07:00:00+00:00",
      "promisedArrivalLocal": "2026-11-03 09:00:00",
      "status": "COMPLETED",
      "seatsTotal": 3,
      "seatsTaken": 2,
      "seatsAvailable": 1,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driverSharePiastres": 11551
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
      "id": "01m6pj9q6fmvw9183eeezjwrc2",
      "tripId": "01m6pj9q5j88qtbrwfj4dq68cj",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phc45mwykhh8tkd8pn38mv",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": null,
      "status": "COMPLETED",
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
        "lat": 30.06386,
        "lng": 31.34753,
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
      "createdAt": "2026-11-05T19:17:16+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-03T05:29:42+00:00",
      "pickupEtaLocal": "2026-11-03 07:29:42",
      "trip": {
        "id": "01m6pj9q5j88qtbrwfj4dq68cj",
        "tripDate": "2026-11-03",
        "departureAt": "2026-11-03T05:00:00+00:00",
        "departureLocal": "2026-11-03 07:00:00",
        "promisedArrivalAt": "2026-11-03T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-03 09:00:00",
        "status": "COMPLETED",
        "seatsTotal": 3,
        "seatsTaken": 2,
        "seatsAvailable": 1,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pj9q61m101631xh7czv55s",
      "tripId": "01m6pj9q5j88qtbrwfj4dq68cj",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phc45mwykhh8tkd8pn38mv",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": null,
      "status": "COMPLETED",
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
        "lat": 30.06996,
        "lng": 31.107580000000002,
        "label": "Smart Village B12",
        "isExact": true
      },
      "otherPartyUserId": "01m6phbrb4e40vhtmyvb23rrqg",
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
      "createdAt": "2026-11-05T19:17:16+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-03T05:19:48+00:00",
      "pickupEtaLocal": "2026-11-03 07:19:48",
      "trip": {
        "id": "01m6pj9q5j88qtbrwfj4dq68cj",
        "tripDate": "2026-11-03",
        "departureAt": "2026-11-03T05:00:00+00:00",
        "departureLocal": "2026-11-03 07:00:00",
        "promisedArrivalAt": "2026-11-03T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-03 09:00:00",
        "status": "COMPLETED",
        "seatsTotal": 3,
        "seatsTaken": 2,
        "seatsAvailable": 1,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false
  }
}
```

**GET /trips/{trip}** → `409`

```json
{
  "success": false,
  "error": {
    "code": "TRIP_NOT_STARTED",
    "message": "This run has not started yet.",
    "fields": null
  }
}
```

## Cancelling

### More than 16 hours ahead: reason none

- **State:** `DC-FEES-NONE`
- **Phone:** `+201055557531`
- **Captured:** 2026-11-05 19:17:18 UTC, locally

**GET /commutes/{commute}/cancel-preview?action=pause** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 0,
    "reason": "none",
    "perTrip": [
      {
        "tripId": "01m6phc4re82g4b0cnys8namjy",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "seats": 2,
        "percent": 0,
        "feePiastres": 0
      }
    ]
  }
}
```

**GET /commutes/{commute}/cancel-preview?action=delete** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 0,
    "reason": "none",
    "perTrip": [
      {
        "tripId": "01m6phc4re82g4b0cnys8namjy",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "seats": 2,
        "percent": 0,
        "feePiastres": 0
      }
    ]
  }
}
```

**GET /trips/{trip}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 0,
    "reason": "none",
    "perTrip": [
      {
        "tripId": "01m6phc4re82g4b0cnys8namjy",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "seats": 2,
        "percent": 0,
        "feePiastres": 0
      }
    ]
  }
}
```

**GET /driver/bookings/{booking}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 1,
    "feePiastres": 0,
    "reason": "none",
    "perTrip": [
      {
        "tripId": "01m6phc4re82g4b0cnys8namjy",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "seats": 1,
        "percent": 0,
        "feePiastres": 0
      }
    ]
  }
}
```

### 13 hours ahead: reason mid_notice

- **State:** `DC-FEES-MID`
- **Phone:** `+201055557532`
- **Captured:** 2026-11-05 19:17:20 UTC, locally

**GET /commutes/{commute}/cancel-preview?action=pause** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 2500,
    "reason": "mid_notice",
    "perTrip": [
      {
        "tripId": "01m6phc540apzt56z9y11hcgbm",
        "departureAt": "2026-11-06T08:17:00+00:00",
        "seats": 2,
        "percent": 10,
        "feePiastres": 2500
      }
    ]
  }
}
```

**GET /commutes/{commute}/cancel-preview?action=delete** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 2500,
    "reason": "mid_notice",
    "perTrip": [
      {
        "tripId": "01m6phc540apzt56z9y11hcgbm",
        "departureAt": "2026-11-06T08:17:00+00:00",
        "seats": 2,
        "percent": 10,
        "feePiastres": 2500
      }
    ]
  }
}
```

**GET /trips/{trip}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 2500,
    "reason": "mid_notice",
    "perTrip": [
      {
        "tripId": "01m6phc540apzt56z9y11hcgbm",
        "departureAt": "2026-11-06T08:17:00+00:00",
        "seats": 2,
        "percent": 10,
        "feePiastres": 2500
      }
    ]
  }
}
```

**GET /driver/bookings/{booking}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 1,
    "feePiastres": 1250,
    "reason": "mid_notice",
    "perTrip": [
      {
        "tripId": "01m6phc540apzt56z9y11hcgbm",
        "departureAt": "2026-11-06T08:17:00+00:00",
        "seats": 1,
        "percent": 10,
        "feePiastres": 1250
      }
    ]
  }
}
```

### 5 hours ahead: reason short_notice

- **State:** `DC-FEES-SHORT`
- **Phone:** `+201055557533`
- **Captured:** 2026-11-05 19:17:21 UTC, locally

**GET /commutes/{commute}/cancel-preview?action=pause** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 6250,
    "reason": "short_notice",
    "perTrip": [
      {
        "tripId": "01m6phc5jz7taw4ds8085vy5ea",
        "departureAt": "2026-11-06T00:17:00+00:00",
        "seats": 2,
        "percent": 25,
        "feePiastres": 6250
      }
    ]
  }
}
```

**GET /commutes/{commute}/cancel-preview?action=delete** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 6250,
    "reason": "short_notice",
    "perTrip": [
      {
        "tripId": "01m6phc5jz7taw4ds8085vy5ea",
        "departureAt": "2026-11-06T00:17:00+00:00",
        "seats": 2,
        "percent": 25,
        "feePiastres": 6250
      }
    ]
  }
}
```

**GET /trips/{trip}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 2,
    "feePiastres": 6250,
    "reason": "short_notice",
    "perTrip": [
      {
        "tripId": "01m6phc5jz7taw4ds8085vy5ea",
        "departureAt": "2026-11-06T00:17:00+00:00",
        "seats": 2,
        "percent": 25,
        "feePiastres": 6250
      }
    ]
  }
}
```

**GET /driver/bookings/{booking}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 1,
    "feePiastres": 3125,
    "reason": "short_notice",
    "perTrip": [
      {
        "tripId": "01m6phc5jz7taw4ds8085vy5ea",
        "departureAt": "2026-11-06T00:17:00+00:00",
        "seats": 1,
        "percent": 25,
        "feePiastres": 3125
      }
    ]
  }
}
```

### Remove one passenger, with the fee

- **State:** `DC-FEES-SHORT`
- **Phone:** `+201055557533`
- **Captured:** 2026-11-05 19:17:23 UTC, locally

**POST /driver/bookings/{booking}/cancel** with `{"reason": "I can't reach this pickup any more"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6pj9wjv3j3b5tks1e1p2rm2",
    "tripId": "01m6phc5jz7taw4ds8085vy5ea",
    "commuteGroupId": null,
    "membership": null,
    "commuteId": "01m6phc58saq41fkz7kbezt491",
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
    "paymentStatus": "NOT_DUE",
    "meetingPoint": {
      "lat": 30.064,
      "lng": 31.348,
      "label": null,
      "isExact": false
    },
    "dropoffPoint": {
      "lat": 30.071,
      "lng": 31.06,
      "label": null,
      "isExact": false
    },
    "otherPartyUserId": null,
    "cancelledAt": "2026-11-05T19:17:23+00:00",
    "cancelledReason": "I can't reach this pickup any more",
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
    "createdAt": "2026-11-05T19:17:22+00:00",
    "driverDelay": null,
    "pickupEta": null,
    "pickupEtaLocal": null,
    "trip": {
      "id": "01m6phc5jz7taw4ds8085vy5ea",
      "tripDate": "2026-11-06",
      "departureAt": "2026-11-06T00:17:00+00:00",
      "departureLocal": "2026-11-06 02:17:00",
      "promisedArrivalAt": "2026-11-06T02:17:00+00:00",
      "promisedArrivalLocal": "2026-11-06 04:17:00",
      "status": "SCHEDULED",
      "seatsTotal": 3,
      "seatsTaken": 1,
      "seatsAvailable": 2,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driverSharePiastres": 11551
    }
  }
}
```

**GET /driver/bookings/{booking}/cancel-preview** → `409`

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_NOT_CANCELLABLE",
    "message": "That booking can no longer be cancelled.",
    "fields": {
      "status": [
        "CANCELLED_BY_DRIVER"
      ]
    }
  }
}
```

**POST /driver/bookings/{booking}/cancel** with `{"reason": "Again"}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_NOT_CANCELLABLE",
    "message": "That booking can no longer be cancelled.",
    "fields": {
      "status": [
        "CANCELLED_BY_DRIVER"
      ]
    }
  }
}
```

### Cancel one day, with the fee

- **State:** `DC-FEES-MID`
- **Phone:** `+201055557532`
- **Captured:** 2026-11-05 19:17:24 UTC, locally

**POST /trips/{trip}/cancel** with `{"reason": "My car is in the garage"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6phc540apzt56z9y11hcgbm",
    "tripDate": "2026-11-06",
    "departureAt": "2026-11-06T08:17:00+00:00",
    "departureLocal": "2026-11-06 10:17:00",
    "promisedArrivalAt": "2026-11-06T10:17:00+00:00",
    "promisedArrivalLocal": "2026-11-06 12:17:00",
    "status": "CANCELLED",
    "seatsTotal": 3,
    "seatsTaken": 0,
    "seatsAvailable": 3,
    "seatPricePiastres": 12500,
    "pricePerSeatPiastres": 12500,
    "driverSharePiastres": 11551
  }
}
```

**POST /trips/{trip}/cancel** → `409`

```json
{
  "success": false,
  "error": {
    "code": "TRIP_NOT_CANCELLABLE",
    "message": "This run can no longer be cancelled.",
    "fields": {
      "status": [
        "CANCELLED"
      ]
    }
  }
}
```

**GET /driver/trips?scope=past** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc540apzt56z9y11hcgbm",
      "commuteId": "01m6phc4y1jq7zyc9wtpnqjf37",
      "tripDate": "2026-11-06",
      "departureLocal": "2026-11-06 10:17:00",
      "promisedArrivalLocal": "2026-11-06 12:17:00",
      "status": "CANCELLED",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsAvailable": 3,
      "seatPricePiastres": 12500,
      "driverSharePiastres": 11551,
      "passengerCount": 0,
      "durationSeconds": null,
      "ratingAverage": null,
      "cancelledBy": "DRIVER",
      "passengersNotified": 2,
      "expiredAt": null
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

### A run under way

- **State:** `DC-TRIP-STARTED`
- **Phone:** `+201055557534`
- **Captured:** 2026-11-05 19:17:25 UTC, locally

**GET /trips/{trip}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 1,
    "feePiastres": 3125,
    "reason": "short_notice",
    "perTrip": [
      {
        "tripId": "01m6phc5ywbcetz8x0vz78cgwm",
        "departureAt": "2026-11-05T19:22:00+00:00",
        "seats": 1,
        "percent": 25,
        "feePiastres": 3125
      }
    ]
  }
}
```

**POST /trips/{trip}/cancel** → `409`

```json
{
  "success": false,
  "error": {
    "code": "TRIP_NOT_CANCELLABLE",
    "message": "This run can no longer be cancelled.",
    "fields": {
      "status": [
        "PREPARING"
      ]
    }
  }
}
```

### Pause and resume

- **State:** `DC-FEES-MID`
- **Phone:** `+201055557532`
- **Captured:** 2026-11-05 19:17:27 UTC, locally

**POST /commutes/{commute}/pause** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6phc4y1jq7zyc9wtpnqjf37",
    "status": "PAUSED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6phc4xzdvjg3w0qbke36nfx",
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
    "createdAt": "2026-11-05T19:01:07+00:00",
    "updatedAt": "2026-11-05T19:17:27+00:00",
    "formStep": null,
    "publishedAt": "2026-11-05T19:01:07+00:00",
    "pausedAt": "2026-11-05T19:17:27+00:00",
    "pausedReason": "by_driver",
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6phc4yf125menxm5vt7ysp4",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6phc4yh7h3abcx21f70hggk",
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
      "generatedUntil": "2026-12-05"
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

**POST /commutes/{commute}/resume** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6phc4y1jq7zyc9wtpnqjf37",
    "status": "PUBLISHED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6phc4xzdvjg3w0qbke36nfx",
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
    "createdAt": "2026-11-05T19:01:07+00:00",
    "updatedAt": "2026-11-05T19:17:27+00:00",
    "formStep": null,
    "publishedAt": "2026-11-05T19:01:07+00:00",
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6phc4yf125menxm5vt7ysp4",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6phc4yh7h3abcx21f70hggk",
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
      "generatedUntil": "2026-12-05"
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

### Delete a published commute

- **State:** `DC-FEES-SHORT`
- **Phone:** `+201055557533`
- **Captured:** 2026-11-05 19:17:28 UTC, locally

**DELETE /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6phc58saq41fkz7kbezt491",
    "status": "ARCHIVED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6phc58qfcshdb87ry5fgyw0",
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
    "createdAt": "2026-11-05T19:01:07+00:00",
    "updatedAt": "2026-11-05T19:17:28+00:00",
    "formStep": null,
    "publishedAt": "2026-11-05T19:01:07+00:00",
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": "2026-11-05T19:17:28+00:00",
    "missing": [],
    "locations": [
      {
        "id": "01m6phc59a5a2ee3yj540s30ge",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6phc59bzsyzcmvfm49zx9a5",
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
      "generatedUntil": "2026-12-05"
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

**GET /commutes?status=archived** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6phc58saq41fkz7kbezt491",
      "status": "ARCHIVED",
      "commuteType": "recurring",
      "direction": "to_work",
      "vehicleId": "01m6phc58qfcshdb87ry5fgyw0",
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
      "createdAt": "2026-11-05T19:01:07+00:00",
      "updatedAt": "2026-11-05T19:17:28+00:00",
      "formStep": null,
      "publishedAt": "2026-11-05T19:01:07+00:00",
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": "2026-11-05T19:17:28+00:00",
      "missing": [],
      "locations": [
        {
          "id": "01m6phc59a5a2ee3yj540s30ge",
          "type": "origin",
          "lat": 30.0602,
          "lng": 31.4915,
          "address": "الرحاب، بوابة ٢",
          "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
          "sequence": 0
        },
        {
          "id": "01m6phc59bzsyzcmvfm49zx9a5",
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
        "generatedUntil": "2026-12-05"
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

## Edit with nobody booked (G6, G7)

### A change proposal applies at once

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-05 19:17:29 UTC, locally

**PUT /commutes/{commute}/schedule** with `{"daysMask": 62, "promisedArrivalTime": "10:00:00"}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_NOT_EDITABLE",
    "message": "This commute cannot be changed in its current state.",
    "fields": null
  }
}
```

**POST /driver/commutes/{commute}/change-proposals** with `{"scheduledTripId": "01m6phc12hz7zh49zedqscvgmg", "scope": "only_this_occurrence", "promisedArrivalTime": "10:00:00"}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pja3zcbckca2jg3gpr1ddj",
    "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
    "tripId": "01m6phc12hz7zh49zedqscvgmg",
    "tripDate": "2026-11-08",
    "scope": "only_this_occurrence",
    "status": "APPLIED",
    "version": 1,
    "changes": {
      "departureTime": null,
      "promisedArrivalTime": "10:00:00",
      "pickupPoints": null
    },
    "previous": {
      "departureTime": null,
      "promisedArrivalTime": "09:00:00",
      "pickupPoints": null
    },
    "effectiveFrom": null,
    "expiresAt": "2026-11-08T05:21:00+00:00",
    "appliedAt": "2026-11-05T19:17:29+00:00",
    "createdAt": "2026-11-05T19:17:29+00:00",
    "affectedCount": 0,
    "acceptedCount": 0,
    "passengers": []
  }
}
```

**POST /driver/commutes/{commute}/change-proposals** with `{"scheduledTripId": "01m6phc12hz7zh49zedqscvgmg", "scope": "all_future_on_this_weekday", "promisedArrivalTime": "08:30:00"}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pja40kmd9tswkxq35vmc00",
    "commuteId": "01m6phc0xa5qh8tyg2wy3s0aw4",
    "tripId": "01m6phc12hz7zh49zedqscvgmg",
    "tripDate": "2026-11-08",
    "scope": "all_future_on_this_weekday",
    "status": "APPLIED",
    "version": 1,
    "changes": {
      "departureTime": null,
      "promisedArrivalTime": "08:30:00",
      "pickupPoints": null
    },
    "previous": {
      "departureTime": null,
      "promisedArrivalTime": "09:00:00",
      "pickupPoints": null
    },
    "effectiveFrom": "2026-11-08",
    "expiresAt": "2026-11-08T06:21:00+00:00",
    "appliedAt": "2026-11-05T19:17:29+00:00",
    "createdAt": "2026-11-05T19:17:29+00:00",
    "affectedCount": 0,
    "acceptedCount": 0,
    "passengers": []
  }
}
```

**GET /commutes/{commute}** → `200`

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
    "updatedAt": "2026-11-05T19:05:51+00:00",
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
      "generatedUntil": "2026-12-05"
    },
    "weekdays": [
      {
        "weekdayBit": 2,
        "promisedArrivalTime": "08:30:00",
        "departureTime": "06:51:00",
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
    "rules": {},
    "upcomingTrips": [
      {
        "id": "01m6phc12hz7zh49zedqscvgmg",
        "tripDate": "2026-11-08",
        "departureAt": "2026-11-08T04:51:00+00:00",
        "departureLocal": "2026-11-08 06:51:00",
        "promisedArrivalAt": "2026-11-08T06:30:00+00:00",
        "promisedArrivalLocal": "2026-11-08 08:30:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phc12jqq4ey0yhggs5x336",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-09T05:21:00+00:00",
        "departureLocal": "2026-11-09 07:21:00",
        "promisedArrivalAt": "2026-11-09T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-09 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phc12mycgty2atjztqcg6s",
        "tripDate": "2026-11-10",
        "departureAt": "2026-11-10T05:21:00+00:00",
        "departureLocal": "2026-11-10 07:21:00",
        "promisedArrivalAt": "2026-11-10T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-10 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phc12n2b3fwky4m9j2q199",
        "tripDate": "2026-11-11",
        "departureAt": "2026-11-11T05:21:00+00:00",
        "departureLocal": "2026-11-11 07:21:00",
        "promisedArrivalAt": "2026-11-11T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-11 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phc12n2b3fwky4m9j2q19a",
        "tripDate": "2026-11-12",
        "departureAt": "2026-11-12T05:21:00+00:00",
        "departureLocal": "2026-11-12 07:21:00",
        "promisedArrivalAt": "2026-11-12T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-12 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
        "id": "01m6phc12p1qbrw8kszyh4kma2",
        "tripDate": "2026-11-15",
        "departureAt": "2026-11-15T04:51:00+00:00",
        "departureLocal": "2026-11-15 06:51:00",
        "promisedArrivalAt": "2026-11-15T06:30:00+00:00",
        "promisedArrivalLocal": "2026-11-15 08:30:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
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
      },
      {
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
      },
      {
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
      },
      {
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
      },
      {
        "id": "01m6phc12vc7epjb6aspg8khhh",
        "tripDate": "2026-11-22",
        "departureAt": "2026-11-22T04:51:00+00:00",
        "departureLocal": "2026-11-22 06:51:00",
        "promisedArrivalAt": "2026-11-22T06:30:00+00:00",
        "promisedArrivalLocal": "2026-11-22 08:30:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
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
      },
      {
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
      },
      {
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
      },
      {
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
      },
      {
        "id": "01m6phc12y60tvsky7gx3nrbcj",
        "tripDate": "2026-11-29",
        "departureAt": "2026-11-29T04:51:00+00:00",
        "departureLocal": "2026-11-29 06:51:00",
        "promisedArrivalAt": "2026-11-29T06:30:00+00:00",
        "promisedArrivalLocal": "2026-11-29 08:30:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      },
      {
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
      },
      {
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
      },
      {
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
      },
      {
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
    ]
  }
}
```

**POST /driver/commutes/{commute}/change-proposals** with `{"scheduledTripId": "01m6phc12hz7zh49zedqscvgmg", "scope": "all_future_on_this_weekday", "pickupPoints": [{"lat": 31.2, "lng": 29.9, "address": "Alexandria"}]}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_ROUTE_INVALID",
    "message": "Please check the route: the origin and destination must differ, and pickup points must be along the way.",
    "fields": {
      "pickups.0": [
        "That pickup point is more than 30 minutes out of your way. Move it closer to your route."
      ]
    }
  }
}
```

### Seats on a published day

- **State:** `DC-NOBODY-BOOKED`
- **Phone:** `+201055557503`
- **Captured:** 2026-11-05 19:17:29 UTC, locally

**PATCH /driver/commutes/{commute}/seats** with `{"seatsTotal": 2, "scope": "only_this_occurrence", "scheduledTripId": "01m6phc12hz7zh49zedqscvgmg"}` → `200`

```json
{
  "success": true,
  "data": {
    "seatsTotal": 2,
    "scope": "only_this_occurrence",
    "updatedDays": 1,
    "tripIds": [
      "01m6phc12hz7zh49zedqscvgmg"
    ]
  }
}
```

**PATCH /driver/commutes/{commute}/seats** with `{"seatsTotal": 2, "scope": "all_future_on_this_weekday", "scheduledTripId": "01m6phc12hz7zh49zedqscvgmg"}` → `200`

```json
{
  "success": true,
  "data": {
    "seatsTotal": 2,
    "scope": "all_future_on_this_weekday",
    "updatedDays": 4,
    "tripIds": [
      "01m6phc12hz7zh49zedqscvgmg",
      "01m6phc12p1qbrw8kszyh4kma2",
      "01m6phc12vc7epjb6aspg8khhh",
      "01m6phc12y60tvsky7gx3nrbcj"
    ]
  }
}
```

**PATCH /driver/commutes/{commute}/seats** with `{"seatsTotal": 3, "scope": "all_future_days"}` → `200`

```json
{
  "success": true,
  "data": {
    "seatsTotal": 3,
    "scope": "all_future_days",
    "updatedDays": 20,
    "tripIds": [
      "01m6phc12hz7zh49zedqscvgmg",
      "01m6phc12jqq4ey0yhggs5x336",
      "01m6phc12mycgty2atjztqcg6s",
      "01m6phc12n2b3fwky4m9j2q199",
      "01m6phc12n2b3fwky4m9j2q19a",
      "01m6phc12p1qbrw8kszyh4kma2",
      "01m6phc12qvym1evd55xcympfr",
      "01m6phc12r44cx1z3fx27vq2r4",
      "01m6phc12sd17xyxd67yfvp2y5",
      "01m6phc12t8mbeke22f8ekrx4q",
      "01m6phc12vc7epjb6aspg8khhh",
      "01m6phc12wt1z7s75xrkgg4gek",
      "01m6phc12wt1z7s75xrkgg4gem",
      "01m6phc12xf5cea6kfy3mdpyn9",
      "01m6phc12y60tvsky7gx3nrbch",
      "01m6phc12y60tvsky7gx3nrbcj",
      "01m6phc12zkf1mz088tm3debqh",
      "01m6phc130mqqf0spyac1rrgxm",
      "01m6phc13170svacy28vyhwc06",
      "01m6phc1329q089prt7rd1w72a"
    ]
  }
}
```

**PATCH /driver/commutes/{commute}/seats** with `{"seatsTotal": 6, "scope": "all_future_days"}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_SEATS_EXCEED_APPROVED",
    "message": "That is more seats than were approved on your car.",
    "fields": {
      "seatsTotal": [
        "You can offer at most 3 seats: that is how many seats with working seat belts were approved on your car."
      ],
      "approvedSeats": [
        "3"
      ]
    }
  }
}
```

**PATCH /driver/commutes/{commute}/seats** with `{"seatsTotal": 2, "scope": "only_this_occurrence"}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "scheduledTripId": [
        "The scheduled trip id field is required unless scope is in all_future_days."
      ]
    }
  }
}
```

### Fewer seats than are taken

- **State:** `DC-PASSENGERS`
- **Phone:** `+201055557501`
- **Captured:** 2026-11-05 19:17:31 UTC, locally

**PATCH /driver/commutes/{commute}/seats** with `{"seatsTotal": 1, "scope": "only_this_occurrence", "scheduledTripId": "01m6phbr5ym09dznhyn5sy04vm"}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_SEATS_CONFLICT",
    "message": "You already have more passengers booked than that number of seats.",
    "fields": {
      "seatsTotal": [
        "3"
      ]
    }
  }
}
```

## Proposals (G8)

### Open, mixed answers

- **State:** `DC-PROPOSAL-OPEN`
- **Phone:** `+201055557541`
- **Captured:** 2026-11-05 19:17:32 UTC, locally

**GET /driver/change-proposals?commuteId={commute}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pja5tz8q0ktz5qa73dnfsr",
      "commuteId": "01m6phc63c24dkjxadaa0ftsc7",
      "tripId": "01m6phc6b1gxygydp86dpk64x7",
      "tripDate": "2026-11-08",
      "scope": "only_this_occurrence",
      "status": "PENDING",
      "version": 1,
      "changes": {
        "departureTime": null,
        "promisedArrivalTime": "08:30:00",
        "pickupPoints": null
      },
      "previous": {
        "departureTime": null,
        "promisedArrivalTime": "09:00:00",
        "pickupPoints": null
      },
      "effectiveFrom": null,
      "expiresAt": "2026-11-08T05:21:00+00:00",
      "appliedAt": null,
      "createdAt": "2026-11-05T19:17:31+00:00",
      "affectedCount": 3,
      "acceptedCount": 1,
      "passengers": [
        {
          "bookingId": "01m6pja5spy8dh9qmvq7z9v0b8",
          "passengerFirstName": "Salma",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:40:48",
          "newPickupLocal": "07:10:48",
          "response": "ACCEPTED"
        },
        {
          "bookingId": "01m6pja5t36xqnq4q3empmz049",
          "passengerFirstName": "Nour",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:50:42",
          "newPickupLocal": "07:20:42",
          "response": "AWAITING_RESPONSE"
        },
        {
          "bookingId": "01m6pja5tf19qpbr0whhd9d74p",
          "passengerFirstName": "Hana",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "08:00:36",
          "newPickupLocal": "07:30:36",
          "response": "AWAITING_RESPONSE"
        }
      ]
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

**POST /driver/commutes/{commute}/change-proposals** with `{"scheduledTripId": "01m6phc6b1gxygydp86dpk64x7", "scope": "only_this_occurrence", "promisedArrivalTime": "08:45:00"}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "CHANGE_PROPOSAL_ALREADY_OPEN",
    "message": "You already have a change waiting on your passengers. Edit that one instead of sending another.",
    "fields": null
  }
}
```

**PATCH /driver/change-proposals/{proposal}** with `{"promisedArrivalTime": "08:20:00", "scheduledTripId": "01m6phc6b1gxygydp86dpk64x7", "scope": "only_this_occurrence"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6pja5tz8q0ktz5qa73dnfsr",
    "commuteId": "01m6phc63c24dkjxadaa0ftsc7",
    "tripId": "01m6phc6b1gxygydp86dpk64x7",
    "tripDate": "2026-11-08",
    "scope": "only_this_occurrence",
    "status": "PENDING",
    "version": 2,
    "changes": {
      "departureTime": null,
      "promisedArrivalTime": "08:20:00",
      "pickupPoints": null
    },
    "previous": {
      "departureTime": null,
      "promisedArrivalTime": "09:00:00",
      "pickupPoints": null
    },
    "effectiveFrom": null,
    "expiresAt": "2026-11-08T05:21:00+00:00",
    "appliedAt": null,
    "createdAt": "2026-11-05T19:17:31+00:00",
    "affectedCount": 3,
    "acceptedCount": 0,
    "passengers": [
      {
        "bookingId": "01m6pja5spy8dh9qmvq7z9v0b8",
        "passengerFirstName": "Salma",
        "tripDate": "2026-11-08",
        "pickupLabel": "Al Rehab Mall entrance",
        "previousPickupLocal": "07:40:48",
        "newPickupLocal": "07:00:48",
        "response": "AWAITING_RESPONSE"
      },
      {
        "bookingId": "01m6pja5t36xqnq4q3empmz049",
        "passengerFirstName": "Nour",
        "tripDate": "2026-11-08",
        "pickupLabel": "Al Rehab Mall entrance",
        "previousPickupLocal": "07:50:42",
        "newPickupLocal": "07:10:42",
        "response": "AWAITING_RESPONSE"
      },
      {
        "bookingId": "01m6pja5tf19qpbr0whhd9d74p",
        "passengerFirstName": "Hana",
        "tripDate": "2026-11-08",
        "pickupLabel": "Al Rehab Mall entrance",
        "previousPickupLocal": "08:00:36",
        "newPickupLocal": "07:20:36",
        "response": "AWAITING_RESPONSE"
      }
    ]
  }
}
```

**DELETE /driver/change-proposals/{proposal}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6pja5tz8q0ktz5qa73dnfsr",
    "commuteId": "01m6phc63c24dkjxadaa0ftsc7",
    "tripId": "01m6phc6b1gxygydp86dpk64x7",
    "tripDate": "2026-11-08",
    "scope": "only_this_occurrence",
    "status": "WITHDRAWN",
    "version": 2,
    "changes": {
      "departureTime": null,
      "promisedArrivalTime": "08:20:00",
      "pickupPoints": null
    },
    "previous": {
      "departureTime": null,
      "promisedArrivalTime": "09:00:00",
      "pickupPoints": null
    },
    "effectiveFrom": null,
    "expiresAt": "2026-11-08T05:21:00+00:00",
    "appliedAt": null,
    "createdAt": "2026-11-05T19:17:31+00:00"
  }
}
```

**POST /driver/commutes/{commute}/change-proposals** with `{"scheduledTripId": "01m6phc6b1gxygydp86dpk64x7", "scope": "only_this_occurrence"}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "departureTime": [
        "Change at least one of the departure time, the promised arrival or the pickup points."
      ]
    }
  }
}
```

**POST /driver/commutes/{commute}/change-proposals** with `{"scheduledTripId": "01m6phc6b1gxygydp86dpk64x7", "scope": "only_this_occurrence", "pickupPoints": [{"lat": 30.06, "lng": 31.39, "address": "Rehab Mall"}]}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "scope": [
        "Pickup points are shared across this commute’s weekdays, so changing them applies to all future occurrences."
      ]
    }
  }
}
```

**POST /driver/commutes/{commute}/change-proposals** with `{"scheduledTripId": "01m6phc6b1gxygydp86dpk64x7", "scope": "only_this_occurrence", "promisedArrivalTime": "08:40:00"}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pja6w9d2g55qj0acfksq22",
    "commuteId": "01m6phc63c24dkjxadaa0ftsc7",
    "tripId": "01m6phc6b1gxygydp86dpk64x7",
    "tripDate": "2026-11-08",
    "scope": "only_this_occurrence",
    "status": "PENDING",
    "version": 1,
    "changes": {
      "departureTime": null,
      "promisedArrivalTime": "08:40:00",
      "pickupPoints": null
    },
    "previous": {
      "departureTime": null,
      "promisedArrivalTime": "09:00:00",
      "pickupPoints": null
    },
    "effectiveFrom": null,
    "expiresAt": "2026-11-08T05:21:00+00:00",
    "appliedAt": null,
    "createdAt": "2026-11-05T19:17:32+00:00",
    "affectedCount": 3,
    "acceptedCount": 0,
    "passengers": [
      {
        "bookingId": "01m6pja5spy8dh9qmvq7z9v0b8",
        "passengerFirstName": "Salma",
        "tripDate": "2026-11-08",
        "pickupLabel": "Al Rehab Mall entrance",
        "previousPickupLocal": "07:40:48",
        "newPickupLocal": "07:20:48",
        "response": "AWAITING_RESPONSE"
      },
      {
        "bookingId": "01m6pja5t36xqnq4q3empmz049",
        "passengerFirstName": "Nour",
        "tripDate": "2026-11-08",
        "pickupLabel": "Al Rehab Mall entrance",
        "previousPickupLocal": "07:50:42",
        "newPickupLocal": "07:30:42",
        "response": "AWAITING_RESPONSE"
      },
      {
        "bookingId": "01m6pja5tf19qpbr0whhd9d74p",
        "passengerFirstName": "Hana",
        "tripDate": "2026-11-08",
        "pickupLabel": "Al Rehab Mall entrance",
        "previousPickupLocal": "08:00:36",
        "newPickupLocal": "07:40:36",
        "response": "AWAITING_RESPONSE"
      }
    ]
  }
}
```

### Revised, answers reset

- **State:** `DC-PROPOSAL-REVISED`
- **Phone:** `+201055557542`
- **Captured:** 2026-11-05 19:17:33 UTC, locally

**GET /driver/change-proposals?commuteId={commute}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pja77eghn3fay6kjxca6dq",
      "commuteId": "01m6phc6jz3kgfgs8x7975f9dm",
      "tripId": "01m6phc6t2xeryw9k5cxwsqm3j",
      "tripDate": "2026-11-08",
      "scope": "only_this_occurrence",
      "status": "PENDING",
      "version": 2,
      "changes": {
        "departureTime": null,
        "promisedArrivalTime": "08:15:00",
        "pickupPoints": null
      },
      "previous": {
        "departureTime": null,
        "promisedArrivalTime": "09:00:00",
        "pickupPoints": null
      },
      "effectiveFrom": null,
      "expiresAt": "2026-11-08T05:21:00+00:00",
      "appliedAt": null,
      "createdAt": "2026-11-05T19:17:33+00:00",
      "affectedCount": 2,
      "acceptedCount": 0,
      "passengers": [
        {
          "bookingId": "01m6pja76hsdx9sv4nnn58h7mj",
          "passengerFirstName": "Salma",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:40:48",
          "newPickupLocal": "06:55:48",
          "response": "AWAITING_RESPONSE"
        },
        {
          "bookingId": "01m6pja76ztkz4vzn2fakw9rsn",
          "passengerFirstName": "Nour",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:50:42",
          "newPickupLocal": "07:05:42",
          "response": "AWAITING_RESPONSE"
        }
      ]
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

### Rejected

- **State:** `DC-PROPOSAL-REJECTED`
- **Phone:** `+201055557543`
- **Captured:** 2026-11-05 19:17:35 UTC, locally

**GET /driver/change-proposals?commuteId={commute}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pja8f9hktkqkj291ec5jq2",
      "commuteId": "01m6phc71a0m7kv64e0r4z1tdh",
      "tripId": "01m6phc790dcztxhnm1yh0qrwv",
      "tripDate": "2026-11-08",
      "scope": "only_this_occurrence",
      "status": "REJECTED",
      "version": 1,
      "changes": {
        "departureTime": null,
        "promisedArrivalTime": "08:30:00",
        "pickupPoints": null
      },
      "previous": {
        "departureTime": null,
        "promisedArrivalTime": "09:00:00",
        "pickupPoints": null
      },
      "effectiveFrom": null,
      "expiresAt": "2026-11-08T05:21:00+00:00",
      "appliedAt": null,
      "createdAt": "2026-11-05T19:17:34+00:00",
      "affectedCount": 2,
      "acceptedCount": 1,
      "passengers": [
        {
          "bookingId": "01m6pja8ecda9p04py80zzv6mz",
          "passengerFirstName": "Salma",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:40:48",
          "newPickupLocal": "07:10:48",
          "response": "ACCEPTED"
        },
        {
          "bookingId": "01m6pja8esngaf8r9zht8jzdwb",
          "passengerFirstName": "Nour",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:50:42",
          "newPickupLocal": "07:20:42",
          "response": "REJECTED"
        }
      ]
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

### Applied

- **State:** `DC-PROPOSAL-APPLIED`
- **Phone:** `+201055557544`
- **Captured:** 2026-11-05 19:17:36 UTC, locally

**GET /driver/change-proposals?commuteId={commute}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pja9pw1qsm4wsdxzgx38k0",
      "commuteId": "01m6phc7fy0d77d4stexnvg02c",
      "tripId": "01m6phc7qg52gqhcaacv1c69pr",
      "tripDate": "2026-11-08",
      "scope": "only_this_occurrence",
      "status": "APPLIED",
      "version": 1,
      "changes": {
        "departureTime": null,
        "promisedArrivalTime": "08:30:00",
        "pickupPoints": null
      },
      "previous": {
        "departureTime": null,
        "promisedArrivalTime": "09:00:00",
        "pickupPoints": null
      },
      "effectiveFrom": null,
      "expiresAt": "2026-11-08T05:21:00+00:00",
      "appliedAt": "2026-11-05T19:17:35+00:00",
      "createdAt": "2026-11-05T19:17:35+00:00",
      "affectedCount": 2,
      "acceptedCount": 2,
      "passengers": [
        {
          "bookingId": "01m6pja9p05mdar2hg1ggw7yqd",
          "passengerFirstName": "Salma",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:40:48",
          "newPickupLocal": "07:10:48",
          "response": "ACCEPTED"
        },
        {
          "bookingId": "01m6pja9pd4e9aaam6fa40v12a",
          "passengerFirstName": "Nour",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:50:42",
          "newPickupLocal": "07:20:42",
          "response": "ACCEPTED"
        }
      ]
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

### Expired

- **State:** `DC-PROPOSAL-EXPIRED`
- **Phone:** `+201055557545`
- **Captured:** 2026-11-05 19:17:37 UTC, locally

**GET /driver/change-proposals?commuteId={commute}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pjab0bn1ep7e16v1b13t9b",
      "commuteId": "01m6phc7yrr95d1rj63cnyqmz4",
      "tripId": "01m6phc855nmtshrn8m56cd4g4",
      "tripDate": "2026-11-08",
      "scope": "only_this_occurrence",
      "status": "EXPIRED",
      "version": 1,
      "changes": {
        "departureTime": null,
        "promisedArrivalTime": "08:30:00",
        "pickupPoints": null
      },
      "previous": {
        "departureTime": null,
        "promisedArrivalTime": "09:00:00",
        "pickupPoints": null
      },
      "effectiveFrom": null,
      "expiresAt": "2026-11-05T18:17:36+00:00",
      "appliedAt": null,
      "createdAt": "2026-11-05T19:17:36+00:00",
      "affectedCount": 2,
      "acceptedCount": 0,
      "passengers": [
        {
          "bookingId": "01m6pjaazejqkydee8bmzydh1e",
          "passengerFirstName": "Salma",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:40:48",
          "newPickupLocal": "07:10:48",
          "response": "AWAITING_RESPONSE"
        },
        {
          "bookingId": "01m6pjaazv9f29zcygqmeg74jz",
          "passengerFirstName": "Nour",
          "tripDate": "2026-11-08",
          "pickupLabel": "Al Rehab Mall entrance",
          "previousPickupLocal": "07:50:42",
          "newPickupLocal": "07:20:42",
          "response": "AWAITING_RESPONSE"
        }
      ]
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

## Itinerary

### With passengers, stops interleaved

- **State:** `DC-ITINERARY`
- **Phone:** `+201055557551`
- **Captured:** 2026-11-05 19:17:39 UTC, locally

**GET /driver/commute-occurrences/{trip}/navigation-route** → `200`

```json
{
  "success": true,
  "data": {
    "status": "CONFIRMED",
    "stops": [
      {
        "id": "01M6PJAD4F687VJG8PM00PF5CN",
        "kind": "DRIVER_START",
        "sequence": 0,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0602,
        "lng": 31.4915,
        "label": "الرحاب، بوابة ٢",
        "etaAt": "2026-11-08T05:21:00+00:00",
        "legDurationSeconds": 0,
        "legDistanceMeters": 0,
        "reachedAt": null
      },
      {
        "id": "01M6PJAD4GQF8Y3JVWGYX0ZEPF",
        "kind": "PASSENGER_PICKUP",
        "sequence": 1,
        "bookingId": "01m6pjac6sg0sx3g1wd2m8q5v1",
        "passengerFirstName": "Salma",
        "lat": 30.06264,
        "lng": 31.39552,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T05:40:48+00:00",
        "legDurationSeconds": 1188,
        "legDistanceMeters": 9241,
        "reachedAt": null
      },
      {
        "id": "01M6PJAD4HZSCFAE570S223CYF",
        "kind": "PASSENGER_PICKUP",
        "sequence": 2,
        "bookingId": "01m6pjac76jvtgnkvt62nnyzyj",
        "passengerFirstName": "Nour",
        "lat": 30.06508,
        "lng": 31.29954,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T06:00:36+00:00",
        "legDurationSeconds": 1188,
        "legDistanceMeters": 9241,
        "reachedAt": null
      },
      {
        "id": "01M6PJAD4JC5NTPQTRNAXYFVR7",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 3,
        "bookingId": "01m6pjac6sg0sx3g1wd2m8q5v1",
        "passengerFirstName": "Salma",
        "lat": 30.06874,
        "lng": 31.15557,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:30:18+00:00",
        "legDurationSeconds": 1782,
        "legDistanceMeters": 13861,
        "reachedAt": null
      },
      {
        "id": "01M6PJAD4JC5NTPQTRNAXYFVR8",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 4,
        "bookingId": "01m6pjac76jvtgnkvt62nnyzyj",
        "passengerFirstName": "Nour",
        "lat": 30.07118,
        "lng": 31.05959,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:50:06+00:00",
        "legDurationSeconds": 1188,
        "legDistanceMeters": 9240,
        "reachedAt": null
      },
      {
        "id": "01M6PJAD4KZAX61GNS8DCBNBBM",
        "kind": "DRIVER_END",
        "sequence": 5,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0724,
        "lng": 31.0116,
        "label": "القرية الذكية، مبنى B6",
        "etaAt": "2026-11-08T07:00:00+00:00",
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

### Over the waypoint limit, with segments

- **State:** `DC-OVER-LIMIT`
- **Phone:** `+201055557552`
- **Captured:** 2026-11-05 19:17:40 UTC, locally

**GET /driver/commute-occurrences/{trip}/navigation-route** → `200`

```json
{
  "success": true,
  "data": {
    "status": "CONFIRMED",
    "stops": [
      {
        "id": "01M6PJAEEPM3NCXXK3E2ZAQFKB",
        "kind": "DRIVER_START",
        "sequence": 0,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0602,
        "lng": 31.4915,
        "label": "الرحاب، بوابة ٢",
        "etaAt": "2026-11-08T05:21:00+00:00",
        "legDurationSeconds": 0,
        "legDistanceMeters": 0,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEQ36KNR5NJZ5WK7Y22",
        "kind": "PASSENGER_PICKUP",
        "sequence": 1,
        "bookingId": "01m6pjadepv60gw0172fjg5gz5",
        "passengerFirstName": "Salma",
        "lat": 30.06142,
        "lng": 31.44351,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T05:30:54+00:00",
        "legDurationSeconds": 594,
        "legDistanceMeters": 4620,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEQ36KNR5NJZ5WK7Y23",
        "kind": "PASSENGER_PICKUP",
        "sequence": 2,
        "bookingId": "01m6pjadf3bpz30c5zscaegbt5",
        "passengerFirstName": "Nour",
        "lat": 30.06203,
        "lng": 31.419515,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T05:35:51+00:00",
        "legDurationSeconds": 297,
        "legDistanceMeters": 2310,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEERJ6W51673SP2KY2CY",
        "kind": "PASSENGER_PICKUP",
        "sequence": 3,
        "bookingId": "01m6pjadff6vd7g4hq8f84v6g6",
        "passengerFirstName": "Hana",
        "lat": 30.06264,
        "lng": 31.39552,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T05:40:48+00:00",
        "legDurationSeconds": 297,
        "legDistanceMeters": 2310,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEESZNJYTC2THSZD89FW",
        "kind": "PASSENGER_PICKUP",
        "sequence": 4,
        "bookingId": "01m6pjadg04q50vdzg9zx2wsvy",
        "passengerFirstName": "Mariam",
        "lat": 30.06325,
        "lng": 31.371525,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T05:45:45+00:00",
        "legDurationSeconds": 297,
        "legDistanceMeters": 2310,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEETNMFJB4SAQ4QHDC91",
        "kind": "PASSENGER_PICKUP",
        "sequence": 5,
        "bookingId": "01m6pjadgbkfsxbwrk2vnxckck",
        "passengerFirstName": "Yasmin",
        "lat": 30.06386,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T05:50:42+00:00",
        "legDurationSeconds": 297,
        "legDistanceMeters": 2310,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEETNMFJB4SAQ4QHDC92",
        "kind": "PASSENGER_PICKUP",
        "sequence": 6,
        "bookingId": "01m6pjadgqy5qq9qm03ahnzba9",
        "passengerFirstName": "Farida",
        "lat": 30.06447,
        "lng": 31.323535,
        "label": "Al Rehab Mall entrance",
        "etaAt": "2026-11-08T05:55:39+00:00",
        "legDurationSeconds": 297,
        "legDistanceMeters": 2310,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEV0JPNSPQCVWX732G2",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 7,
        "bookingId": "01m6pjadepv60gw0172fjg5gz5",
        "passengerFirstName": "Salma",
        "lat": 30.0663,
        "lng": 31.25155,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:10:30+00:00",
        "legDurationSeconds": 891,
        "legDistanceMeters": 6930,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEWX8R43D6ZX5BBJVWA",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 8,
        "bookingId": "01m6pjadf3bpz30c5zscaegbt5",
        "passengerFirstName": "Nour",
        "lat": 30.067154,
        "lng": 31.217957,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:17:26+00:00",
        "legDurationSeconds": 416,
        "legDistanceMeters": 3234,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEX09QVKD9AG9D5V93G",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 9,
        "bookingId": "01m6pjadff6vd7g4hq8f84v6g6",
        "passengerFirstName": "Hana",
        "lat": 30.068008,
        "lng": 31.184364,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:24:22+00:00",
        "legDurationSeconds": 416,
        "legDistanceMeters": 3234,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEYK08WJ5G98BQ3XXDK",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 10,
        "bookingId": "01m6pjadg04q50vdzg9zx2wsvy",
        "passengerFirstName": "Mariam",
        "lat": 30.068862,
        "lng": 31.150771,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:31:18+00:00",
        "legDurationSeconds": 416,
        "legDistanceMeters": 3234,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEYK08WJ5G98BQ3XXDM",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 11,
        "bookingId": "01m6pjadgbkfsxbwrk2vnxckck",
        "passengerFirstName": "Yasmin",
        "lat": 30.069716,
        "lng": 31.117178,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:38:14+00:00",
        "legDurationSeconds": 416,
        "legDistanceMeters": 3234,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEEZ8DKAK1AV3Q4M7YAB",
        "kind": "PASSENGER_DROPOFF",
        "sequence": 12,
        "bookingId": "01m6pjadgqy5qq9qm03ahnzba9",
        "passengerFirstName": "Farida",
        "lat": 30.07057,
        "lng": 31.083585,
        "label": "Smart Village B12",
        "etaAt": "2026-11-08T06:45:10+00:00",
        "legDurationSeconds": 416,
        "legDistanceMeters": 3234,
        "reachedAt": null
      },
      {
        "id": "01M6PJAEF0S0D7PCHXH060Y5EV",
        "kind": "DRIVER_END",
        "sequence": 13,
        "bookingId": null,
        "passengerFirstName": null,
        "lat": 30.0724,
        "lng": 31.0116,
        "label": "القرية الذكية، مبنى B6",
        "etaAt": "2026-11-08T07:00:01+00:00",
        "legDurationSeconds": 891,
        "legDistanceMeters": 6930,
        "reachedAt": null
      }
    ],
    "pointCount": 14,
    "polyline": "gcnvD{te_EsF|jHyB|tCyB~tCyB|tCyB~tCyB|tCmJ|`MiD|pEkD~pEiD|pEkD|pEiD|pEmJ|`M",
    "distanceMeters": 46200,
    "durationSeconds": 5941,
    "googleMapsDirectionsUrl": null,
    "exceedsWaypointLimit": true,
    "waypointLimit": 9,
    "segments": [
      "https://www.google.com/maps/dir/?api=1&origin=30.060200%2C31.491500&destination=30.068862%2C31.150771&travelmode=driving&waypoints=30.061420%2C31.443510%7C30.062030%2C31.419515%7C30.062640%2C31.395520%7C30.063250%2C31.371525%7C30.063860%2C31.347530%7C30.064470%2C31.323535%7C30.066300%2C31.251550%7C30.067154%2C31.217957%7C30.068008%2C31.184364",
      "https://www.google.com/maps/dir/?api=1&origin=30.068862%2C31.150771&destination=30.072400%2C31.011600&travelmode=driving&waypoints=30.069716%2C31.117178%7C30.070570%2C31.083585"
    ],
    "receivesRequestsDuringRide": true
  }
}
```

## Resume and offers

### Paused: previous passenger, recurring, one day, in another commute

- **State:** `DC-WAITLIST`
- **Phone:** `+201055557561`
- **Captured:** 2026-11-05 19:17:41 UTC, locally

**GET /driver/commutes/{commute}/waitlist** → `200`

```json
{
  "success": true,
  "data": [
    {
      "seatRequestId": "01m6pjaes6jx70vsttvknnsdxf",
      "commuteId": "01m6phc96j56ffvwefaymg6pr5",
      "commitment": "recurring",
      "requestedDaysMask": 127,
      "tripId": null,
      "seats": 1,
      "waitlistPosition": 1,
      "joinedWaitlistAt": "2026-11-03T19:17:40+00:00",
      "tripDate": null,
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
        "publicFirstName": "Layla",
        "trustLevel": 3,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      }
    },
    {
      "seatRequestId": "01m6pjaesa77xcew4pphfjdfjs",
      "commuteId": "01m6phc96j56ffvwefaymg6pr5",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6phc9ed6bvcmw1zddkyjgbf",
      "seats": 1,
      "waitlistPosition": 2,
      "joinedWaitlistAt": "2026-11-02T19:17:40+00:00",
      "tripDate": "2026-11-09",
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
      "hasOtherConfirmedCommute": true,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Rana",
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
      "seatRequestId": "01m6pjaesejdyz1c5as3h2wxsh",
      "commuteId": "01m6phc96j56ffvwefaymg6pr5",
      "commitment": "recurring",
      "requestedDaysMask": 127,
      "tripId": null,
      "seats": 1,
      "waitlistPosition": 3,
      "joinedWaitlistAt": "2026-11-01T19:17:40+00:00",
      "tripDate": null,
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
      "hasOtherConfirmedCommute": true,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Reem",
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
      "seatRequestId": "01m6pjaersznyy1gmf1peka1pb",
      "commuteId": "01m6phc96j56ffvwefaymg6pr5",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6phc9ec3ev3w08075vt0mgr",
      "seats": 1,
      "waitlistPosition": 4,
      "joinedWaitlistAt": "2026-11-05T19:17:40+00:00",
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
      "isPreviousPassenger": true,
      "displacedAt": "2026-11-05T19:17:40+00:00",
      "displacedReason": "commute_paused",
      "hasOtherConfirmedCommute": false,
      "introMessage": "أهلاً! بتنقل يوميًا نفس الطريق وحابة أجرب مجموعتك.",
      "meetingPreference": "gate",
      "paymentType": "cash",
      "passenger": {
        "publicFirstName": "Dina",
        "trustLevel": 2,
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
    "total": 4,
    "lastPage": 1,
    "hasMore": false
  }
}
```

**POST /driver/commutes/{commute}/waitlist/offers** with `{"all": true}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_NOT_EDITABLE",
    "message": "This commute cannot be changed in its current state.",
    "fields": {
      "status": [
        "paused"
      ]
    }
  }
}
```

**POST /commutes/{commute}/resume** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6phc96j56ffvwefaymg6pr5",
    "status": "PUBLISHED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6phc96gehryfjbx5w21jegy",
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
    "createdAt": "2026-11-05T19:01:12+00:00",
    "updatedAt": "2026-11-05T19:17:41+00:00",
    "formStep": null,
    "publishedAt": "2026-11-05T19:01:12+00:00",
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6phc9768qsrdfpk3q5xc4ms",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6phc97j4nakvc1gw0xt994r",
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
      "generatedUntil": "2026-12-05"
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

**POST /driver/commutes/{commute}/waitlist/offers** with `{"seatRequestIds": ["01m6pjaes6jx70vsttvknnsdxf"]}` → `200`

```json
{
  "success": true,
  "data": {
    "membersOffered": 1,
    "offersCreated": 1
  }
}
```

**POST /driver/commutes/{commute}/waitlist/offers** with `{"all": true}` → `200`

```json
{
  "success": true,
  "data": {
    "membersOffered": 4,
    "offersCreated": 4
  }
}
```

**GET /driver/seat-offers** → `200`

```json
{
  "success": true,
  "data": [
    {
      "releaseEventId": "01M6PJAFT429KWHXJV7E2MMJXZ",
      "replacesBookingId": null,
      "tripId": "01m6phc9ec3ev3w08075vt0mgr",
      "commuteId": "01m6phc96j56ffvwefaymg6pr5",
      "date": "2026-11-08",
      "departureLocal": "2026-11-08 07:21:00",
      "status": "OPEN",
      "recipientsCount": 1,
      "acceptedBy": null,
      "members": [
        {
          "offerId": "01M6PJAFT8A9KR1QM1T1TV0Z2M",
          "seatRequestId": "01m6pjaes6jx70vsttvknnsdxf",
          "publicFirstName": "Layla",
          "status": "OFFERED"
        }
      ]
    },
    {
      "releaseEventId": "01M6PJAFVG6R891XY9BR7C9HKQ",
      "replacesBookingId": null,
      "tripId": "01m6phc9ed6bvcmw1zddkyjgbf",
      "commuteId": "01m6phc96j56ffvwefaymg6pr5",
      "date": "2026-11-09",
      "departureLocal": "2026-11-09 07:21:00",
      "status": "OPEN",
      "recipientsCount": 4,
      "acceptedBy": null,
      "members": [
        {
          "offerId": "01M6PJAFVNJZ0FWVN85J7JE5VW",
          "seatRequestId": "01m6pjaes6jx70vsttvknnsdxf",
          "publicFirstName": "Layla",
          "status": "OFFERED"
        },
        {
          "offerId": "01M6PJAFWGFYKSH0JHRXTRH6KS",
          "seatRequestId": "01m6pjaesa77xcew4pphfjdfjs",
          "publicFirstName": "Rana",
          "status": "OFFERED"
        },
        {
          "offerId": "01M6PJAFWPV2JT21DFY8ZQV975",
          "seatRequestId": "01m6pjaesejdyz1c5as3h2wxsh",
          "publicFirstName": "Reem",
          "status": "OFFERED"
        },
        {
          "offerId": "01M6PJAFXGRMMSYPKJTRFDK06W",
          "seatRequestId": "01m6pjaersznyy1gmf1peka1pb",
          "publicFirstName": "Dina",
          "status": "OFFERED"
        }
      ]
    }
  ]
}
```

### Offers: open, withdrawn, accepted

- **State:** `DC-OFFERS`
- **Phone:** `+201055557562`
- **Captured:** 2026-11-05 19:17:43 UTC, locally

**GET /driver/seat-offers** → `200`

```json
{
  "success": true,
  "data": [
    {
      "releaseEventId": "01M6PJAG88KEJHNCSVY44JGNWR",
      "replacesBookingId": null,
      "tripId": "01m6phca4f9ybv6893gev5rje0",
      "commuteId": "01m6phc9xtf0r5be87rqcv3hmr",
      "date": "2026-11-08",
      "departureLocal": "2026-11-08 07:21:00",
      "status": "OPEN",
      "recipientsCount": 1,
      "acceptedBy": null,
      "members": [
        {
          "offerId": "01m6pjag88kejhncsvy44jgnws",
          "seatRequestId": "01m6pjag7s5ts8kzq1f358zm16",
          "publicFirstName": "Salma",
          "status": "OFFERED"
        }
      ]
    },
    {
      "releaseEventId": "01M6PJAG89VPJ65YHJY0XP73SG",
      "replacesBookingId": null,
      "tripId": "01m6phca4h5qp16w6ad58r50q7",
      "commuteId": "01m6phc9xtf0r5be87rqcv3hmr",
      "date": "2026-11-09",
      "departureLocal": "2026-11-09 07:21:00",
      "status": "CLOSED",
      "recipientsCount": 1,
      "acceptedBy": null,
      "members": [
        {
          "offerId": "01m6pjag89vpj65yhjy0xp73sh",
          "seatRequestId": "01m6pjag7y8whpz8qhrbcz0kda",
          "publicFirstName": "Nour",
          "status": "OFFER_CLOSED"
        }
      ]
    },
    {
      "releaseEventId": "01M6PJAG8B31YA9VC6QM21YTFM",
      "replacesBookingId": null,
      "tripId": "01m6phca4j0n0ghrkns224gtwr",
      "commuteId": "01m6phc9xtf0r5be87rqcv3hmr",
      "date": "2026-11-10",
      "departureLocal": "2026-11-10 07:21:00",
      "status": "ACCEPTED",
      "recipientsCount": 2,
      "acceptedBy": {
        "seatRequestId": "01m6pjag82ca4ym2h6q20nwf56",
        "publicFirstName": "Hana"
      },
      "members": [
        {
          "offerId": "01m6pjag8b31ya9vc6qm21ytfn",
          "seatRequestId": "01m6pjag82ca4ym2h6q20nwf56",
          "publicFirstName": "Hana",
          "status": "CONFIRMED"
        },
        {
          "offerId": "01m6pjag8cvzh15ft9qa8ssgxn",
          "seatRequestId": "01m6pjag863c1e2favf9qvnmrh",
          "publicFirstName": "Mariam",
          "status": "OFFER_CLOSED"
        }
      ]
    }
  ]
}
```

**DELETE /driver/seat-offers/{offer}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6pjag88kejhncsvy44jgnws",
    "status": "WITHDRAWN",
    "tripId": "01m6phca4f9ybv6893gev5rje0",
    "seatRequestId": "01m6pjag7s5ts8kzq1f358zm16",
    "offeredAt": "2026-11-05T19:17:42+00:00",
    "closedAt": "2026-11-05T19:17:43+00:00",
    "trip": {
      "date": "2026-11-08",
      "departureAt": "2026-11-08T05:21:00+00:00",
      "departureLocal": "2026-11-08 07:21:00",
      "pricePiastres": 12500
    },
    "originLabel": null,
    "destinationLabel": null,
    "driver": null,
    "vehicle": null,
    "pickup": null,
    "dropoff": null,
    "promisedArrivalLocal": "2026-11-08 09:00:00",
    "conflictingBookingId": null
  }
}
```

**DELETE /driver/seat-offers/{acceptedOffer}** → `409`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_OFFER_CLOSED",
    "message": "That seat has already gone to someone else.",
    "fields": {
      "status": [
        "accepted"
      ]
    }
  }
}
```

## Replace a passenger (G9)

### Offer the seat, and it's accepted

- **State:** `DC-REPLACE`
- **Phone:** `+201055557571`
- **Captured:** 2026-11-05 19:17:44 UTC, locally

**GET /driver/waitlist?tripId={trip}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "seatRequestId": "01m6pjahhmbrvhrm1ekrcr3dqe",
      "commuteId": "01m6phcabr69s361b84dcac6v8",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6phcam787mcwpe1ne8n71hz",
      "seats": 1,
      "waitlistPosition": 1,
      "joinedWaitlistAt": "2026-11-04T19:17:43+00:00",
      "tripDate": "2026-11-07",
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
        "publicFirstName": "Rana",
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
      "seatRequestId": "01m6pjahhrzn1jwmfsc7rx2yfg",
      "commuteId": "01m6phcabr69s361b84dcac6v8",
      "commitment": "trial",
      "requestedDaysMask": null,
      "tripId": "01m6phcam787mcwpe1ne8n71hz",
      "seats": 1,
      "waitlistPosition": 2,
      "joinedWaitlistAt": "2026-11-03T19:17:43+00:00",
      "tripDate": "2026-11-07",
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
        "publicFirstName": "Aya",
        "trustLevel": 4,
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
        "date": "2026-11-07",
        "tripId": "01m6phcam787mcwpe1ne8n71hz",
        "commuteId": "01m6phcabr69s361b84dcac6v8",
        "departureLocal": "2026-11-07 13:17:00",
        "isFull": true,
        "waitingCount": 2
      }
    ]
  }
}
```

**GET /driver/bookings/{booking}/cancel-preview** → `200`

```json
{
  "success": true,
  "data": {
    "affectedSeats": 1,
    "feePiastres": 0,
    "reason": "none",
    "perTrip": [
      {
        "tripId": "01m6phcam787mcwpe1ne8n71hz",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "seats": 1,
        "percent": 0,
        "feePiastres": 0
      }
    ]
  }
}
```

**POST /driver/bookings/{booking}/replacement-offer** with `{"seatRequestId": "01m6pjahhmbrvhrm1ekrcr3dqe"}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pjahgts2rtn7x82q3xevtz",
    "tripId": "01m6phcam787mcwpe1ne8n71hz",
    "commuteGroupId": null,
    "membership": null,
    "commuteId": "01m6phcabr69s361b84dcac6v8",
    "passenger": {
      "publicFirstName": "Salma",
      "trustLevel": 2,
      "verifiedLevels": [],
      "sameOrganisation": false,
      "rating": null,
      "ratingCount": 0,
      "completedTrips": 0,
      "onTimeRate": null
    },
    "replacementOffer": {
      "offerId": "01M6PJAJH82T66QKX6J27HM0XB",
      "seatRequestId": "01m6pjahhmbrvhrm1ekrcr3dqe",
      "passengerFirstName": "Rana",
      "status": "OPEN",
      "offeredAt": "2026-11-05T19:17:44+00:00",
      "closedAt": null
    },
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
    "createdAt": "2026-11-05T19:17:43+00:00",
    "driverDelay": null,
    "pickupEta": null,
    "pickupEtaLocal": null,
    "trip": {
      "id": "01m6phcam787mcwpe1ne8n71hz",
      "tripDate": "2026-11-07",
      "departureAt": "2026-11-07T11:17:00+00:00",
      "departureLocal": "2026-11-07 13:17:00",
      "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
      "promisedArrivalLocal": "2026-11-07 15:17:00",
      "status": "SCHEDULED",
      "seatsTotal": 2,
      "seatsTaken": 2,
      "seatsAvailable": 0,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driverSharePiastres": 11551
    }
  }
}
```

**POST /driver/bookings/{booking}/replacement-offer** with `{"seatRequestId": "01m6pjahhrzn1jwmfsc7rx2yfg"}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "REPLACEMENT_ALREADY_OPEN",
    "message": "You already offered this seat to someone. Withdraw that offer before sending another.",
    "fields": null
  }
}
```

**POST /driver/bookings/{booking}/replacement-offer** with `{"seatRequestId": "01JABCDEFGHJKMNPQRSTVWXYZ0"}` → `404`

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

**GET /driver/bookings?tripId={trip}&status=confirmed** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pjahh9m77trretm7kz2kba",
      "tripId": "01m6phcam787mcwpe1ne8n71hz",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcabr69s361b84dcac6v8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 2,
        "verifiedLevels": [],
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
        "lat": 30.06386,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.07118,
        "lng": 31.05959,
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
      "createdAt": "2026-11-05T19:17:43+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:46:42+00:00",
      "pickupEtaLocal": "2026-11-07 13:46:42",
      "trip": {
        "id": "01m6phcam787mcwpe1ne8n71hz",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pjahgts2rtn7x82q3xevtz",
      "tripId": "01m6phcam787mcwpe1ne8n71hz",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcabr69s361b84dcac6v8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": {
        "offerId": "01M6PJAJH82T66QKX6J27HM0XB",
        "seatRequestId": "01m6pjahhmbrvhrm1ekrcr3dqe",
        "passengerFirstName": "Rana",
        "status": "OPEN",
        "offeredAt": "2026-11-05T19:17:44+00:00",
        "closedAt": null
      },
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
      "createdAt": "2026-11-05T19:17:43+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:36:48+00:00",
      "pickupEtaLocal": "2026-11-07 13:36:48",
      "trip": {
        "id": "01m6phcam787mcwpe1ne8n71hz",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false
  }
}
```

### The replacement accepts

- **State:** `DC-REPLACE`
- **Phone:** `+201055557571`
- **Captured:** 2026-11-05 19:17:45 UTC, locally

**POST /seat-offers/{offer}/accept** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6pjakhr5yfpmh2jvx576cqx",
    "tripId": "01m6phcam787mcwpe1ne8n71hz",
    "commuteGroupId": "01m6phhk374z9jww7d26kv06aq",
    "membership": {
      "committedDaysMask": null,
      "commitment": "trial",
      "status": "ACTIVE"
    },
    "commuteId": "01m6phcabr69s361b84dcac6v8",
    "originLabel": "الرحاب، بوابة ٢",
    "destinationLabel": "القرية الذكية، مبنى B6",
    "status": "CONFIRMED",
    "seatsReserved": 1,
    "boardingCode": "4408",
    "price": {
      "seatPricePiastres": 12500,
      "totalPiastres": 12500
    },
    "paymentType": "cash",
    "paymentStatus": "NOT_DUE",
    "meetingPoint": {
      "lat": 30.06386,
      "lng": 31.34753,
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
    "cancellationFeePiastres": null,
    "feeLinePiastres": 0,
    "feeLineReason": null,
    "earlyDrop": {
      "askedAt": null,
      "distanceMeters": null,
      "answer": null
    },
    "createdAt": "2026-11-05T19:17:45+00:00",
    "driverDelay": null,
    "pickupEta": "2026-11-07T11:46:42+00:00",
    "pickupEtaLocal": "2026-11-07 13:46:42",
    "trip": {
      "id": "01m6phcam787mcwpe1ne8n71hz",
      "tripDate": "2026-11-07",
      "departureAt": "2026-11-07T11:17:00+00:00",
      "departureLocal": "2026-11-07 13:17:00",
      "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
      "promisedArrivalLocal": "2026-11-07 15:17:00",
      "status": "SCHEDULED",
      "seatsTotal": 2,
      "seatsTaken": 1,
      "seatsAvailable": 1,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500
    }
  },
  "meta": {
    "releasedDates": []
  }
}
```

**GET /driver/bookings?tripId={trip}** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pjakhr5yfpmh2jvx576cqx",
      "tripId": "01m6phcam787mcwpe1ne8n71hz",
      "commuteGroupId": "01m6phhk374z9jww7d26kv06aq",
      "membership": {
        "committedDaysMask": null,
        "commitment": "trial",
        "status": "ACTIVE"
      },
      "commuteId": "01m6phcabr69s361b84dcac6v8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Rana",
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
      "boardingCode": "4408",
      "price": {
        "seatPricePiastres": 12500,
        "totalPiastres": 12500,
        "driverSharePiastres": 11551,
        "driverShareTotalPiastres": 11551
      },
      "paymentType": "cash",
      "paymentStatus": "NOT_DUE",
      "meetingPoint": {
        "lat": 30.06386,
        "lng": 31.34753,
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
      "createdAt": "2026-11-05T19:17:45+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:46:42+00:00",
      "pickupEtaLocal": "2026-11-07 13:46:42",
      "trip": {
        "id": "01m6phcam787mcwpe1ne8n71hz",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pjahh9m77trretm7kz2kba",
      "tripId": "01m6phcam787mcwpe1ne8n71hz",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcabr69s361b84dcac6v8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 2,
        "verifiedLevels": [],
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
        "lat": 30.06386,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.07118,
        "lng": 31.05959,
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
      "createdAt": "2026-11-05T19:17:43+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:46:42+00:00",
      "pickupEtaLocal": "2026-11-07 13:46:42",
      "trip": {
        "id": "01m6phcam787mcwpe1ne8n71hz",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pjahgts2rtn7x82q3xevtz",
      "tripId": "01m6phcam787mcwpe1ne8n71hz",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcabr69s361b84dcac6v8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": {
        "offerId": "01M6PJAJH82T66QKX6J27HM0XB",
        "seatRequestId": "01m6pjahhmbrvhrm1ekrcr3dqe",
        "passengerFirstName": "Rana",
        "status": "ACCEPTED",
        "offeredAt": "2026-11-05T19:17:44+00:00",
        "closedAt": "2026-11-05T19:17:45+00:00"
      },
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
      "paymentStatus": "NOT_DUE",
      "meetingPoint": {
        "lat": 30.063,
        "lng": 31.396,
        "label": null,
        "isExact": false
      },
      "dropoffPoint": {
        "lat": 30.07,
        "lng": 31.108,
        "label": null,
        "isExact": false
      },
      "otherPartyUserId": null,
      "cancelledAt": "2026-11-05T19:17:45+00:00",
      "cancelledReason": "driver_replaced",
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
      "createdAt": "2026-11-05T19:17:43+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:36:48+00:00",
      "pickupEtaLocal": "2026-11-07 13:36:48",
      "trip": {
        "id": "01m6phcam787mcwpe1ne8n71hz",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
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

**POST /driver/bookings/{booking}/replacement-offer** with `{"seatRequestId": "01m6pjahhrzn1jwmfsc7rx2yfg"}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "BOOKING_NOT_CANCELLABLE",
    "message": "That booking can no longer be cancelled.",
    "fields": {
      "status": [
        "CANCELLED_BY_DRIVER"
      ]
    }
  }
}
```

### Waiting, then declined

- **State:** `DC-REPLACE-PENDING`
- **Phone:** `+201055557572`
- **Captured:** 2026-11-05 19:17:46 UTC, locally

**GET /driver/bookings?tripId={trip}&status=confirmed** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pjakz89h4sh7ywd7zsdnqj",
      "tripId": "01m6phcb2zgs3nd2qamsg1h20a",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcav6c9heqkq6e88xxbf8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 2,
        "verifiedLevels": [],
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
        "lat": 30.06386,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.07118,
        "lng": 31.05959,
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
      "createdAt": "2026-11-05T19:17:46+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:46:42+00:00",
      "pickupEtaLocal": "2026-11-07 13:46:42",
      "trip": {
        "id": "01m6phcb2zgs3nd2qamsg1h20a",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pjakytjcm8h3x2czbhmjv0",
      "tripId": "01m6phcb2zgs3nd2qamsg1h20a",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcav6c9heqkq6e88xxbf8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": {
        "offerId": "01M6PJAM015KWB1C9GH8ZBS3F5",
        "seatRequestId": "01m6pjakzjmth8tykp69mpg0mq",
        "passengerFirstName": "Rana",
        "status": "OPEN",
        "offeredAt": "2026-11-05T19:17:46+00:00",
        "closedAt": null
      },
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
      "createdAt": "2026-11-05T19:17:46+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:36:48+00:00",
      "pickupEtaLocal": "2026-11-07 13:36:48",
      "trip": {
        "id": "01m6phcb2zgs3nd2qamsg1h20a",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false
  }
}
```

**GET /driver/seat-offers** → `200`

```json
{
  "success": true,
  "data": [
    {
      "releaseEventId": "01M6PJAM015KWB1C9GH8ZBS3F6",
      "replacesBookingId": "01m6pjakytjcm8h3x2czbhmjv0",
      "tripId": "01m6phcb2zgs3nd2qamsg1h20a",
      "commuteId": "01m6phcav6c9heqkq6e88xxbf8",
      "date": "2026-11-07",
      "departureLocal": "2026-11-07 13:17:00",
      "status": "OPEN",
      "recipientsCount": 1,
      "acceptedBy": null,
      "members": [
        {
          "offerId": "01M6PJAM015KWB1C9GH8ZBS3F5",
          "seatRequestId": "01m6pjakzjmth8tykp69mpg0mq",
          "publicFirstName": "Rana",
          "status": "OFFERED"
        }
      ]
    }
  ]
}
```

**POST /seat-offers/{offer}/decline** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01M6PJAM015KWB1C9GH8ZBS3F5",
    "status": "DECLINED",
    "tripId": "01m6phcb2zgs3nd2qamsg1h20a",
    "seatRequestId": "01m6pjakzjmth8tykp69mpg0mq",
    "offeredAt": "2026-11-05T19:17:46+00:00",
    "closedAt": "2026-11-05T19:17:47+00:00",
    "trip": {
      "date": "2026-11-07",
      "departureAt": "2026-11-07T11:17:00+00:00",
      "departureLocal": "2026-11-07 13:17:00",
      "pricePiastres": 12500
    },
    "originLabel": "الرحاب، بوابة ٢",
    "destinationLabel": "القرية الذكية، مبنى B6",
    "driver": {
      "publicFirstName": "Dcreplacepending",
      "trustLevel": 3,
      "verifiedLevels": [],
      "sameOrganisation": false,
      "rating": null,
      "ratingCount": 0,
      "completedTrips": 0,
      "onTimeRate": 95
    },
    "vehicle": {
      "make": "Toyota",
      "model": "Corolla",
      "colour": "White"
    },
    "pickup": {
      "label": "Al Rehab Mall entrance"
    },
    "dropoff": {
      "label": "Smart Village B12"
    },
    "promisedArrivalLocal": "2026-11-07 15:17:00",
    "conflictingBookingId": null
  }
}
```

**GET /driver/bookings?tripId={trip}&status=confirmed** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6pjakz89h4sh7ywd7zsdnqj",
      "tripId": "01m6phcb2zgs3nd2qamsg1h20a",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcav6c9heqkq6e88xxbf8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Nour",
        "trustLevel": 2,
        "verifiedLevels": [],
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
        "lat": 30.06386,
        "lng": 31.34753,
        "label": "Al Rehab Mall entrance",
        "isExact": true
      },
      "dropoffPoint": {
        "lat": 30.07118,
        "lng": 31.05959,
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
      "createdAt": "2026-11-05T19:17:46+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:46:42+00:00",
      "pickupEtaLocal": "2026-11-07 13:46:42",
      "trip": {
        "id": "01m6phcb2zgs3nd2qamsg1h20a",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    },
    {
      "id": "01m6pjakytjcm8h3x2czbhmjv0",
      "tripId": "01m6phcb2zgs3nd2qamsg1h20a",
      "commuteGroupId": null,
      "membership": null,
      "commuteId": "01m6phcav6c9heqkq6e88xxbf8",
      "originLabel": "الرحاب، بوابة ٢",
      "destinationLabel": "القرية الذكية، مبنى B6",
      "passenger": {
        "publicFirstName": "Salma",
        "trustLevel": 2,
        "verifiedLevels": [],
        "sameOrganisation": false,
        "rating": null,
        "ratingCount": 0,
        "completedTrips": 0,
        "onTimeRate": null
      },
      "replacementOffer": {
        "offerId": "01M6PJAM015KWB1C9GH8ZBS3F5",
        "seatRequestId": "01m6pjakzjmth8tykp69mpg0mq",
        "passengerFirstName": "Rana",
        "status": "DECLINED",
        "offeredAt": "2026-11-05T19:17:46+00:00",
        "closedAt": "2026-11-05T19:17:47+00:00"
      },
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
      "createdAt": "2026-11-05T19:17:46+00:00",
      "driverDelay": null,
      "pickupEta": "2026-11-07T11:36:48+00:00",
      "pickupEtaLocal": "2026-11-07 13:36:48",
      "trip": {
        "id": "01m6phcb2zgs3nd2qamsg1h20a",
        "tripDate": "2026-11-07",
        "departureAt": "2026-11-07T11:17:00+00:00",
        "departureLocal": "2026-11-07 13:17:00",
        "promisedArrivalAt": "2026-11-07T13:17:00+00:00",
        "promisedArrivalLocal": "2026-11-07 15:17:00",
        "status": "SCHEDULED",
        "seatsTotal": 2,
        "seatsTaken": 2,
        "seatsAvailable": 0,
        "seatPricePiastres": 12500,
        "pricePerSeatPiastres": 12500,
        "driverSharePiastres": 11551
      }
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "hasMore": false
  }
}
```

### Withdrawn by the driver

- **State:** `DC-REPLACE-PENDING`
- **Phone:** `+201055557572`
- **Captured:** 2026-11-05 19:17:49 UTC, locally

**DELETE /driver/seat-offers/{offer}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01M6PJAP5K8XP7CJ1203WQ66H2",
    "status": "WITHDRAWN",
    "tripId": "01m6phcb2zgs3nd2qamsg1h20a",
    "seatRequestId": "01m6pjap54vzth8tdnj61g5991",
    "offeredAt": "2026-11-05T19:17:48+00:00",
    "closedAt": "2026-11-05T19:17:49+00:00",
    "trip": {
      "date": "2026-11-07",
      "departureAt": "2026-11-07T11:17:00+00:00",
      "departureLocal": "2026-11-07 13:17:00",
      "pricePiastres": 12500
    },
    "originLabel": null,
    "destinationLabel": null,
    "driver": null,
    "vehicle": null,
    "pickup": null,
    "dropoff": null,
    "promisedArrivalLocal": "2026-11-07 15:17:00",
    "conflictingBookingId": null
  }
}
```

**POST /seat-offers/{offer}/accept** → `409`

```json
{
  "success": false,
  "error": {
    "code": "SEAT_OFFER_CLOSED",
    "message": "That seat has already gone to someone else.",
    "fields": {
      "status": [
        "withdrawn"
      ]
    }
  }
}
```

### Inside the lock: only someone with the same stops

- **State:** `DC-REPLACE-LOCKED`
- **Phone:** `+201055557573`
- **Captured:** 2026-11-05 19:17:51 UTC, locally

**POST /driver/bookings/{booking}/replacement-offer** with `{"seatRequestId": "01m6pjarad5f2s8dss7j8x7dh9"}` → `409`

```json
{
  "success": false,
  "error": {
    "code": "REPLACEMENT_CHANGES_OTHERS",
    "message": "This day is too close to departure, and this replacement would change the trip for your other passengers.",
    "fields": {
      "lockedFrom": [
        "2026-11-05T08:17:00+00:00"
      ]
    }
  }
}
```

**POST /driver/bookings/{booking}/replacement-offer** with `{"seatRequestId": "01m6pjarahr6yw16gwaccfcnbz"}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pjar9n9p049cesx4q01xfc",
    "tripId": "01m6phcbh2jbdnt0hw23nsqw87",
    "commuteGroupId": null,
    "membership": null,
    "commuteId": "01m6phcb8x35sg33w0m2754hcg",
    "passenger": {
      "publicFirstName": "Salma",
      "trustLevel": 2,
      "verifiedLevels": [],
      "sameOrganisation": false,
      "rating": null,
      "ratingCount": 0,
      "completedTrips": 0,
      "onTimeRate": null
    },
    "replacementOffer": {
      "offerId": "01M6PJAS9QEP0BSC5YVFCBP1T6",
      "seatRequestId": "01m6pjarahr6yw16gwaccfcnbz",
      "passengerFirstName": "Aya",
      "status": "OPEN",
      "offeredAt": "2026-11-05T19:17:51+00:00",
      "closedAt": null
    },
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
    "createdAt": "2026-11-05T19:17:50+00:00",
    "driverDelay": null,
    "pickupEta": null,
    "pickupEtaLocal": null,
    "trip": {
      "id": "01m6phcbh2jbdnt0hw23nsqw87",
      "tripDate": "2026-11-06",
      "departureAt": "2026-11-06T00:17:00+00:00",
      "departureLocal": "2026-11-06 02:17:00",
      "promisedArrivalAt": "2026-11-06T02:17:00+00:00",
      "promisedArrivalLocal": "2026-11-06 04:17:00",
      "status": "SCHEDULED",
      "seatsTotal": 2,
      "seatsTaken": 2,
      "seatsAvailable": 0,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "driverSharePiastres": 11551
    }
  }
}
```

## Return and Duplicate

### From a commute with passengers

- **State:** `DC-PASSENGERS`
- **Phone:** `+201055557501`
- **Captured:** 2026-11-05 19:17:52 UTC, locally

**POST /commutes/{commute}/return** → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pjatj3xkgdh6wy5ypfm8ps",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_home",
    "vehicleId": "01m6phbqtj1m4nc2yqjkxq27pe",
    "seatsTotal": 3,
    "seatPricePiastres": null,
    "pricePerSeatPiastres": null,
    "driverSharePiastres": null,
    "currency": null,
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "any_verified",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "createdAt": "2026-11-05T19:17:52+00:00",
    "updatedAt": "2026-11-05T19:17:52+00:00",
    "formStep": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6pjatj4s3bg0ch93a1jvgtv",
        "type": "origin",
        "lat": 30.0724,
        "lng": 31.0116,
        "address": "القرية الذكية، مبنى B6",
        "placeId": "01m6hf9f3ckmpvgs75rye4hwee",
        "sequence": 0
      },
      {
        "id": "01m6pjatj5xywy3p6cebj3z7pa",
        "type": "destination",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

**POST /commutes/{commute}/duplicate** → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pjatk3yej7jcw4nk031y78",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6phbqtj1m4nc2yqjkxq27pe",
    "seatsTotal": 3,
    "seatPricePiastres": null,
    "pricePerSeatPiastres": null,
    "driverSharePiastres": null,
    "currency": null,
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "any_verified",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "createdAt": "2026-11-05T19:17:52+00:00",
    "updatedAt": "2026-11-05T19:17:52+00:00",
    "formStep": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6pjatk4hv1mhmf5embsrv61",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6pjatk56ac39b1ep7tcrvx2",
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
      "startDate": "2026-11-06",
      "endDate": "2027-03-05",
      "generatedUntil": null
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

### Duplicate with its whole window passed

- **State:** `CC-WINDOW-PASSED`
- **Phone:** `+201055557412`
- **Captured:** 2026-11-05 19:17:54 UTC, locally

**POST /commutes/{commute}/duplicate** → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6pjavsk3vzjjy289kvzqnjd",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6my7t5eht89e44rhbxaamf7",
    "seatsTotal": 3,
    "seatPricePiastres": null,
    "pricePerSeatPiastres": null,
    "driverSharePiastres": null,
    "currency": null,
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "any_verified",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "createdAt": "2026-11-05T19:17:54+00:00",
    "updatedAt": "2026-11-05T19:17:54+00:00",
    "formStep": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6pjavsndxwhpknse2eqeecr",
        "type": "origin",
        "lat": 30.0602,
        "lng": 31.4915,
        "address": "الرحاب، بوابة ٢",
        "placeId": "01m6hf9f36ap97wmdqqgvp8t5f",
        "sequence": 0
      },
      {
        "id": "01m6pjavtbas86pf0hrn0tz5v7",
        "type": "destination",
        "lat": 30.0724,
        "lng": 31.0116,
        "address": "القرية الذكية، مبنى B6",
        "placeId": "01m6hf9f3ckmpvgs75rye4hwee",
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

