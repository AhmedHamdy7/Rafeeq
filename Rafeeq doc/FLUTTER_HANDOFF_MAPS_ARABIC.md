# Google Maps in Arabic: backend handoff for Flutter

**Date:** 2026-11-14 · **Answers:** `google-maps-arabic-backend.md` (2026-11-14) · **Backend:** local `main`, not merged or deployed yet

Everything below is additive: no field was renamed or removed. All JSON is real, captured from the backend against the seeded Greater Cairo catalogue (`php artisan places:seed-greater-cairo`). IDs are from a test database.

General rules are unchanged: responses are `{"success": true, "data": …}`, send `Accept-Language: ar | en` on every call.

---

## 1. Answers to your five questions

| # | Question | Answer |
|---|---|---|
| 1 | How many places are in the catalogue? | The seed command adds **26**: 15 areas, 7 offices and malls, 4 universities. The live table has not been counted. Search will stay thin until more places are added, so use the zero-result log (section 2.4) to decide what to add. |
| 2 | Does `GET /places?q=` call Google Places? | **No.** It searches our table only. |
| 3 | Does any Google text reach users? | **No.** The backend calls only the Google Routes API and keeps the polyline, distance and duration. Scenario 10 needs nothing. |
| 4 | What does profile `language` default to? | **`ar`**. Sending it after every sign-in is right. |
| 5 | Is route geometry stored permanently? | **Yes**: a commute's `polyline` is stored with the commute, and computed routes are cached for 30 days. This still needs a check against the Maps Platform terms. |

---

## 2. Place search: `GET /api/v1/places` (scenario 2)

No new endpoint and no new query field. Send `q` as the user typed it; do not normalise it in the app.

### 2.1 What the backend now forgives

| Typed | Matches | Why |
|---|---|---|
| `مدينه نصر` | `مدينة نصر` | ة / ه |
| `الاسماعيلية` | `الإسماعيلية` | أ / إ / آ / ا |
| `مصطفي` | `مصطفى` | ى / ي |
| `مَدِينَتِي`, `مديـنتي` | `مدينتي` | tashkeel and tatweel are dropped |
| `بوابة ٥` | `بوابة 5` | Arabic and Persian digits become 0–9 |
| `TAGAMO3`, `tagamo3` | `التجمع الخامس` | alias, any letter case |
| `5th Settlement` | `التجمع الخامس` | alias |
| `سمارت فيليدج` | `القرية الذكية` | alias |

Aliases are stored per place and are not returned. An alias match ranks like a name match (starts with, then contains, then area only).

### 2.2 Two new row fields

| Field | Type | Notes |
|---|---|---|
| `isArea` | bool | `true` for a district or a city. Use it to decide whether an area search is possible (section 3). |
| `radiusMetres` | int or `null` | The area's radius. `null` when `isArea` is `false`. Default 4,000 for a district and 8,000 for a city. |

### 2.3 ⚠️ Two new `type` values

`type` can now be **`district`** or **`city`**, besides `compound_gate` · `street` · `landmark` · `station` · `campus` · `office`. The 15 seeded areas (Nasr City, Rehab, Maadi …) were `landmark` and are now `district` or `city`. If the app maps `type` to an icon, add the two values and keep a fallback for unknown ones.

### 2.4 Responses

**Spelling variant** `مدينه نصر`:

```http
GET /api/v1/places?q=مدينه نصر
Accept-Language: ar
```

```json
{
  "success": true,
  "data": [
    {
      "id": "01m7bv0srmycn4eh9arfhtacqb",
      "name": "Nasr City",
      "nameAr": "مدينة نصر",
      "type": "district",
      "lat": 30.0566,
      "lng": 31.3301,
      "isArea": true,
      "radiusMetres": 4000,
      "city": "القاهرة",
      "district": "مدينة نصر"
    }
  ]
}
```

**Franco alias**, with the user's location (adds `distanceMetres`):

