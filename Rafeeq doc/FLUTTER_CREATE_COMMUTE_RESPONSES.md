# Create Commute: real responses

Captured from the running API on the `fix/create-commute-open-items` branch. Reset any state with `php artisan demo:create-commute-states --state=CC-READY`. The sign-in code is the server's `RAFEEQ_DEV_OTP_CODE`.

The local server uses the straight-line route engine, so `polyline`, distances and minutes are estimates here. The shapes are the same on the live server.

`COMMUTE_ROUTE_UNAVAILABLE` can't be produced by the straight-line engine, so it isn't captured here. It answers `503` with no `error.fields`, and a feature test covers it.

| State | What it shows | Phone |
|---|---|---|
| `CC-READY` | Limits and defaults | `+201055557401` |
| `CC-CAR-NOT-REVIEWED` | Not reviewed, approvedSeats null | `+201055557402` |
| `CC-CAR-PENDING` | Waiting for review | `+201055557404` |
| `CC-CAR-REJECTED` | Rejected | `+201055557403` |
| `CC-NO-CAR` | No car | `+201055557405` |
| `CC-READY` | Search, reverse lookup and no results | `+201055557401` |
| `CC-READY` | Nothing sent: every value defaults | `+201055557401` |
| `CC-READY` | Seats above approvedSeats | `+201055557401` |
| `CC-READY` | Another driver's car | `+201055557401` |
| `CC-READY` | Start and end only | `+201055557401` |
| `CC-READY` | With stops, one point used for both | `+201055557401` |
| `CC-READY` | Start equals end | `+201055557401` |
| `CC-READY` | A stop too far from the road | `+201055557401` |
| `CC-READY` | Too many stops | `+201055557401` |
| `CC-READY` | Once the route is saved | `+201055557401` |
| `CC-READY` | Before the route is saved | `+201055557401` |
| `CC-CAR-NOT-REVIEWED` | Car not reviewed | `+201055557402` |
| `CC-READY` | Arrival only, dates default | `+201055557401` |
| `CC-READY` | Arrival and seats, each weekday its own | `+201055557401` |
| `CC-READY` | Seats above approvedSeats on a day | `+201055557401` |
| `CC-READY` | No day chosen | `+201055557401` |
| `CC-READY` | Rules on a draft, with Women-only | `+201055557401` |
| `CC-READY` | Unknown key | `+201055557401` |
| `CC-READY` | Missing details | `+201055557401` |
| `CC-READY` | Success, with upcomingTrips | `+201055557401` |
| `CC-READY` | Published twice | `+201055557401` |
| `CC-CAR-PENDING` | Car waiting for review | `+201055557404` |
| `CC-CAR-REJECTED` | Car rejected | `+201055557403` |
| `CC-OVERDUE` | Commission unpaid after Friday | `+201055557407` |
| `CC-SUSPENDED` | Driver suspended | `+201055557408` |
| `CC-LICENCE-EXPIRED` | Licence expired | `+201055557409` |
| `CC-SUSPENDED` | A new draft is refused | `+201055557408` |
| `CC-READY` | From a published commute | `+201055557401` |
| `CC-READY` | Duplicate with its whole window passed: schedule is null | `+201055557401` |
| `CC-READY` | Several drafts, resume and discard | `+201055557401` |
| `CC-UNVERIFIED` | Each call in the form | `+201055557406` |
| `CC-NO-CAR` | Verified identity, no car: publish names the missing car | `+201055557405` |

## Form options

### Limits and defaults

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:46 UTC

**GET /commutes/options** → `200`

```json
{
  "success": true,
  "data": {
    "maxPickupStops": 3,
    "maxDropoffStops": 3,
    "maxDetourMinutes": 30,
    "matchRadiusMeters": 1500,
    "detourTiers": {
      "onWay": {
        "pointMeters": 1500,
        "requestMinutes": 10,
        "tripMinutes": 20
      },
      "far": {
        "pointMeters": 3000,
        "requestMinutes": 15,
        "tripMinutes": 30
      }
    },
    "maxScheduleMonths": 12,
    "preferences": [
      "nonsmoking",
      "quiet",
      "ac",
      "nofood",
      "music"
    ],
    "canMarkWomenOnly": true,
    "defaults": {
      "commuteType": "recurring",
      "direction": "to_work",
      "audience": "any_verified",
      "allowsCustomPickup": true,
      "startDate": "2026-11-06",
      "endDate": "2027-11-06"
    }
  }
}
```

