# Backend for the app review of 2026-11-23

Answers to `2026-11-23-backend-requirements.md` (review items 1–30). Items 1–7 are built; 8 and 9 are not (see the
end). Everything reaches the test server with the next deploy. Every new field is optional: today's builds keep working.

| # | Review items | Change | State |
|---|---|---|---|
| 1 | 8, 14, 19, 21, 24 | Google places and areas on every point; point-then-area matching | ✅ built |
| 2 | 12, 16 | Demand and search time is arrive-by | ✅ built |
| 3 | 30 | Women-only only for verified female drivers | ✅ built |
| 4 | 15 | `minSeatsOpen`; full commutes returned | ✅ built |
| 5 | 18 | Travel date range `dateFrom` / `dateTo` | ✅ built |
| 6 | 20 | Whole route line and real ends on the public commute | ✅ built |
| 7 | 11 | Per-commute A/C | ✅ built |
| 8 | 6 | Drop the `guidelines` content page | ⏸ kept (older builds still read it) |
| 9 | 7 | Organisations | ⏸ next release, not built |

---

## 1 · Google places and areas

### The point shape the server accepts

Everywhere a point is sent, these fields are accepted next to `lat` / `lng`:

```json
{
  "lat": 30.0605,
  "lng": 31.3456,
  "label": "Makram Ebeid St",
  "googlePlaceId": "ChIJ…",
  "area": { "placeId": "ChIJ…", "name": "Nasr City", "city": "Cairo" }
}
```

- `label` is stored **as sent** (coordinates like `"30.06050, 31.34560"` are fine). When a point carries
  `googlePlaceId` or `area`, the server no longer names it after the nearest catalogue place.
- `area.name` / `area.city` are stored in the language of the request's `Accept-Language` (`ar` or `en`). Send the
  header you already send.
- `googlePlaceId` and `area` are optional; `area: null` is fine. Old builds can keep sending the catalogue `placeId`.