```http
GET /api/v1/places?q=tagamo3&lat=30.0626&lng=31.4913
Accept-Language: ar
```

```json
{
  "success": true,
  "data": [
    {
      "id": "01m7bv0srex12nscdj04zzwcaf",
      "name": "Fifth Settlement",
      "nameAr": "التجمع الخامس",
      "type": "district",
      "lat": 30.0074,
      "lng": 31.4913,
      "isArea": true,
      "radiusMetres": 4000,
      "city": "القاهرة",
      "district": "التجمع الخامس",
      "distanceMetres": 6138
    }
  ]
}
```

**Arabic digits** `بوينت ٩٠`:

```json
{
  "success": true,
  "data": [
    {
      "id": "01m7bv0ss6nbng0p4zx1e7k8fj",
      "name": "Point 90",
      "nameAr": "بوينت 90",
      "type": "office",
      "lat": 30.0207,
      "lng": 31.497,
      "isArea": false,
      "radiusMetres": null,
      "city": "القاهرة",
      "district": "التجمع الخامس"
    }
  ]
}
```

**Arabic alias** `سمارت فيليدج`:

```json
{
  "success": true,
  "data": [
    {
      "id": "01m7bv0ss295gs6a5p6dcv1w5z",
      "name": "Smart Village",
      "nameAr": "القرية الذكية",
      "type": "office",
      "lat": 30.071,
      "lng": 31.017,
      "isArea": false,
      "radiusMetres": null,
      "city": "الجيزة",
      "district": "6 أكتوبر"
    }
  ]
}
```

**Nothing found** `مدينة بدر`: still `200` with an empty list.

```json
{
  "success": true,
  "data": []
}
```

The backend logs every such search (the text, the time, the user and the location rounded to about 1 km) so missing places and aliases can be added. The app does nothing for this.

---

## 3. Discover: point first, then the area (scenario 3)

### 3.1 How to ask

Same endpoint, `GET /api/v1/search/commutes`. Two new optional query fields:

| Field | Type | Notes |
|---|---|---|
| `origin[areaPlaceId]` | place id | A place with `isArea: true`. Matches commutes with a pickup point or route inside that area. |
| `destination[areaPlaceId]` | place id | The same for the drop-off end. |

Send either or both. Keep sending `origin[lat,lng]` and `destination[lat,lng]` as before: they are still required, and `distanceMetres` is measured from `origin`.

The flow:

1. Run the normal point search (no `areaPlaceId`).
2. If it returns fewer than 3 results **and** the chosen place has `isArea: true`, run it again with `areaPlaceId` on that end.
3. Show the second list under "More drivers in <area>", leaving out any `commuteId` already in the first list.

A pin or a non-area place has no area search: skip step 2.

### 3.2 What changes in the response

| Field | Where | Notes |
|---|---|---|
| `meta.scope` | both modes | `"point"` or `"area"` |
| `distanceMetres` | every result, both modes | Shortest distance in metres from the `origin` you sent to the commute's route or its nearest pickup point. `0` when she is on the route. |

In an area search, `meetingPoint` is the commute's pickup point inside the area. If the commute only drives through the area, it is the commute's nearest pickup point to where its route crosses the area. `meetingPoint.walkMinutes` and `detourMinutes` are then measured from that point, not from her pin, so use `distanceMetres` for "x km from you".

Area searches are **not** saved to recent searches.

### 3.3 Responses

**Point search that misses.** Her point is 3.7 km from the nearest pickup point:

```http
GET /api/v1/search/commutes?origin[lat]=30.03&origin[lng]=31.34&destination[lat]=30.0714&destination[lng]=31.0175&daysMask=62&arrivalWindowStart=06:45:00&arrivalWindowEnd=08:00:00
```

```json
{
  "success": true,
  "data": [],
  "meta": {
    "matched": 0,
    "scope": "point"
  }
}
```

**The same search widened to Nasr City:**

