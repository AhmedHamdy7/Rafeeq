# Commute detail — `GET /v1/commutes/{commute}/public`

Real response captured on 2026-11-03 from a local server running branch `fix/match-gaps` with the demo data, signed in as a woman tester (Accept-Language: ar). `upcomingTrips` is cut to 2 of 20 days and `route.polyline` is shortened.

Wrapped as `{"success": true, "data": …}`. `…At` is ISO-8601 UTC, `…Local` is Cairo's clock. Money in piastres.

## 1. Full response (commute هالة: overlaps her seat on day 1, 2 pickups + 1 drop-off)

```json
{
  "success": true,
  "data": {
    "commuteId": "01m6ffxgcms2j8wtsbhjwv750g",
    "status": "PUBLISHED",
    "commuteType": "recurring",
    "direction": "to_work",
    "audience": "women_only",
    "womenOnly": true,
    "seatsTotal": 3,
    "seatPricePiastres": 17000,
    "pricePerSeatPiastres": 17000,
    "currency": "EGP",
    "allowsCustomPickup": true,
    "maxPointDistanceMetres": 1500,
    "maxDetourMinutes": 10,
    "maxDetourKm": 5,
    "schedule": {
      "daysMask": 62,
      "departureTime": "06:50:00",
      "timezone": "Africa/Cairo",
      "startDate": "2026-11-03",
      "endDate": "2027-03-03"
    },
    "route": {
      "start": {
        "label": "New Cairo",
        "lat": 30.06046405783299,
        "lng": 31.48111300376607
      },
      "end": {
        "label": "6th of October",
        "lat": 30.072135942167005,
        "lng": 31.02198699623393
      },
      "distanceKm": 46.2,
      "durationMinutes": 99,
      "polyline": "{dnvD}sc_E_hAntxA…",
      "isTrimmed": true
    },
    "pickups": [
      {
        "id": "01m6ffxgczk1znpw088ahfn6fh",
        "label": "Rehab Mall 2 gate",
        "lat": 30.059,
        "lng": 31.47,
        "estimatedTime": "06:54:25"
      },
      {
        "id": "01m6ffxgd05xkk2kf3d2myz317",
        "label": "Ring Road, Suez Road exit",
        "lat": 30.065,
        "lng": 31.43,
        "estimatedTime": "07:02:43"
      }
    ],
    "dropoffs": [
      {
        "id": "01m6ffxgd1jzanv2bzsgpbd2jd",
        "label": "Smart Village main gate",
        "lat": 30.071,
        "lng": 31.03,
        "estimatedTime": "08:25:12"
      }
    ],
    "upcomingTrips": [
      {
        "id": "01m6ffxgj9z8fqh5b4kgy2dgsz",
        "tripDate": "2026-11-03",
        "departureAt": "2026-11-03T04:50:00+00:00",
        "departureLocal": "2026-11-03 06:50:00",
        "promisedArrivalAt": null,
        "promisedArrivalLocal": null,
        "seatsAvailable": 3,
        "myStatus": {
          "kind": "none",
          "seatRequestId": null,
          "bookingId": null
        },
        "overlapsBooking": {
          "bookingId": "01m6ffxgsq43qhyzpfyfsyza5y",
          "driverFirstName": "داليا",
          "departureLocal": "2026-11-03 06:40:00",
          "arrivalLocal": null
        },
        "seatPricePiastres": 17000,
        "pointTimes": [
          {
            "pointId": "01m6ffxgczk1znpw088ahfn6fh",
            "estimatedAt": "2026-11-03T04:54:25+00:00",
            "estimatedLocal": "2026-11-03 06:54:25"
          },
          {
            "pointId": "01m6ffxgd05xkk2kf3d2myz317",
            "estimatedAt": "2026-11-03T05:02:43+00:00",
            "estimatedLocal": "2026-11-03 07:02:43"
          },
          {
            "pointId": "01m6ffxgd1jzanv2bzsgpbd2jd",
            "estimatedAt": "2026-11-03T06:25:12+00:00",
            "estimatedLocal": "2026-11-03 08:25:12"
          }
        ]
      },
      {
        "id": "01m6ffxgja9j93yss71nntwc1a",
        "tripDate": "2026-11-04",
        "departureAt": "2026-11-04T04:50:00+00:00",
        "departureLocal": "2026-11-04 06:50:00",
        "promisedArrivalAt": null,
        "promisedArrivalLocal": null,
        "seatsAvailable": 3,
        "myStatus": {
          "kind": "none",
          "seatRequestId": null,
          "bookingId": null
        },
        "overlapsBooking": null,
        "seatPricePiastres": 17000,
        "pointTimes": [
          {
            "pointId": "01m6ffxgczk1znpw088ahfn6fh",
            "estimatedAt": "2026-11-04T04:54:25+00:00",
            "estimatedLocal": "2026-11-04 06:54:25"
          },
          {
            "pointId": "01m6ffxgd05xkk2kf3d2myz317",
            "estimatedAt": "2026-11-04T05:02:43+00:00",
            "estimatedLocal": "2026-11-04 07:02:43"
          },
          {
            "pointId": "01m6ffxgd1jzanv2bzsgpbd2jd",
            "estimatedAt": "2026-11-04T06:25:12+00:00",
            "estimatedLocal": "2026-11-04 08:25:12"
          }
        ]
      }
    ],
    "rules": {
      "ac": true,
      "nonsmoking": true
    },
    "requestEligibility": {
      "canRequest": true,
      "reason": null
    },
    "vehicle": {
      "make": "Hyundai",
      "model": "Tucson",
      "colour": "White",
      "tier": "comfort",
      "hasAc": true
    },
    "driver": {
      "publicFirstName": "هالة",
      "trustLevel": 3,
      "verifiedLevels": [
        "phone",
        "government_id",
        "selfie"
      ],
      "sameOrganisation": false,
      "rating": null,
      "ratingCount": 0,
      "completedTrips": 142,
      "onTimeRate": 94
    }
  }
}
```