## Open: the driver's car

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6mga8j7zs96783vn0wwx4q0",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7401",
      "seats": 4,
      "approvedSeats": 3,
      "tier": "standard",
      "hasAc": true,
      "transmission": "manual",
      "fuelType": "petrol",
      "isActive": true,
      "status": "APPROVED",
      "rejectionReason": null,
      "documents": [
        {
          "id": "01m6mga956hzztrbvcfdwk2vgc",
          "type": "registration",
          "status": "APPROVED",
          "expiresAt": "2027-11-05"
        }
      ]
    }
  ]
}
```

### Not reviewed, approvedSeats null

- **State:** `CC-CAR-NOT-REVIEWED`
- **Phone:** `+201055557402`
- **Captured:** 2026-11-05 02:55:47 UTC

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6mga98nqh05f1dfyhwjhpxp",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7402",
      "seats": 4,
      "approvedSeats": null,
      "tier": "standard",
      "hasAc": true,
      "transmission": "manual",
      "fuelType": "petrol",
      "isActive": true,
      "status": "APPROVED",
      "rejectionReason": null,
      "documents": [
        {
          "id": "01m6mga9dmw6yngsd86nnx47en",
          "type": "registration",
          "status": "APPROVED",
          "expiresAt": "2027-11-05"
        }
      ]
    }
  ]
}
```

### Waiting for review

- **State:** `CC-CAR-PENDING`
- **Phone:** `+201055557404`
- **Captured:** 2026-11-05 02:55:48 UTC

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6mga9pnqqk4d9bcr6c0d82q",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7404",
      "seats": 4,
      "approvedSeats": null,
      "tier": "standard",
      "hasAc": true,
      "transmission": "automatic",
      "fuelType": "petrol",
      "isActive": true,
      "status": "PENDING",
      "rejectionReason": null,
      "documents": [
        {
          "id": "01m6mga9vjnm6s7jr87x504rac",
          "type": "registration",
          "status": "APPROVED",
          "expiresAt": "2027-11-05"
        }
      ]
    }
  ]
}
```

### Rejected

- **State:** `CC-CAR-REJECTED`
- **Phone:** `+201055557403`
- **Captured:** 2026-11-05 02:55:49 UTC

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6mga9fqg8qjqc66q2qg0dt7",
      "make": "Toyota",
      "model": "Corolla",
      "year": 2020,
      "colour": "White",
      "plateNumber": "DH 7403",
      "seats": 4,
      "approvedSeats": 3,
      "tier": "standard",
      "hasAc": true,
      "transmission": "automatic",
      "fuelType": "petrol",
      "isActive": true,
      "status": "REJECTED",
      "rejectionReason": "The registration photo is unreadable.",
      "documents": [
        {
          "id": "01m6mga9mphnpq1wdsq0rqhbbs",
          "type": "registration",
          "status": "APPROVED",
          "expiresAt": "2027-11-05"
        }
      ]
    }
  ]
}
```

### No car

- **State:** `CC-NO-CAR`
- **Phone:** `+201055557405`
- **Captured:** 2026-11-05 02:55:51 UTC

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": []
}
```

## Places

### Search, reverse lookup and no results

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**GET /places?q=Rehab** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6hf9f36ap97wmdqqgvp8t5f",
      "name": "Rehab Gate 2",
      "nameAr": "الرحاب بوابة ٢",
      "type": "compound_gate",
      "lat": 30.0602,
      "lng": 31.4915,
      "city": "القاهرة",
      "district": "القاهرة الجديدة"
    }
  ]
}
```

**GET /places?lat=30.0594&lng=31.4913** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6hf9f36ap97wmdqqgvp8t5f",
      "name": "Rehab Gate 2",
      "nameAr": "الرحاب بوابة ٢",
      "type": "compound_gate",
      "lat": 30.0602,
      "lng": 31.4915,
      "city": "القاهرة",
      "district": "القاهرة الجديدة",
      "distanceMetres": 91
    }
  ]
}
```

**GET /places?q=zzzzzzzzz** → `200`

```json
{
  "success": true,
  "data": []
}
```

## Create

### Nothing sent: every value defaults

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**POST /commutes** with `{}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6mga8j7zs96783vn0wwx4q0",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "origin",
      "destination",
      "schedule"
    ],
    "locations": [],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

**GET /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "origin",
      "destination",
      "schedule"
    ],
    "locations": [],
    "schedule": null,
    "weekdays": [],
    "rules": {},
    "upcomingTrips": []
  }
}
```

### Seats above approvedSeats

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**POST /commutes** with `{"seatsTotal": 6}` → `422`

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

### Another driver's car

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**POST /commutes** with `{"vehicleId": "01JABCDEFGHJKMNPQRSTVWXYZ0"}` → `404`

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

## Route

### Start and end only

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**PUT /commutes/{commute}/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0714, "lng": 30.9716, "address": "Smart Village B6"}}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6mt4qabzd3vn8y1504beehb",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qacas0ns09r137a9srw",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

**GET /commutes/{commute}/route-preview** → `200`

```json
{
  "success": true,
  "data": {
    "polyline": "g~mvDsse_E_jAbodB",
    "distanceMeters": 50031,
    "durationSeconds": 6433
  }
}
```

**GET /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6mt4qabzd3vn8y1504beehb",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qacas0ns09r137a9srw",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {},
    "upcomingTrips": []
  }
}
```

