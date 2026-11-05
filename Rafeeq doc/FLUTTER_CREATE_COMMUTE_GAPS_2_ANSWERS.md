# Create Commute: answers to the backend gaps, round 2

Answers to `create_commute_backend_gaps_2.md`. Real responses are in `FLUTTER_CREATE_COMMUTE_RESPONSES.md`, under "Round 2". They were captured locally, not on live. All of this reaches live only after the next deploy.

Items marked **interim** are backend choices no Product rule covers yet, so they could change. D-0034 still needs the other partners' approval, and nothing here changes its rules.

| # | Item | Answer |
|---|---|---|
| G17 | Saved date | Added: `updatedAt` and `createdAt` |
| G18 | Saved step | Added: `formStep`, 1 to 5 (**interim**) |
| G19 | Commission week | Added: `weekStart[]`, the oldest unpaid week |
| G20 | Estimate after a route change | Fixed |
| G21 | Stop-too-far message | Fixed, in English and Arabic |
| G22 | Real route engine | Not on yet; we'll tell you |
| G23 | Suspended driver's draft | Read-only, and Discard is allowed (**interim**) |
| G24 | Stop-too-far field names | Confirmed, and drop-offs are now checked |
| G25 | Drivers who aren't approved | Show the verification gate, except `SUSPENDED` |
| G26 | Test access | Three new states; the limit is explained below |
| G27 | Weekdays without times | Stays as it is; keep the design's warnings |

## G17. Saved date

`updatedAt` and `createdAt` are on the commute, as UTC timestamps. `updatedAt` changes on every saved step: `PUT /route`, `PUT /schedule` and `PATCH`. Show "Saved Tue 3 Nov" in the driver's local time.

## G18. Saved step

Send `formStep` on `PATCH /commutes/{commute}`, 1 to 5. Read it back as `formStep`, which is `null` until you send one.

- Anything else answers `422 VALIDATION_FAILED`.
- The server only stores it. It doesn't check it against `missing[]`, so use `missing[]` to decide what's still needed.
- It's kept on a draft only, and ignored after publishing. A duplicate or a return starts with `null`.

## G19. Commission week

`DRIVER_COMMISSION_OVERDUE` now also has `weekStart[]`, as `YYYY-MM-DD`. When more than one week is unpaid, it holds the **oldest**. `amountOwedPiastres[]` is the total owed, which can cover several weeks.

## G20. Estimate after a route change

`PUT /route` now recalculates `weekdays[].departureTime` and `schedule.departureTime` for days where `departureIsComputed` is `true`. You don't need to send `PUT /schedule` again. It's still an estimate with no passengers.

## G21. Stop-too-far message

It now ends "Move it closer to your route.", with no mention of raising a limit. The same change is in Arabic.

The number in it is the outer whole-trip limit, currently 30 minutes.

## G22. Real route engine

Still straight-line on live. We'll tell you when `RAFEEQ_GEO_ENGINE=google` is on. There's no way to see `COMMUTE_ROUTE_UNAVAILABLE` on live yet, so wire "Route not available" from the captured response.

## G23. Suspended driver's draft

| Call | Answer |
|---|---|
| `GET` | Allowed |
| `PUT /route`, `PUT /schedule`, `PATCH` | `403 DRIVER_NOT_ELIGIBLE`, with the suspended message |
| `POST /publish` | `403 DRIVER_NOT_ELIGIBLE` |
| `DELETE` | Allowed, so `CC:D08` can keep Discard |

Before this change, the three edits were allowed by mistake.

## G24. Stop-too-far field names

- A drop-off that's too far answers `dropoffs.{index}[]`. Drop-offs weren't checked before.
- `{index}` is the position in the list you sent, from 0.
- Every stop that's too far is listed at once. A point sent in both lists answers for both: `pickups.{index}` and `dropoffs.{index}`.
- `route[]` is still for the ends being the same place, or too many stops.

## G25. Drivers who aren't approved

Yes, show the verification gate (`CC:R03`) for every status in `driver[]` except `SUSPENDED`, which is `CC:R12`.

- `REJECTED` gets the same gate. The verification screen then shows what to fix, from `actionItem` (PROF-17).
- An expired licence isn't in this group. It answers `DRIVER_LICENCE_EXPIRED`.

## G26. Test access

**Rate limit:** `POST /auth/otp/request` allows 5 requests per phone and 20 per IP per hour. Each limit clears within an hour of being reached. We're also asking for the limits to be turned off on the test server, and we'll tell you when that's done.

**New states**, available once the server is reseeded:

| State | Phone | What it has |
|---|---|---|
| `CC-WINDOW-PASSED` | `+201055557412` | A published commute whose whole window has passed, so `duplicate` answers `schedule: null` |
| `CC-PENDING-REVIEW` | `+201055557413` | Driver in `PENDING_REVIEW`, with a complete draft |
| `CC-DRIVER-REJECTED` | `+201055557414` | Driver in `REJECTED`, with a complete draft |

The nightly job can archive the `CC-WINDOW-PASSED` commute. `duplicate` answers the same either way; reseed the state if you need it published again.

**Leftovers:** reseeding removes the archived commutes your testing left, unless they have bookings.

## G27. Weekdays without times

This stays as it is, so keep the warnings in `CC:S03` and `CC:T07`. Accepting days with no time would touch how trips are generated and how search reads a commute, which is too much for an optional change.