```http
GET /api/v1/search/commutes?origin[lat]=30.03&origin[lng]=31.34&origin[areaPlaceId]=01m7bv0srmycn4eh9arfhtacqb&destination[lat]=30.0714&destination[lng]=31.0175&daysMask=62&arrivalWindowStart=06:45:00&arrivalWindowEnd=08:00:00
```

```json
{
  "success": true,
  "data": [
    {
      "commuteId": "01m7bv0tbtpjg4zkf5x29rwzy5",
      "tripId": "01m7bv0tfb3er7e5jf6vgxmkwr",
      "tripDate": "2026-09-27",
      "departureAt": "2026-09-27T04:22:00+00:00",
      "departureLocal": "2026-09-27 07:22:00",
      "promisedArrivalAt": "2026-09-27T06:00:00+00:00",
      "promisedArrivalLocal": "2026-09-27 09:00:00",
      "daysMask": 62,
      "seatsAvailable": 3,
      "seatPricePiastres": 12500,
      "pricePerSeatPiastres": 12500,
      "audience": "women_only",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "womenOnly": true,
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
        "colour": "فضي",
        "tier": "standard",
        "hasAc": true
      },
      "meetingPoint": {
        "lat": 30.0624,
        "lng": 31.3614,
        "address": "Ring Road, Rehab exit",
        "walkMinutes": 0,
        "estimatedAt": "2026-09-27T04:48:48+00:00",
        "estimatedLocal": "2026-09-27 07:48:48"
      },
      "dropOffPoint": {
        "lat": 30.0714,
        "lng": 31.0175,
        "address": "Smart Village B6",
        "walkMinutes": 0,
        "estimatedArrivalAt": "2026-09-27T05:59:45+00:00",
        "estimatedArrivalLocal": "2026-09-27 08:59:45"
      },
      "myStatus": {
        "kind": "none",
        "seatRequestId": null,
        "bookingId": null
      },
      "overlapsBooking": null,
      "score": {
        "total": 85,
        "overlap": 15,
        "schedule": 25,
        "detour": 15,
        "audience": 10,
        "comfort": 10,
        "reliability": 10
      },
      "distanceMetres": 3667,
      "detourMinutes": 0,
      "tier": "on_way",
      "extraMinutes": 0,
      "rules": {
        "ac": true
      },
      "otherDays": [
        {
          "id": "01m7bv0tff5748m9cns44ymt5f",
          "tripDate": "2026-09-28",
          "departureAt": "2026-09-28T04:22:00+00:00",
          "departureLocal": "2026-09-28 07:22:00",
          "promisedArrivalAt": "2026-09-28T06:00:00+00:00",
          "promisedArrivalLocal": "2026-09-28 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tfjehxq0fcke2mngz2a",
          "tripDate": "2026-09-29",
          "departureAt": "2026-09-29T04:22:00+00:00",
          "departureLocal": "2026-09-29 07:22:00",
          "promisedArrivalAt": "2026-09-29T06:00:00+00:00",
          "promisedArrivalLocal": "2026-09-29 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tfpm2w01ng2dfjzspe3",
          "tripDate": "2026-09-30",
          "departureAt": "2026-09-30T04:22:00+00:00",
          "departureLocal": "2026-09-30 07:22:00",
          "promisedArrivalAt": "2026-09-30T06:00:00+00:00",
          "promisedArrivalLocal": "2026-09-30 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tftw2jektdrnk0m2n7x",
          "tripDate": "2026-10-01",
          "departureAt": "2026-10-01T04:22:00+00:00",
          "departureLocal": "2026-10-01 07:22:00",
          "promisedArrivalAt": "2026-10-01T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-01 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tfxmgpe4qyxe7q3ppv2",
          "tripDate": "2026-10-04",
          "departureAt": "2026-10-04T04:22:00+00:00",
          "departureLocal": "2026-10-04 07:22:00",
          "promisedArrivalAt": "2026-10-04T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-04 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tfzz9phr51ythtgj9yy",
          "tripDate": "2026-10-05",
          "departureAt": "2026-10-05T04:22:00+00:00",
          "departureLocal": "2026-10-05 07:22:00",
          "promisedArrivalAt": "2026-10-05T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-05 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tg245qj9mazyr1ak2pc",
          "tripDate": "2026-10-06",
          "departureAt": "2026-10-06T04:22:00+00:00",
          "departureLocal": "2026-10-06 07:22:00",
          "promisedArrivalAt": "2026-10-06T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-06 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tg52rabc2q616zv7bw8",
          "tripDate": "2026-10-07",
          "departureAt": "2026-10-07T04:22:00+00:00",
          "departureLocal": "2026-10-07 07:22:00",
          "promisedArrivalAt": "2026-10-07T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-07 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tg7djc7txe83krsngen",
          "tripDate": "2026-10-08",
          "departureAt": "2026-10-08T04:22:00+00:00",
          "departureLocal": "2026-10-08 07:22:00",
          "promisedArrivalAt": "2026-10-08T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-08 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tgawq1wg5pb5x24k0ee",
          "tripDate": "2026-10-11",
          "departureAt": "2026-10-11T04:22:00+00:00",
          "departureLocal": "2026-10-11 07:22:00",
          "promisedArrivalAt": "2026-10-11T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-11 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tgfy2vxk2zzq30rg61y",
          "tripDate": "2026-10-12",
          "departureAt": "2026-10-12T04:22:00+00:00",
          "departureLocal": "2026-10-12 07:22:00",
          "promisedArrivalAt": "2026-10-12T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-12 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tghqn4vetwqv08s0nyn",
          "tripDate": "2026-10-13",
          "departureAt": "2026-10-13T04:22:00+00:00",
          "departureLocal": "2026-10-13 07:22:00",
          "promisedArrivalAt": "2026-10-13T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-13 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tgkcmvjttpnnzjcwh8b",
          "tripDate": "2026-10-14",
          "departureAt": "2026-10-14T04:22:00+00:00",
          "departureLocal": "2026-10-14 07:22:00",
          "promisedArrivalAt": "2026-10-14T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-14 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tgpxckze6kbzg15dzz9",
          "tripDate": "2026-10-15",
          "departureAt": "2026-10-15T04:22:00+00:00",
          "departureLocal": "2026-10-15 07:22:00",
          "promisedArrivalAt": "2026-10-15T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-15 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tgs2eb903ws3v1wq8h6",
          "tripDate": "2026-10-18",
          "departureAt": "2026-10-18T04:22:00+00:00",
          "departureLocal": "2026-10-18 07:22:00",
          "promisedArrivalAt": "2026-10-18T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-18 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tgvjv9w90sp8dfh5czr",
          "tripDate": "2026-10-19",
          "departureAt": "2026-10-19T04:22:00+00:00",
          "departureLocal": "2026-10-19 07:22:00",
          "promisedArrivalAt": "2026-10-19T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-19 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0tgymd1qk1fj0e77c067",
          "tripDate": "2026-10-20",
          "departureAt": "2026-10-20T04:22:00+00:00",
          "departureLocal": "2026-10-20 07:22:00",
          "promisedArrivalAt": "2026-10-20T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-20 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0th0a6c2ctgbyye869yf",
          "tripDate": "2026-10-21",
          "departureAt": "2026-10-21T04:22:00+00:00",
          "departureLocal": "2026-10-21 07:22:00",
          "promisedArrivalAt": "2026-10-21T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-21 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0th2rxfv0a5j4zjdrhbg",
          "tripDate": "2026-10-22",
          "departureAt": "2026-10-22T04:22:00+00:00",
          "departureLocal": "2026-10-22 07:22:00",
          "promisedArrivalAt": "2026-10-22T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-22 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0th59rrn3g1s32yyp3xr",
          "tripDate": "2026-10-25",
          "departureAt": "2026-10-25T04:22:00+00:00",
          "departureLocal": "2026-10-25 07:22:00",
          "promisedArrivalAt": "2026-10-25T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-25 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        },
        {
          "id": "01m7bv0th7gmbtgnrm81m8z66a",
          "tripDate": "2026-10-26",
          "departureAt": "2026-10-26T04:22:00+00:00",
          "departureLocal": "2026-10-26 07:22:00",
          "promisedArrivalAt": "2026-10-26T06:00:00+00:00",
          "promisedArrivalLocal": "2026-10-26 09:00:00",
          "seatsAvailable": 3,
          "myStatus": {
            "kind": "none",
            "seatRequestId": null,
            "bookingId": null
          },
          "overlapsBooking": null
        }
      ]
    }
  ],
  "meta": {
    "matched": 1,
    "scope": "area"
  }
}
```