### With stops, one point used for both

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**PUT /commutes/{commute}/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0714, "lng": 30.9716, "address": "Smart Village B6"}, "pickups": [{"lat": 30.065, "lng": 31.25, "address": "Ring Road service stop"}], "dropoffs": [{"lat": 30.065, "lng": 31.25, "address": "Ring Road service stop"}]}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6mt4qeqppbz533myxv59575",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qeqppbz533myxv59576",
        "type": "pickup",
        "lat": 30.065,
        "lng": 31.25,
        "address": "Ring Road service stop",
        "placeId": null,
        "sequence": 1
      },
      {
        "id": "01m6mt4qern9ka532xf7vwhxqz",
        "type": "dropoff",
        "lat": 30.065,
        "lng": 31.25,
        "address": "Ring Road service stop",
        "placeId": null,
        "sequence": 2
      },
      {
        "id": "01m6mt4qest644nsnw1b4bmzs7",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

### Start equals end

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**PUT /commutes/{commute}/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_ROUTE_INVALID",
    "message": "Please check the route: the origin and destination must differ, and pickup points must be along the way.",
    "fields": {
      "route": [
        "The starting point and the destination need to be different places."
      ]
    }
  }
}
```

### A stop too far from the road

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**PUT /commutes/{commute}/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0714, "lng": 30.9716, "address": "Smart Village B6"}, "pickups": [{"lat": 31.2, "lng": 29.9, "address": "Alexandria"}]}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_ROUTE_INVALID",
    "message": "Please check the route: the origin and destination must differ, and pickup points must be along the way.",
    "fields": {
      "pickups.0": [
        "That pickup point is more than 30 minutes out of your way. Move it closer to your route, or raise the detour you are willing to make."
      ]
    }
  }
}
```

### Too many stops

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**PUT /commutes/{commute}/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0714, "lng": 30.9716, "address": "Smart Village B6"}, "pickups": [{"lat": 30.065, "lng": 31.25, "address": "Ring Road service stop"}, {"lat": 30.065, "lng": 31.25, "address": "Ring Road service stop"}, {"lat": 30.065, "lng": 31.25, "address": "Ring Road service stop"}, {"lat": 30.065, "lng": 31.25, "address": "Ring Road service stop"}]}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "pickups": [
        "The pickups field must not have more than 3 items."
      ]
    }
  }
}
```

**PUT /commutes/{commute}/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0714, "lng": 30.9716, "address": "Smart Village B6"}}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6mt4qjwpkakbep5fpswnn83",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qjxw1rwtchdj5m9n0xw",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

## Price

### Once the route is saved

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**GET /commutes/{commute}/price** → `200`

```json
{
  "success": true,
  "data": {
    "seatPricePiastres": 14000,
    "driverSharePiastres": 12508,
    "distanceKm": 50,
    "tier": "standard",
    "approvedSeats": 3
  }
}
```

### Before the route is saved

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:52 UTC

**GET /commutes/{other}/route-preview** → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_INCOMPLETE",
    "message": "Some required details are still missing.",
    "fields": {
      "missing": [
        "route"
      ]
    }
  }
}
```

**GET /commutes/{other}/price** → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_INCOMPLETE",
    "message": "Some required details are still missing.",
    "fields": {
      "missing": [
        "route"
      ]
    }
  }
}
```

### Car not reviewed

- **State:** `CC-CAR-NOT-REVIEWED`
- **Phone:** `+201055557402`
- **Captured:** 2026-11-05 02:55:54 UTC

**GET /commutes/{commute}/price** → `409`

```json
{
  "success": false,
  "error": {
    "code": "VEHICLE_SEATS_NOT_APPROVED",
    "message": "Your car has not been reviewed yet, so it has no approved seats. You can publish once the review is done.",
    "fields": {
      "vehicleId": [
        "01m6mga98nqh05f1dfyhwjhpxp"
      ]
    }
  }
}
```

## Schedule

### Arrival only, dates default

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**PUT /commutes/{commute}/schedule** with `{"daysMask": 62, "promisedArrivalTime": "09:00:00"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6mt4qjwpkakbep5fpswnn83",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qjxw1rwtchdj5m9n0xw",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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

### Arrival and seats, each weekday its own

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**PUT /commutes/{commute}/schedule** with `{"daysMask": 10, "perDay": {"2": {"promisedArrivalTime": "09:00:00", "seatsTotal": 2}, "8": {"promisedArrivalTime": "09:30:00"}}}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6mt4qjwpkakbep5fpswnn83",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qjxw1rwtchdj5m9n0xw",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": {
      "daysMask": 10,
      "departureTime": "07:12:00",
      "timezone": "Africa/Cairo",
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
      "generatedUntil": null
    },
    "weekdays": [
      {
        "weekdayBit": 2,
        "promisedArrivalTime": "09:00:00",
        "departureTime": "07:12:00",
        "departureIsComputed": true,
        "seatsTotal": 2
      },
      {
        "weekdayBit": 8,
        "promisedArrivalTime": "09:30:00",
        "departureTime": "07:42:00",
        "departureIsComputed": true,
        "seatsTotal": null
      }
    ],
    "rules": {}
  }
}
```

### Seats above approvedSeats on a day

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**PUT /commutes/{commute}/schedule** with `{"daysMask": 2, "perDay": {"2": {"promisedArrivalTime": "09:00:00", "seatsTotal": 6}}}` → `422`

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

### No day chosen

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**PUT /commutes/{commute}/schedule** with `{"promisedArrivalTime": "09:00:00"}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_INCOMPLETE",
    "message": "Some required details are still missing.",
    "fields": {
      "daysMask": [
        "Choose at least one day of the week."
      ]
    }
  }
}
```

**PUT /commutes/{commute}/schedule** with `{"daysMask": 62, "promisedArrivalTime": "09:00:00"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6mt4qjwpkakbep5fpswnn83",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qjxw1rwtchdj5m9n0xw",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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

## Preferences

### Rules on a draft, with Women-only

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**PATCH /commutes/{commute}** with `{"rules": {"nonsmoking": true, "music": true, "ac": true}, "audience": "women_only"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
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
    "audience": "women_only",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6mt4qjwpkakbep5fpswnn83",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qjxw1rwtchdj5m9n0xw",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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
    "rules": {
      "ac": true,
      "music": true,
      "nonsmoking": true
    }
  }
}
```

### Unknown key

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**PATCH /commutes/{commute}** with `{"rules": {"smoking_room": true}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": {
      "rules.smoking_room": [
        "The selected rule is invalid."
      ]
    }
  }
}
```

## Publish

### Missing details

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**POST /commutes/{other}/publish** → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_INCOMPLETE",
    "message": "Some required details are still missing.",
    "fields": {
      "missing": [
        "origin",
        "destination",
        "schedule"
      ]
    }
  }
}
```

