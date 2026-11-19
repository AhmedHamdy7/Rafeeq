# Answer: `GET /my-bookings?scope=upcoming` answered 500 (2026-11-19)

Answer to `backend-my-bookings-500-2026-11-19.md`. **Fixed on the server.** It reaches the test server with the next deploy. No app change is needed.

## What happened

It was a backend bug from the 2026-11-19 deploy (S1, "passed your stop" from GPS).

- Every booking in the list works out its `driverDelay`.
- The new GPS check read the day's trip session without loading it first.
- The test server refuses that ("lazy loading"), but only when the row came from a list of several rows.

So it hit Mariam and not Karim:

- Her recurring approval created several confirmed bookings, so her upcoming list had several rows on the same commute.
- Karim's list didn't, and `scope=past` didn't either.

When her bookings were cancelled, the rows went away and the call answered `200` again. That matches what you saw at 02:04.

## What changed

- The check now loads what it needs, so `GET /my-bookings?scope=upcoming` answers `200` with every confirmed day.
- The same fix covers `GET /bookings/{booking}`, `/home` and `POST /bookings/{booking}/exit-without-fee`. Each reads `driverDelay` the same way.
- The same kind of problem was waiting in the new pickup-change expiry job (T7) once two changes were open. It is fixed before anyone hit it.

New backend tests:

- a recurring approval, then `GET /my-bookings?scope=upcoming` with and without `page` / `perPage`;
- two open pickup changes expiring in one run of the job.

## For the next Patrol run

Nothing changes in the response shape. Rerun g117, g146 and g59 after the deploy. If any list call answers `500` again, please send the time (UTC), the caller's phone number and the exact URL.