| Endpoint | Where the fields go |
|---|---|
| `PUT /commutes/{commute}/route` | `origin`, `destination`, every `pickups[]` / `dropoffs[]`. `label` is accepted as the name of `address` (send either) |
| `POST /driver/commutes/{commute}/change-proposals` | every `pickupPoints[]` |
| `POST /commute-demands`, `PATCH /commute-demands/{id}` | `origin`, `destination` |
| `GET /search/commutes` | query: `origin[googlePlaceId]`, `origin[areaId]`, `origin[areaName]`, `origin[areaCity]` (same for `destination`). `origin[area][placeId]` etc. also works |
| `POST /commutes/{commute}/seat-requests`, `PATCH /seat-requests/{id}` | custom `pickup` / `dropoff` (a picked `suggestionId` takes the suggestion's area) |
| `POST /driver/seat-requests/{id}/counteroffer` | custom `pickup` / `dropoff` |
| `POST /seat-requests/{id}/pickup-request`, `POST /groups/{group}/pickup-request` | top level: `lat`, `lng`, `label`, `googlePlaceId`, `area` |
| `POST /driver/pickup-requests/{id}/suggest-alternative` | **now a full point** at top level: `lat`, `lng`, `label`, `googlePlaceId`, `area`. `placeId` (catalogue) still works for old builds |

### Where they come back

| Response | New fields |
|---|---|
| Commute `locations[]` (driver's commute) | `label` (same as `address`), `googlePlaceId`, `area {placeId, name, city}` |
| Demand `origin` / `destination` | `googlePlaceId`, `area` |
| `GET /me/recent-searches` → `criteria.origin` / `destination` | `googlePlaceId`, `area` (send them straight back with the search) |
| Pickup request `proposedPoint` | `area`. `label` stays the **area name** until approved |
| Pickup request `alternativePoint` (new) | `{id, label, lat, lng, googlePlaceId, area, isExact}`. `alternativePlace` has the same shape, kept for old builds |

Area names follow `Accept-Language`; a name stored only in the other language falls back to it.

### Search: point first, then same area

`GET /search/commutes` answers as before (`data` = the point matches), plus a new top-level list:

```json
{
  "success": true,
  "data": [ … ],
  "meta": { "matched": 1, "areaMatched": 2, "scope": "point" },
  "areaMatches": [ … same item shape as data … ]
}
```

- `areaMatches` is filled only when `data` has **fewer than 3** commutes and the search sent `origin` area. It holds
  commutes whose start or a pickup suggestion is in her origin's area and whose end or a drop-off is in her
  destination's area (or that pass near her destination). Never the same commute as in `data`.
- Same time, day, seats and rules checks as `data`.
- Every result (in both lists) now has:
  - `matchKind`: `"near"` (in `data`) or `"sameArea"` (in `areaMatches`)
  - `areaName`: the area name for `sameArea`, else `null` ("More drivers in Nasr City")
  - `distanceMetres`: from her point to the commute
- `origin[areaPlaceId]` / `destination[areaPlaceId]` (catalogue areas) still work for old builds; with those,
  `meta.scope` is `area` as before.

### Saved demands

Demand matching runs the same two steps. Each match records its kind:

- `GET /matches` rows: `matchKind` (`near` | `sameArea`) and `areaName`.
- `GET /home` → `demandAlert`: `matchKind` (`near` when any new match is near, else `sameArea`) and `areaName`.
- `match_found` push: data has `matchKind` and `areaId`. For `sameArea` the text says "A commute in Nasr City" /
  "فيه رحلة في مدينة نصر".

---

## 2 · Arrive-by time

The demand's and the search's time is **arrive by**. A commute fits when its **promised arrival** that day is between
`time − 30 min` and `time` (inclusive). Pickup / departure time is no longer compared. `flexibilityMinutes` is
accepted and ignored.

- New: `arriveBy=08:00` (`HH:MM`) on `GET /search/commutes` and on `POST/PATCH /commute-demands`.
- Leave the time out for **any time**.
- Still accepted (old builds): `arrivalWindowStart` / `arrivalWindowEnd`. They mean "promised arrival between start −
  30 min and end". Sending the same time in both is the same as `arriveBy`.
- A demand saved with `arriveBy` returns it as `arrivalWindowStart` = `arrivalWindowEnd`.
- A commute day without a promised arrival uses departure + the route's duration.

---

## 3 · Women only

- `GET /commutes/options` → `canMarkWomenOnly` is `true` only when the driver's gender is `woman` **and** her
  government ID is approved. Anything else (man, unknown gender, ID not approved) → `false`.
- `POST /commutes`, `PATCH /commutes/{commute}`, publish and resume refuse `audience: "women_only"` from anyone else:
  `422 COMMUTE_WOMEN_ONLY_NOT_ALLOWED`, field error on `audience`.
- Deploy resets existing women-only commutes of drivers who aren't verified women to `any_verified`.

---

## 4 · Seats open

- `GET /search/commutes?minSeatsOpen=1..3`: only days with at least that many free seats.
- Without it: full commutes come back too, last, with `isFull: true` and `seatsAvailable: 0` (show "Full" and "Join
  waiting list").
- `seatsNeeded` (old builds) works as before.

---

## 5 · Travel date range

- `GET /search/commutes?dateFrom=2026-11-30&dateTo=2026-12-02` (Cairo dates). `daysMask` is optional when the dates
  are sent; when both are sent, the dates win.
- Rules (`422` with a field error otherwise): `dateFrom` not in the past, `dateTo ≥ dateFrom`, at most **5 days**
  inclusive, `dateTo` at most 90 days ahead.
- Returns commutes with a scheduled trip on at least one of the dates (inside the commute's start / end, not on a
  paused or cancelled day).
- Every result has `matchedDates: ["2026-11-30", "2026-12-02"]`: the card's day plus `otherDays`, all inside the
  range.

---

## 6 · Whole route on the public commute

`GET /commutes/{commute}/public` → `route`:

- `polyline`: always the whole stored line.
- `start` / `end`: the real start and end, `{label, lat, lng}` (`label` is the driver's name for the point).
- `isTrimmed`: always `false` (kept for old builds; delete your trimmed-route handling).

---

## 7 · Per-commute A/C

- `POST /commutes` now takes `rules` (same map as `PATCH`), e.g. `{"rules": {"ac": true, "quiet": true}}`.
- `rules.ac: true` on a car without A/C → `422 COMMUTE_AC_NOT_AVAILABLE` (field `rules.ac`).
- Not sent: the commute uses the car's A/C. Existing commutes got the car's value on deploy.
- `rules.ac` is now **always** in `rules` (true or false) on the driver's commute, search results, the public
  commute, browse and `/matches`. Read the commute's A/C from `rules.ac`, not `vehicle.hasAc` (`vehicle.hasAc` still
  says whether the car has A/C, for the Create / Edit toggle).
- The passenger's A/C preference (`rules[]=ac`) matches only commutes with `rules.ac: true`.

---

## Not built

- **Item 8 · `guidelines`:** still answers on `GET /content/guidelines`, since older builds read it. It can go once no
  installed build calls it.
- **Item 9 · Organisations:** next release.
- **Retiring `GET /places` and the catalogue:** waits until no installed build calls it.

## Owner and deploy steps

1. Enable **Places API (New)** and **Geocoding API** on the app's Maps key project (app side).
2. For the server's area check and backfill: create a server key restricted to the **Geocoding API** and set
   `GOOGLE_MAPS_SERVER_KEY` on Railway. Without it the server keeps the area the app sends.
3. After deploying with the key: run `php artisan areas:backfill` once to fill the area of every stored point.
4. Check the owner's test driver account: its saved gender, and whether it should see Women only.