### Success, with upcomingTrips

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**POST /commutes/{commute}/publish** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt4q2szkmfjc0kaz6njd3c",
    "status": "PUBLISHED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6mga8j7zs96783vn0wwx4q0",
    "seatsTotal": 3,
    "seatPricePiastres": 14000,
    "pricePerSeatPiastres": 14000,
    "driverSharePiastres": 12508,
    "currency": "EGP",
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "women_only",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": "g~mvDsse_E_jAbodB",
    "routeDistanceMeters": 50031,
    "routeDurationSeconds": 6433,
    "corridorId": null,
    "publishedAt": "2026-11-05T02:55:54+00:00",
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6mt4qjwpkakbep5fpswnn83",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt4qjxw1rwtchdj5m9n0xw",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
      "generatedUntil": "2026-12-05"
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
    "rules": {
      "ac": true,
      "music": true,
      "nonsmoking": true
    },
    "upcomingTrips": [
      {
        "id": "01m6mt4s7ghcakcr4eng5ptqh4",
        "tripDate": "2026-11-08",
        "departureAt": "2026-11-08T05:12:00+00:00",
        "departureLocal": "2026-11-08 07:12:00",
        "promisedArrivalAt": "2026-11-08T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-08 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s7k0ma67cmnw336g4xn",
        "tripDate": "2026-11-09",
        "departureAt": "2026-11-09T05:12:00+00:00",
        "departureLocal": "2026-11-09 07:12:00",
        "promisedArrivalAt": "2026-11-09T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-09 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s7nfcnrm11dvnhgh1bz",
        "tripDate": "2026-11-10",
        "departureAt": "2026-11-10T05:12:00+00:00",
        "departureLocal": "2026-11-10 07:12:00",
        "promisedArrivalAt": "2026-11-10T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-10 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s7qs61yb1w9c3x891ez",
        "tripDate": "2026-11-11",
        "departureAt": "2026-11-11T05:12:00+00:00",
        "departureLocal": "2026-11-11 07:12:00",
        "promisedArrivalAt": "2026-11-11T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-11 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s7sztfa3eqsar550raf",
        "tripDate": "2026-11-12",
        "departureAt": "2026-11-12T05:12:00+00:00",
        "departureLocal": "2026-11-12 07:12:00",
        "promisedArrivalAt": "2026-11-12T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-12 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s7vx4qw6dg233n8vatc",
        "tripDate": "2026-11-15",
        "departureAt": "2026-11-15T05:12:00+00:00",
        "departureLocal": "2026-11-15 07:12:00",
        "promisedArrivalAt": "2026-11-15T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-15 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s7xg97z442n319b3tmj",
        "tripDate": "2026-11-16",
        "departureAt": "2026-11-16T05:12:00+00:00",
        "departureLocal": "2026-11-16 07:12:00",
        "promisedArrivalAt": "2026-11-16T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-16 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s7z87n9cgdexwzhpq5b",
        "tripDate": "2026-11-17",
        "departureAt": "2026-11-17T05:12:00+00:00",
        "departureLocal": "2026-11-17 07:12:00",
        "promisedArrivalAt": "2026-11-17T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-17 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s81tqzb83q0wvx8bp3w",
        "tripDate": "2026-11-18",
        "departureAt": "2026-11-18T05:12:00+00:00",
        "departureLocal": "2026-11-18 07:12:00",
        "promisedArrivalAt": "2026-11-18T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-18 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s83qeez9r8k3b4z8ts9",
        "tripDate": "2026-11-19",
        "departureAt": "2026-11-19T05:12:00+00:00",
        "departureLocal": "2026-11-19 07:12:00",
        "promisedArrivalAt": "2026-11-19T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-19 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s861ej74m1730w8eyg4",
        "tripDate": "2026-11-22",
        "departureAt": "2026-11-22T05:12:00+00:00",
        "departureLocal": "2026-11-22 07:12:00",
        "promisedArrivalAt": "2026-11-22T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-22 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s889m01ma1k9kbbm77e",
        "tripDate": "2026-11-23",
        "departureAt": "2026-11-23T05:12:00+00:00",
        "departureLocal": "2026-11-23 07:12:00",
        "promisedArrivalAt": "2026-11-23T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-23 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8ak67xfqng6r8j0j9c",
        "tripDate": "2026-11-24",
        "departureAt": "2026-11-24T05:12:00+00:00",
        "departureLocal": "2026-11-24 07:12:00",
        "promisedArrivalAt": "2026-11-24T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-24 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8cdk4et4pvcf45brvn",
        "tripDate": "2026-11-25",
        "departureAt": "2026-11-25T05:12:00+00:00",
        "departureLocal": "2026-11-25 07:12:00",
        "promisedArrivalAt": "2026-11-25T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-25 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8ec5thxk5c7epdpwsa",
        "tripDate": "2026-11-26",
        "departureAt": "2026-11-26T05:12:00+00:00",
        "departureLocal": "2026-11-26 07:12:00",
        "promisedArrivalAt": "2026-11-26T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-26 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8g07f3j7sjyg319wnh",
        "tripDate": "2026-11-29",
        "departureAt": "2026-11-29T05:12:00+00:00",
        "departureLocal": "2026-11-29 07:12:00",
        "promisedArrivalAt": "2026-11-29T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-29 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8j9qm2qep4ypz5myy5",
        "tripDate": "2026-11-30",
        "departureAt": "2026-11-30T05:12:00+00:00",
        "departureLocal": "2026-11-30 07:12:00",
        "promisedArrivalAt": "2026-11-30T07:00:00+00:00",
        "promisedArrivalLocal": "2026-11-30 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8m66fnf8mx78y0y7e8",
        "tripDate": "2026-12-01",
        "departureAt": "2026-12-01T05:12:00+00:00",
        "departureLocal": "2026-12-01 07:12:00",
        "promisedArrivalAt": "2026-12-01T07:00:00+00:00",
        "promisedArrivalLocal": "2026-12-01 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8p4exm1k88zc5j2nq0",
        "tripDate": "2026-12-02",
        "departureAt": "2026-12-02T05:12:00+00:00",
        "departureLocal": "2026-12-02 07:12:00",
        "promisedArrivalAt": "2026-12-02T07:00:00+00:00",
        "promisedArrivalLocal": "2026-12-02 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      },
      {
        "id": "01m6mt4s8rntjqbcem9g40709g",
        "tripDate": "2026-12-03",
        "departureAt": "2026-12-03T05:12:00+00:00",
        "departureLocal": "2026-12-03 07:12:00",
        "promisedArrivalAt": "2026-12-03T07:00:00+00:00",
        "promisedArrivalLocal": "2026-12-03 09:00:00",
        "status": "SCHEDULED",
        "seatsTotal": 3,
        "seatsTaken": 0,
        "seatsAvailable": 3,
        "seatPricePiastres": 14000,
        "pricePerSeatPiastres": 14000,
        "driverSharePiastres": 12508
      }
    ]
  }
}
```

### Published twice

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:55:54 UTC

**POST /commutes/{commute}/publish** → `409`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_INVALID_TRANSITION",
    "message": "That change is not possible from the commute’s current state.",
    "fields": {
      "status": [
        "PUBLISHED"
      ]
    }
  }
}
```

