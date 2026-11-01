<!-- task: 123uve060qm · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

```json
"live": { "pickupEtaAt": "2026-10-18T04:04:00+00:00", "arrivalEtaAt": null, "progressPercent": 45,
          "driverArrivedAt": null, "boardedAt": null }
```
- Non-null **only** for `driver_en_route` / `driver_arrived` / `on_board`; `null` otherwise.
- en route: `pickupEtaAt` + `progressPercent` (to her pickup). Arrived: `driverArrivedAt`, `progressPercent: 100`. On board: `arrivalEtaAt` + `progressPercent` (to her drop-off) + `boardedAt`.
- ETA = last stop the car actually reached + the itinerary's measured leg times. It is never in the past, and it is `null` when no itinerary was built (no made-up ETAs, per v5). Progress stops at 99 until the car is recorded there.
- **Poll interval: 30 s while `live` is not null; don't poll otherwise.**


