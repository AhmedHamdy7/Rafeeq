# Create Commute: not checked on live

Four things Flutter hasn't tested on the live server yet. The "Expected" column comes from the backend code and the local captures in `FLUTTER_CREATE_COMMUTE_RESPONSES.md`. None of it has been confirmed on live.

Test accounts are in `FLUTTER_CREATE_COMMUTE_GAPS_ANSWERS.md`, section G15. Reset a state with `php artisan demo:create-commute-states --state=CC-READY`.

| # | Item | Account | Call | Expected | Can test now? |
|---|---|---|---|---|---|
| 1 | Road can't be calculated | Any | `GET /commutes/{commute}/route-preview` | `503 COMMUTE_ROUTE_UNAVAILABLE` | No, live uses straight-line routing |
| 2 | Schedule after publishing | `CC-READY` | `PUT /commutes/{commute}/schedule` on a published commute | `COMMUTE_NOT_EDITABLE` | Once seeded |
| 3 | Duplicate and return | `CC-READY` | `POST /commutes/{commute}/duplicate`, `POST /commutes/{commute}/return` | `201`, a new draft | Once seeded |
| 4 | Suspended driver's existing draft | `CC-SUSPENDED` | `POST /commutes/{commute}/publish` on the draft | `403 DRIVER_NOT_ELIGIBLE` | Once seeded |

## 1. Road can't be calculated

Live can't produce this until the real routing engine is on, because the straight-line engine never fails. The captured response comes from a local server with the routing provider made to fail.

To test it:
1. The backend sets `RAFEEQ_GEO_ENGINE=google` and `GOOGLE_MAPS_API_KEY` on the server.
2. Flutter checks that the route step shows a real road first.
3. The failure itself needs the provider to be down, so the backend has to arrange it. Don't wait on this to wire "Route not available"; use the captured response.

## 2. Schedule after publishing

The local capture covers `PUT /route` after publishing, not `PUT /schedule`. Both use the same check in the code. Times on a published commute change through a change proposal.

Check: the `PUT` is refused, and the commute's times are unchanged afterwards.

## 3. Duplicate and return

Check on each new draft:
- It's a new `DRAFT`, with no bookings.
- `weekdays[]` keeps `promisedArrivalTime` and `departureIsComputed`.
- `return` has the route reversed and `direction` flipped. Never show `direction`.
- If the source commute's whole window has passed, `duplicate` still answers `201` with `schedule: null`.

## 4. Suspended driver's existing draft

The `CC-SUSPENDED` account already has a complete draft.

Check:
- Publish answers `403 DRIVER_NOT_ELIGIBLE`.
- The draft stays a `DRAFT` and can still be read.
- A new draft is refused too. See "Suspended driver" in the responses file.

## When you reply

For each item, send the result and the real response if it differs from "Expected".