### Car not reviewed

- **State:** `CC-CAR-NOT-REVIEWED`
- **Phone:** `+201055557402`
- **Captured:** 2026-11-05 02:55:55 UTC

**POST /commutes/{commute}/publish** → `409`

```json
{
  "success": false,
  "error": {
    "code": "VEHICLE_SEATS_NOT_APPROVED",
    "message": "Your car has not been reviewed yet, so it has no approved seats. You can publish once the review is done.",
    "fields": {
      "vehicleId": [
        "01m6mga98nqh05f1dfyhwjhpxp"
      ]
    }
  }
}
```

### Car waiting for review

- **State:** `CC-CAR-PENDING`
- **Phone:** `+201055557404`
- **Captured:** 2026-11-05 02:55:57 UTC

**POST /commutes/{commute}/publish** → `422`

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

### Car rejected

- **State:** `CC-CAR-REJECTED`
- **Phone:** `+201055557403`
- **Captured:** 2026-11-05 02:55:58 UTC

**POST /commutes/{commute}/publish** → `422`

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

### Commission unpaid after Friday

- **State:** `CC-OVERDUE`
- **Phone:** `+201055557407`
- **Captured:** 2026-11-05 02:55:59 UTC

**POST /commutes/{commute}/publish** → `403`

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
      ]
    }
  }
}
```

### Driver suspended

- **State:** `CC-SUSPENDED`
- **Phone:** `+201055557408`
- **Captured:** 2026-11-05 02:56:00 UTC

**POST /commutes/{commute}/publish** → `403`

```json
{
  "success": false,
  "error": {
    "code": "DRIVER_NOT_ELIGIBLE",
    "message": "You cannot apply to drive yet.",
    "fields": {
      "driver": [
        "SUSPENDED"
      ]
    }
  }
}
```

### Licence expired

- **State:** `CC-LICENCE-EXPIRED`
- **Phone:** `+201055557409`
- **Captured:** 2026-11-05 02:56:02 UTC

**POST /commutes/{commute}/publish** → `422`

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

## Suspended driver

### A new draft is refused

- **State:** `CC-SUSPENDED`
- **Phone:** `+201055557408`
- **Captured:** 2026-11-05 02:56:03 UTC

**POST /commutes** with `{}` → `403`

```json
{
  "success": false,
  "error": {
    "code": "DRIVER_NOT_ELIGIBLE",
    "message": "You cannot apply to drive yet.",
    "fields": {
      "driver": [
        "SUSPENDED"
      ]
    }
  }
}
```

## Duplicate and return

### From a published commute

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:56:04 UTC

**POST /commutes/{commute}/duplicate** → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt538g5k513fbf77tfjyvh",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6mga8j7zs96783vn0wwx4q0",
    "seatsTotal": 3,
    "seatPricePiastres": null,
    "pricePerSeatPiastres": null,
    "driverSharePiastres": null,
    "currency": null,
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "women_only",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6mt538j0fdmbfrarht1h2w0",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt538k0rw5th4zh0jx5hyh",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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
    "rules": {
      "ac": true,
      "music": true,
      "nonsmoking": true
    }
  }
}
```