## 2. New and changed fields

| Field | Type | Notes |
|---|---|---|
| `requestEligibility` | `{canRequest: bool, reason: string \| null}` | `reason`: `null` · `women_only` · `gender_not_set` · `own_commute` · `already_requested` · `account_restricted`. Disable Request Seat when `canRequest` is `false`. `POST /commutes/{commute}/seat-requests` still makes the same checks. |
| `upcomingTrips[].pointTimes[]` | `{pointId, estimatedAt, estimatedLocal}` | That day's time at each suggested point (`pointId` = `pickups[].id` / `dropoffs[].id`). Use it once she picks a day; `estimatedTime` stays as the usual time. |
| `rules` | object | Always an object; `{}` when the commute has none (also on search, `/matches`, `/home`). |
| `route.start.label` / `route.end.label` | string \| null | Area names in the request's language (`ar`: "القاهرة الجديدة", `en`: "New Cairo"). |
| `vehicle.colour` | string | In the request's language for the common colours ("أحمر" / "Red"); anything else as the driver typed it. |

A man opening a women-only commute (كريم):

```json
{
  "requestEligibility": {
    "canRequest": false,
    "reason": "women_only"
  }
}
```

A commute with no suggested points (منى):

```json
{
  "pickups": [],
  "dropoffs": [],
  "allowsCustomPickup": true,
  "maxPointDistanceMetres": 1500,
  "rules": {}
}
```

## 3. Demo states (after reseeding)

| Commute | State |
|---|---|
| ياسمين (+201055550005) | Open seats, new driver (`completedTrips: 0`), 2 pickups + 1 drop-off, `ac` → F3P-22 |
| سلمى (+201055550003) | Women-only, every day `seatsAvailable: 0`, 1 + 1 points, `quiet` → F3P-21 (waiting list) |
| هالة (+201055550001) | 06:50; the testers' 06:40 seat with داليا is on the same morning, so day 1 has `overlapsBooking` → F3P-23 |
| منى (+201055550002) | No suggested points, `allowsCustomPickup: true` → custom pin |

Test accounts (OTP `123456` with `RAFEEQ_DEV_OTP_CODE`): women testers `+201125847213`, `+201014531739` (each holds a seat on داليا's first morning); man tester `+201055559901`.

Note: the testers' 06:40 seat overlaps day 1 of **every** demo commute (they all run that morning), not only هالة's. Pick day 2 or later to see a day without the note.