**A point search that matches** has the same row shape, with `"meta": {"matched": 1, "scope": "point"}` and, here, `"distanceMetres": 0`.

**`areaPlaceId` that is not an area** (here Smart Village, an office) or does not exist: `422`.

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "البيانات المُرسلة غير صحيحة.",
    "fields": {
      "origin.areaPlaceId": [
        "القيمة المحددة لحقل origin.area place id غير صالحة."
      ]
    }
  }
}
```

---

## 4. Place names: `nameAr` (scenario 4)

Every catalogue place has `nameAr`: the database requires it, and the backend now also refuses a blank one. `name` and `nameAr` are always both sent. Nothing changes for the app.

---

## 5. Custom pins: `nearLabel` (scenarios 5 and 8)

Every point below now carries three more fields beside its own `label` (or `address`):

| Field | Type | Notes |
|---|---|---|
| `nearPlaceId` | place id or `null` | The nearest catalogue place within 1 km |
| `nearLabel` | string or `null` | That place's name in the request's `Accept-Language` |
| `nearDistanceMetres` | int or `null` | Straight-line distance from the point to that place |

All three are `null` together when no catalogue place is within 1 km. `label` is still exactly what the person typed.

The near place is worked out when the point is read, not when it was saved, so a place added to the catalogue later also names older pins.

### 5.1 Where the fields appear

| Response | Points |
|---|---|
| Seat request (`GET /seat-requests`, `GET /seat-requests/{id}`, driver inbox) | `pickup`, `dropoff`, and `counteroffer.pickup` / `counteroffer.dropoff` |
| Counteroffer options | `requested.pickup`, `requested.dropoff`, each suggested stop |
| Pickup change request | `currentPoint`, and `proposedPoint` once approved |
| Booking | `pickup` and `dropoff`, once the booking is confirmed |
| Commute (driver's own) | every row of `locations[]` |
| Commute (passenger's view) | each suggested pickup and drop-off |
| Navigation route | every stop |

🔒 Where a point is still approximate (a booking before confirmation, a pickup change before approval), the three fields are `null`, the same as `label`.

The label length is not capped on the backend (still 255, or 150 on a pickup change). Keep the app's 60-character cap.

### 5.2 Responses

A seat request with two custom pins. The pickup is 386 m from the Nasr City place; the drop-off has no catalogue place within 1 km.

```http
GET /api/v1/seat-requests/01m7bv0v01qzgee8e8pkx0c2aw
Accept-Language: ar
```

```json
{
  "pickup": {
    "lat": 30.059,
    "lng": 31.333,
    "label": "هنا",
    "nearPlaceId": "01m7bv0srmycn4eh9arfhtacqb",
    "nearLabel": "مدينة نصر",
    "nearDistanceMetres": 386,
    "source": "custom_map",
    "suggestionId": null,
    "estimatedAt": "2026-09-27T04:54:38+00:00",
    "estimatedLocal": "2026-09-27 07:54:38"
  },
  "dropoff": {
    "lat": 30.0684,
    "lng": 31.1015,
    "label": "عند المحور",
    "nearPlaceId": null,
    "nearLabel": null,
    "nearDistanceMetres": null,
    "source": "custom_map",
    "suggestionId": null,
    "estimatedArrivalAt": "2026-09-27T05:42:25+00:00",
    "estimatedArrivalLocal": "2026-09-27 08:42:25",
    "promisedArrivalLocal": "2026-09-27 09:00:00"
  }
}
```

The same request with `Accept-Language: en`: only `nearLabel` changes.

```json
{
  "pickup": {
    "lat": 30.059,
    "lng": 31.333,
    "label": "هنا",
    "nearPlaceId": "01m7bv0srmycn4eh9arfhtacqb",
    "nearLabel": "Nasr City",
    "nearDistanceMetres": 386,
    "source": "custom_map",
    "suggestionId": null,
    "estimatedAt": "2026-09-27T04:54:38+00:00",
    "estimatedLocal": "2026-09-27 07:54:38"
  }
}
```

A driver's own commute, `data.locations` (`Accept-Language: en`):

```json
[
  {
    "id": "01m7bv0tcmc3gynvard1pbp3q0",
    "type": "origin",
    "lat": 30.0594,
    "lng": 31.4913,
    "address": "Rehab Gate 2",
    "placeId": null,
    "nearPlaceId": "01m7bv0srgygxz6gc7pvk4v6rn",
    "nearLabel": "Rehab",
    "nearDistanceMetres": 459,
    "sequence": 0
  },
  {
    "id": "01m7bv0tcn6bykhw8emb4jrw04",
    "type": "pickup",
    "lat": 30.0624,
    "lng": 31.3614,
    "address": "Ring Road, Rehab exit",
    "placeId": null,
    "nearPlaceId": null,
    "nearLabel": null,
    "nearDistanceMetres": null,
    "sequence": 1
  },
  {
    "id": "01m7bv0tcn6bykhw8emb4jrw05",
    "type": "dropoff",
    "lat": 30.0684,
    "lng": 31.1015,
    "address": "Mehwar, Sheikh Zayed",
    "placeId": null,
    "nearPlaceId": null,
    "nearLabel": null,
    "nearDistanceMetres": null,
    "sequence": 2
  },
  {
    "id": "01m7bv0tcpgg8ngvtj0x9zvx0p",
    "type": "destination",
    "lat": 30.0714,
    "lng": 31.0175,
    "address": "Smart Village B6",
    "placeId": null,
    "nearPlaceId": "01m7bv0ss295gs6a5p6dcv1w5z",
    "nearLabel": "Smart Village",
    "nearDistanceMetres": 66,
    "sequence": 999
  }
]
```

For "Open in Google Maps / Waze" (scenario 8), open by `lat`/`lng` and use `nearLabel` as the visible name when it is not `null`, else `label`.

---

## 6. Push language (scenario 9)

Nothing changed. Pushes are written in the profile language, which defaults to `ar`. Send `language` after every sign-in as planned.

## 7. Not done

- **Google Places fallback**: not built, as agreed for after the beta.
- **Area polygons**: areas are circles (centre and radius). A commute just outside a district's real edge can appear, and one in a far corner of a large district can be missed.
- **More catalogue places**: still the 26 seeded ones.
- **Production server key and always sending `polyline`** (scenario 11): not part of this change.

## 8. Checklist for the app

- [ ] Handle `type` = `district` and `city`, with a fallback for unknown types.
- [ ] Read `isArea` and `radiusMetres` on place rows.
- [ ] Stop normalising `q` in the app, if it does.
- [ ] Run the area search when the point search returns fewer than 3 results and the place is an area.
- [ ] Read `meta.scope` and `distanceMetres` on search results.
- [ ] Show `nearLabel` beside a custom pin's `label`, and handle `null`.
- [ ] Send profile `language` after every sign-in.