**POST /commutes/{commute}/return** → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt539v24c60fjn8py32frx",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_home",
    "vehicleId": "01m6mga8j7zs96783vn0wwx4q0",
    "seatsTotal": 3,
    "seatPricePiastres": null,
    "pricePerSeatPiastres": null,
    "driverSharePiastres": null,
    "currency": null,
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "women_only",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6mt539x153xvsb60kn4whgv",
        "type": "origin",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt539yxtjqvcm5gv3zmeft",
        "type": "destination",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {
      "ac": true,
      "music": true,
      "nonsmoking": true
    }
  }
}
```

### Duplicate with its whole window passed: schedule is null

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:56:04 UTC

**POST /commutes/{commute}/duplicate** → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt53nsgh2ferbhy4e41twc",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6mga8j7zs96783vn0wwx4q0",
    "seatsTotal": 3,
    "seatPricePiastres": null,
    "pricePerSeatPiastres": null,
    "driverSharePiastres": null,
    "currency": null,
    "maxDetourMinutes": 30,
    "maxWalkMinutes": 18,
    "audience": "women_only",
    "minTrustLevel": 0,
    "allowsCustomPickup": true,
    "routePolyline": null,
    "routeDistanceMeters": null,
    "routeDurationSeconds": null,
    "corridorId": null,
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule"
    ],
    "locations": [
      {
        "id": "01m6mt53nxp3en0p3asb9j8a5d",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt53nyyxtn9fh6xa06pp5j",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {
      "ac": true,
      "music": true,
      "nonsmoking": true
    }
  }
}
```

## Drafts

### Several drafts, resume and discard

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:56:06 UTC

