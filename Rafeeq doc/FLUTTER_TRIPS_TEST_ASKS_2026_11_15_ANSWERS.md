# Answers: two Trips states the tests could not reach (2026-11-15)

Answers to `backend-trips-test-asks-2026-11-15.md`. The new call reaches the test server with the next deploy.

| # | Answer |
|---|---|
| 1 | ✅ Built: `POST /testing/commute-demands/{demand}/expire` |
| 2 | Yes, she is charged, in one case only. Keep the reduced-fare line. The fare didn't show in your runs because the car's position was not past 25 % of her route |

## 1 · Expiring a saved request

`POST /testing/commute-demands/{demand}/expire`, no body, guide §4.17.

- Caller: the demand's owner.
- Moves `expiresAt` into the past and runs the `expire-demands` job's own code on that demand only, so
  `status` becomes `EXPIRED`.
- The job sends **no** push or inbox row when a demand expires, so this call sends none either.
- Answers the demand as `GET /commute-demands/{demand}` does.
- `404` for somebody else's demand or a cancelled one.
- `409 COMMUTE_DEMAND_NOT_EDITABLE` with `error.fields.status` when the demand is not `ACTIVE`: `EXPIRED`
  (already done) and also `PAUSED`, because the job never expires a paused demand. Resume it first.

Resuming works as the guide says: `PATCH /commute-demands/{demand}` with `{paused: false}` answers `ACTIVE`
with `expiresAt` 60 days ahead.

`expiresAt` and `endDate` on `POST` and `PATCH` stay ignored on purpose: the expiry is a platform rule
(Chapter 6), not something a passenger sets.

## 2 · When a passenger pays on a stopped run

The rule is PAY-24 and PAY-25 in the business spec, and the server does what they say.

| `reason` | How far the car carried her (pickup → her drop-off) | She pays | `paymentStatus` | `price.totalPiastres` |
|---|---|---|---|---|
| `other` | any | 0 | `WAIVED` | the full seat price, unchanged |
| `breakdown` · `accident` · `emergency` | 25 % or less, or the car's position is unknown | 0 | `WAIVED` | the full seat price, unchanged |
| `breakdown` · `accident` · `emergency` | more than 25 % | that share of the fare, by distance | `PAID` (cash, settled at once) | **the reduced amount** |

So the app should read it like this:

1. `paymentStatus: WAIVED` → "You weren't charged for this ride." Ignore `price.totalPiastres`.
2. `paymentStatus: PAID` on an `ENDED_EARLY` booking with `tripCancelReason` set → "You pay {amount} EGP for
   the part you rode", with `price.totalPiastres` as the amount. The server overwrites it with the reduced
   amount, so it is no longer the seat price.

The share is measured from the car's **last known position** when the driver stops the run: how far that is
from her pickup, against the distance from her pickup to her drop-off.

**Why your breakdown run was waived.** The car had no recorded position past 25 % of her route when it was
stopped. To reach the reduced fare on the test server:

1. Start the run and check her in.
2. Place the car past a quarter of her route with `POST /testing/trips/{trip}/position`. Send `lat`/`lng` of
   a point well past her pickup towards her drop-off, with `metres: 0` (the default puts the car 1 km
   **before** the point).
3. `POST /trips/{trip}/cancel-active` with `reason: breakdown`.
4. `GET /bookings/{booking}` as her: `paymentStatus: PAID`, `price.totalPiastres` below the seat price.

Guide §4.9 (Cancel Active Trip) now has these rules. No behaviour changed for this answer; a new test covers
both cases (`tests/Feature/Trip/CancelActiveTripTest.php`).