**GET /commutes?status=draft** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6mt54x8h6gmqafns6bpa5xk",
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
      "publishedAt": null,
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": null,
      "missing": [
        "origin",
        "destination",
        "schedule"
      ],
      "locations": [],
      "schedule": null,
      "weekdays": [],
      "rules": {}
    },
    {
      "id": "01m6mt53yxmk75676xse9vy6hf",
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
      "publishedAt": null,
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": null,
      "missing": [],
      "locations": [
        {
          "id": "01m6mt53z977qa4eyv5mqd7t1g",
          "type": "origin",
          "lat": 30.0594,
          "lng": 31.4913,
          "address": "Rehab Gate 2",
          "placeId": null,
          "sequence": 0
        },
        {
          "id": "01m6mt53za8vwmrw7c8gsa03f2",
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
        "startDate": "2026-11-06",
        "endDate": "2027-11-06",
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
  ]
}
```

**GET /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt53yxmk75676xse9vy6hf",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [],
    "locations": [
      {
        "id": "01m6mt53z977qa4eyv5mqd7t1g",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt53za8vwmrw7c8gsa03f2",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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
    "rules": {},
    "upcomingTrips": []
  }
}
```

**DELETE /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt53yxmk75676xse9vy6hf",
    "status": "ARCHIVED",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": "2026-11-05T02:56:06+00:00",
    "missing": [],
    "locations": [
      {
        "id": "01m6mt53z977qa4eyv5mqd7t1g",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt53za8vwmrw7c8gsa03f2",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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

**GET /commutes?status=draft** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6mt54x8h6gmqafns6bpa5xk",
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
      "publishedAt": null,
      "pausedAt": null,
      "pausedReason": null,
      "archivedAt": null,
      "missing": [
        "origin",
        "destination",
        "schedule"
      ],
      "locations": [],
      "schedule": null,
      "weekdays": [],
      "rules": {}
    }
  ]
}
```

## Driver who has not started verification

### Each call in the form

- **State:** `CC-UNVERIFIED`
- **Phone:** `+201055557406`
- **Captured:** 2026-11-05 02:56:07 UTC

**GET /commutes/options** → `200`

```json
{
  "success": true,
  "data": {
    "maxPickupStops": 3,
    "maxDropoffStops": 3,
    "maxDetourMinutes": 30,
    "matchRadiusMeters": 1500,
    "detourTiers": {
      "onWay": {
        "pointMeters": 1500,
        "requestMinutes": 10,
        "tripMinutes": 20
      },
      "far": {
        "pointMeters": 3000,
        "requestMinutes": 15,
        "tripMinutes": 30
      }
    },
    "maxScheduleMonths": 12,
    "preferences": [
      "nonsmoking",
      "quiet",
      "ac",
      "nofood",
      "music"
    ],
    "canMarkWomenOnly": true,
    "defaults": {
      "commuteType": "recurring",
      "direction": "to_work",
      "audience": "any_verified",
      "allowsCustomPickup": true,
      "startDate": "2026-11-06",
      "endDate": "2027-11-06"
    }
  }
}
```

**GET /driver/vehicles** → `200`

```json
{
  "success": true,
  "data": []
}
```

**GET /commutes** → `200`

```json
{
  "success": true,
  "data": []
}
```

**POST /commutes** with `{}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt5687kvejm54xqkvs9wp2",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": null,
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "origin",
      "destination",
      "schedule",
      "vehicle"
    ],
    "locations": [],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

**PUT /commutes/{commute}/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0714, "lng": 30.9716, "address": "Smart Village B6"}}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt5687kvejm54xqkvs9wp2",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": null,
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "schedule",
      "vehicle"
    ],
    "locations": [
      {
        "id": "01m6mt569676hk5w1av4psdr84",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt5697e73bh1a6cavhsa63",
        "type": "destination",
        "lat": 30.0714,
        "lng": 30.9716,
        "address": "Smart Village B6",
        "placeId": null,
        "sequence": 999
      }
    ],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

**GET /commutes/{commute}/route-preview** → `200`

```json
{
  "success": true,
  "data": {
    "polyline": "g~mvDsse_E_jAbodB",
    "distanceMeters": 50031,
    "durationSeconds": 6433
  }
}
```

**GET /commutes/{commute}/price** → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_INCOMPLETE",
    "message": "Some required details are still missing.",
    "fields": {
      "missing": [
        "vehicle"
      ]
    }
  }
}
```

**PUT /commutes/{commute}/schedule** with `{"daysMask": 62, "promisedArrivalTime": "09:00:00"}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt5687kvejm54xqkvs9wp2",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": null,
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "vehicle"
    ],
    "locations": [
      {
        "id": "01m6mt569676hk5w1av4psdr84",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt5697e73bh1a6cavhsa63",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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

**PATCH /commutes/{commute}** with `{"rules": {"nonsmoking": true}}` → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt5687kvejm54xqkvs9wp2",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": null,
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "vehicle"
    ],
    "locations": [
      {
        "id": "01m6mt569676hk5w1av4psdr84",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt5697e73bh1a6cavhsa63",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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
    "rules": {
      "nonsmoking": true
    }
  }
}
```

**GET /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt5687kvejm54xqkvs9wp2",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": null,
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "vehicle"
    ],
    "locations": [
      {
        "id": "01m6mt569676hk5w1av4psdr84",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt5697e73bh1a6cavhsa63",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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
    "rules": {
      "nonsmoking": true
    },
    "upcomingTrips": []
  }
}
```

**POST /commutes/{commute}/publish** → `403`

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

**DELETE /commutes/{commute}** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt5687kvejm54xqkvs9wp2",
    "status": "ARCHIVED",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": null,
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": "2026-11-05T02:56:08+00:00",
    "missing": [
      "vehicle"
    ],
    "locations": [
      {
        "id": "01m6mt569676hk5w1av4psdr84",
        "type": "origin",
        "lat": 30.0594,
        "lng": 31.4913,
        "address": "Rehab Gate 2",
        "placeId": null,
        "sequence": 0
      },
      {
        "id": "01m6mt5697e73bh1a6cavhsa63",
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
      "startDate": "2026-11-06",
      "endDate": "2027-11-06",
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
    "rules": {
      "nonsmoking": true
    }
  }
}
```

### Verified identity, no car: publish names the missing car

- **State:** `CC-NO-CAR`
- **Phone:** `+201055557405`
- **Captured:** 2026-11-05 02:56:09 UTC

**POST /commutes** with `{}` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt57kqy751vy34t1ktb6p5",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": null,
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "origin",
      "destination",
      "schedule",
      "vehicle"
    ],
    "locations": [],
    "schedule": null,
    "weekdays": [],
    "rules": {}
  }
}
```

**POST /commutes/{commute}/publish** → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_INCOMPLETE",
    "message": "Some required details are still missing.",
    "fields": {
      "missing": [
        "vehicle"
      ]
    }
  }
}
```

## Rules from the answers (D-0034)

### Options with the detour tiers

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:56:10 UTC

**GET /commutes/options** → `200`

```json
{
  "success": true,
  "data": {
    "maxPickupStops": 3,
    "maxDropoffStops": 3,
    "maxDetourMinutes": 30,
    "matchRadiusMeters": 1500,
    "detourTiers": {
      "onWay": {
        "pointMeters": 1500,
        "requestMinutes": 10,
        "tripMinutes": 20
      },
      "far": {
        "pointMeters": 3000,
        "requestMinutes": 15,
        "tripMinutes": 30
      }
    },
    "maxScheduleMonths": 12,
    "preferences": [
      "nonsmoking",
      "quiet",
      "ac",
      "nofood",
      "music"
    ],
    "canMarkWomenOnly": true,
    "defaults": {
      "commuteType": "recurring",
      "direction": "to_work",
      "audience": "any_verified",
      "allowsCustomPickup": true,
      "startDate": "2026-11-06",
      "endDate": "2027-11-06"
    }
  }
}
```

### A draft with a car under review

- **State:** `CC-CAR-NOT-REVIEWED`
- **Phone:** `+201055557402`
- **Captured:** 2026-11-05 02:56:11 UTC

**GET /commutes/01m6mt59wdtmsxh0wxf86v72n8** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt59wdtmsxh0wxf86v72n8",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6mga98nqh05f1dfyhwjhpxp",
    "seatsTotal": null,
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "origin",
      "destination",
      "schedule",
      "vehicle_under_review"
    ],
    "locations": [],
    "schedule": null,
    "weekdays": [],
    "rules": {},
    "upcomingTrips": []
  }
}
```

### A draft with a rejected car

- **State:** `CC-CAR-REJECTED`
- **Phone:** `+201055557403`
- **Captured:** 2026-11-05 02:56:12 UTC

**GET /commutes/01m6mt5b6bh2haz5dz1ka9rbh9** → `200`

```json
{
  "success": true,
  "data": {
    "id": "01m6mt5b6bh2haz5dz1ka9rbh9",
    "status": "DRAFT",
    "commuteType": "recurring",
    "direction": "to_work",
    "vehicleId": "01m6mga9fqg8qjqc66q2qg0dt7",
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
    "publishedAt": null,
    "pausedAt": null,
    "pausedReason": null,
    "archivedAt": null,
    "missing": [
      "origin",
      "destination",
      "schedule",
      "vehicle_rejected"
    ],
    "locations": [],
    "schedule": null,
    "weekdays": [],
    "rules": {},
    "upcomingTrips": []
  }
}
```

## The four refusals

### A/C on a car without it

- **State:** `CC-CAR-NO-AC`
- **Phone:** `+201055557410`
- **Captured:** 2026-11-05 02:56:14 UTC

**PATCH /commutes/01m6mt5bx4jwh7h0p0e1qagksa** with `{"rules": {"ac": true}}` → `422`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_AC_NOT_AVAILABLE",
    "message": "Your car is registered without AC.",
    "fields": {
      "rules.ac": [
        "Your car is registered without AC."
      ]
    }
  }
}
```

### Women-only from a driver who is not a woman

- **State:** `CC-MAN-DRIVER`
- **Phone:** `+201055557411`
- **Captured:** 2026-11-05 02:56:16 UTC

**POST /commutes** with `{"audience": "women_only"}` → `422`

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

### Changing the route after publishing

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:56:17 UTC

**PUT /commutes/01m6mga8jeybrb8jxacb2nbvaw/route** with `{"origin": {"lat": 30.0594, "lng": 31.4913, "address": "Rehab Gate 2"}, "destination": {"lat": 30.0714, "lng": 30.9716, "address": "Smart Village B6"}}` → `409`

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

### The road can't be calculated (routing provider down, on a local server)

- **State:** `CC-READY`
- **Phone:** `+201055557401`
- **Captured:** 2026-11-05 02:56:18 UTC

**GET /commutes/{commute}/route-preview** → `503`

```json
{
  "success": false,
  "error": {
    "code": "COMMUTE_ROUTE_UNAVAILABLE",
    "message": "We couldn't calculate the road between your start and end. Try again.",
    "fields": null
  }
}
```

