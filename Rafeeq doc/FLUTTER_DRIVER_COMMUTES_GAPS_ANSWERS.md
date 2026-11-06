# Driver Commutes: answers to the backend gaps

Answers to `driver_commutes_backend_gaps.md`, G1 to G13. Real responses are in `FLUTTER_DRIVER_COMMUTES_RESPONSES.md`.

**Status:** this is on branch `fix/driver-commutes-gaps` and isn't deployed. All responses were captured locally, none on live. Nothing below works on live until it's merged, deployed and seeded.

Items marked **interim** are backend choices no Product rule covers yet, so they could change.

| # | Item | Answer |
|---|---|---|
| G1 | Coming days | Added: `GET /driver/trips?scope=upcoming` |
| G2 | Passengers on a day | Added: `commuteId`, `tripId` and `status` filters, and `passenger` |
| G3 | Past days | Added: `GET /driver/trips?scope=past` |
| G4 | Arrival on each day | Empty days get filled; a booked old day can stay `null` |
| G5 | Fee for one passenger | Added: `GET /driver/bookings/{booking}/cancel-preview` |
| G6 | Editing with nobody booked | A proposal now applies at once (**interim**) |
| G7 | Seats on a published day | Added: `PATCH /driver/commutes/{commute}/seats` (**interim**) |
| G8 | Passenger times on a proposal | Added, and `?commuteId=` |
| G9 | Replace a passenger | Stays, and it's built. Rule confirmed; lock details **interim** |
| G10 | Times on a proposal | Arrival only in the app; `departureTime` stays on the API (**interim**) |
| G11 | Reliability on the cancel sheets | There are no levels. Ask Design to remove both |
| G12 | Commute list shape | Confirmed below |
| G13 | Responses and accounts | Captured locally; accounts below |

## G1. Coming days

`GET /driver/trips?scope=upcoming`, paginated, oldest first. Optional `commuteId`.

Each row has `id`, `commuteId`, `tripDate`, `departureLocal`, `promisedArrivalLocal`, `status`, `originLabel`, `destinationLabel`, `seatsTotal`, `seatsTaken`, `seatsAvailable`, `seatPricePiastres`, `driverSharePiastres` and `passengerCount`.

- It includes a run under way, so `status` can be `PREPARING`, `EN_ROUTE` or `IN_PROGRESS`.
- A paused commute's days are left out, because they aren't running (**interim**).

## G2. Passengers on a day

`GET /driver/bookings` now takes `commuteId`, `tripId` and `status`. `status` takes a comma-separated list, such as `?status=confirmed`. A wrong value answers `422`.

Each row has `meetingPoint`, `dropoffPoint`, `pickupEtaLocal`, `price.driverSharePiastres`, and a new `passenger`.

**The name is `passenger.publicFirstName`, a first name only.** SAFE-18 says other users never see a full name, so "Salma Kamal" in the design can't be shown. That's a known design gap (G14 in the business repo).

Without a `status`, the list includes cancelled seats, so pass `status=confirmed` for who's riding.

## G3. Past days

`GET /driver/trips?scope=past`, paginated, newest first. It has the G1 fields, plus:

| Field | Meaning |
|---|---|
| `status` | `COMPLETED`, `CANCELLED` or `EXPIRED` |
| `passengerCount` | Passengers who rode |
| `durationSeconds` | From departure to finish, `null` unless the run finished |
| `ratingAverage` | Average of the ratings passengers can already see, `null` if none |
| `cancelledBy` | `DRIVER`, `PLATFORM` or `null` |
| `passengersNotified` | Passengers who lost their seat on that day |
| `expiredAt` | UTC, `null` unless expired |

A cancelled day appears here even if its date is still ahead. `PLATFORM` means the commute ended or the account was deleted.

**Opening a past day:**
- **Completed:** `GET /trips/{trip}`, and `GET /driver/bookings?tripId=` for who rode.
- **Cancelled or expired:** use the row. `GET /trips/{trip}` answers `409 TRIP_NOT_STARTED`, because the run never started.

## G4. Arrival on each day

- A migration fills `promisedArrivalLocal` on future days with no seats taken, from the weekday's arrival.
- A day that already has passengers and no arrival stays `null`, because adding a promise changes their terms.
- New days always have it.

So `null` can still happen. Show the departure only, as you suggested.

## G5. Fee for removing one passenger

`GET /driver/bookings/{booking}/cancel-preview`, in the same shape: `affectedSeats`, `feePiastres`, `reason`, `perTrip[]`. It uses the same rule as the cancel (PAY-23).

A seat that isn't `CONFIRMED` answers `409 BOOKING_NOT_CANCELLABLE`. Someone else's booking answers `404`.

## G6. Editing with nobody booked (interim)

A proposal with nobody affected now applies at once. `POST .../change-proposals` answers `201` with `status: APPLIED` and `passengers: []`.

**`CHANGE_PROPOSAL_NOBODY_AFFECTED` is gone**, so there's no response to capture. `PUT /route` and `PUT /schedule` still answer `409 COMMUTE_NOT_EDITABLE` on a published commute.

| Scope | Open requests | Waiting list |
|---|---|---|
| `only_this_occurrence` | Stay open | Stays (COM-18) |
| `all_future_on_this_weekday` | Stay open | Cleared, and members are told to reapply (COM-19) |

Limits to know:
- Only times and pickup points can change. Start, end and drop-off points can't be changed on a published commute. To change those, the driver deletes it, or duplicates it and publishes again.
- A pickup too far from the route now answers `422 COMMUTE_ROUTE_INVALID`, with `pickups.{index}`. This applies to every proposal.
- The driver gets no notification for their own change.

## G7. Seats on a published day (interim)

```json
PATCH /driver/commutes/{commute}/seats
{ "seatsTotal": 2, "scope": "only_this_occurrence", "scheduledTripId": "01m6..." }
```

| `scope` | Changes | `scheduledTripId` |
|---|---|---|
| `only_this_occurrence` | That day | Required |
| `all_future_on_this_weekday` | That weekday from that day on, including days not generated yet | Required |
| `all_future_days` | Every future day | Not needed |

It answers `{seatsTotal, scope, updatedDays, tripIds[]}`. It reaches days already generated, including inside the 12-hour lock.

- **Below the seats taken:** `409 COMMUTE_SEATS_CONFLICT`, with the highest number taken in `fields.seatsTotal`. Nothing changes and nobody loses a seat. The driver removes a passenger first.
- **Above the car's approved seats:** `422 COMMUTE_SEATS_EXCEED_APPROVED`.
- **More seats on a published commute:** the new seats go out to the waiting list automatically.

`PATCH /commutes/{commute}` with `seatsTotal` still only affects days generated later, so use this call on the Edit screen.

## G8. Passenger times on a proposal

`passengers[]` now has `previousPickupLocal`, `newPickupLocal`, `pickupLabel` and `tripDate`, on `GET`, `POST` and `PATCH`.

- Both times are `HH:MM:SS`, and either can be `null` if a pickup time can't be worked out.
- They're estimates: the current pickup, moved by the same amount as the departure.
- `GET /driver/change-proposals?commuteId=` is added.

With `all_future_on_this_weekday`, one passenger can appear several times, once per day. Use `tripDate`.

## G9. Replace a passenger

It stays. COM-22 is a confirmed rule, so don't remove the screen.

| Step | Call |
|---|---|
| Show who can take it | `GET /driver/waitlist?tripId=` |
| Show the fee | `GET /driver/bookings/{booking}/cancel-preview` |
| Offer the seat | `POST /driver/bookings/{booking}/replacement-offer` with `seatRequestId` |
| Read its status | `replacementOffer` on `GET /driver/bookings`, and `replacesBookingId` on `GET /driver/seat-offers` |
| Withdraw it | `DELETE /driver/seat-offers/{offer}` |

The other passenger answers with the usual `POST /seat-offers/{offer}/accept` or `decline`. They aren't told it's a replacement, and the original passenger isn't told until they lose the seat.

`replacementOffer` is `{offerId, seatRequestId, passengerFirstName, status, offeredAt, closedAt}`. Its `status` is `OPEN`, `ACCEPTED`, `DECLINED`, `WITHDRAWN` or `CLOSED`.

| What happens | Result |
|---|---|
| Accepted | Original seat cancelled, passenger told, driver charged by notice **at that moment**, Reliability recorded. The driver is told |
| Declined | Passenger keeps the seat, no fee, no Reliability. The driver is told. The other person stays on the waiting list |
| Withdrawn | Passenger keeps the seat. A later accept answers `409 SEAT_OFFER_CLOSED` |
| Passenger leaves first | The offer closes (`CLOSED`) |

| Refusal | When |
|---|---|
| `409 REPLACEMENT_ALREADY_OPEN` | This seat already has a live offer |
| `409 REPLACEMENT_NOT_ELIGIBLE` | `fields.reason`: `same_passenger`, `not_waiting_for_this_day`, `needs_more_seats` or `already_offered` |
| `409 REPLACEMENT_CHANGES_OTHERS` | Inside the 12-hour lock, and it would change the trip for others |
| `409 BOOKING_NOT_CANCELLABLE` | The seat isn't confirmed, or the day has started |
| `404 NOT_FOUND` | Not on this commute's waiting list |

**Interim:**
- COM-22 says that after the lock a replacement may change nothing for the others. We allow it only when both of the new passenger's stops are within the arrival radius (150 m) of the old passenger's stops. The accept checks this again.
- The fee shown beforehand can rise by the time someone accepts, because it's charged by notice at that moment. The design should say so.

A person on a recurring waiting list who accepts gets that one day only.

## G10. Times on a proposal (interim)

- **Edit is arrival only, like Create.** Please ask Design to remove earliest departure from `F3-05`. COM-41 covers this for Create; applying it to Edit is our reading.
- **Send only `promisedArrivalTime`.** On apply, the departure is now worked out again from the new arrival. Before this, it stayed where it was.
- **`departureTime` stays on the API, but the app shouldn't send it.** COM-17 and its example still describe a departure change after the lock, and Product hasn't decided whether to remove it. If sent, the time only holds inside the lock; before the lock the next re-time replaces it.
- **Price tiers stay removed.** The price is automatic (COM-41, PAY-42).

## G11. Reliability on the cancel sheets

**There are no levels.** No rule or field defines "Level 3 of 4". The only figure is `asDriver.score` on `GET /account/reliability`, out of `perfectScore` (100).

The preview won't say what a cancellation changes. The score's formula is now decided (SAFE-24), but it has no levels. Please ask Design to remove both the level and the fall. The sheets can still say a cancellation lowers Reliability (SAFE-04).

## G12. Commute list shape

- **Paging:** not paginated, and there's no `meta`. It returns all of the driver's commutes.
- **`?status=published,paused`:** works, and a wrong value answers `422`.
- **Archived:** with a `status` filter, only what you ask for. **With no filter, archived commutes that were once published are included**, so always send the filter.
- **Order:** newest created first.
- **My commutes:** every `PAUSED` commute belongs there, whatever the reason.

There's a seventh `pausedReason`, `gender_review` (COM-45). Please handle it too.

`vehicle_suspended` exists, but no code sets it today, so you won't see it outside the seeded account. Keep the copy for it.

## G13. Accounts

Create them with `php artisan demo:driver-commutes-states`, or reset one with `--state=DC-PASSENGERS`. **They aren't on live yet.** The sign-in code is `123456` on a server with `RAFEEQ_DEV_OTP_CODE=123456`.

| State | Phone | What it has |
|---|---|---|
| `DC-PASSENGERS` | `+201055557501` | A full day inside the lock, a part-full day with 2, one with 1, then empty days |
| `DC-MIXED-LIST` | `+201055557502` | Published, paused and archived commutes |
| `DC-NOBODY-BOOKED` | `+201055557503` | Published, no passengers |
| `DC-PAUSED-BY-DRIVER` | `+201055557511` | `by_driver`, passengers displaced, resume works |
| `DC-PAUSED-VEHICLE` | `+201055557512` | `vehicle_suspended`, resume answers `COMMUTE_VEHICLE_UNAVAILABLE` |
| `DC-PAUSED-LICENCE` | `+201055557513` | `licence_expired`, resume answers `DRIVER_LICENCE_EXPIRED` |
| `DC-PAUSED-COMMISSION` | `+201055557514` | `commission_unpaid`, resume answers `DRIVER_COMMISSION_OVERDUE` |
| `DC-PAUSED-ACCOUNT` | `+201055557515` | `account_suspended`, resume answers `ACCOUNT_SUSPENDED` |
| `DC-PAUSED-DRIVER` | `+201055557516` | `driver_suspended`, resume answers `DRIVER_NOT_ELIGIBLE` |
| `DC-PAUSED-GENDER` | `+201055557517` | `gender_review`, on a Women-only commute |
| `DC-PAST-DAYS` | `+201055557521` | Completed and rated, cancelled by the driver, cancelled by the platform, expired |
| `DC-FEES-NONE` | `+201055557531` | Next day 40 hours away, reason `none` |
| `DC-FEES-MID` | `+201055557532` | Next day 13 hours away, reason `mid_notice` |
| `DC-FEES-SHORT` | `+201055557533` | Next day 5 hours away, reason `short_notice` |
| `DC-TRIP-STARTED` | `+201055557534` | A run under way, for `TRIP_NOT_CANCELLABLE` |
| `DC-PROPOSAL-OPEN` | `+201055557541` | Open, one accepted and two waiting |
| `DC-PROPOSAL-REVISED` | `+201055557542` | Version 2, answers reset |
| `DC-PROPOSAL-REJECTED` | `+201055557543` | Rejected |
| `DC-PROPOSAL-APPLIED` | `+201055557544` | Applied |
| `DC-PROPOSAL-EXPIRED` | `+201055557545` | Expired |
| `DC-ITINERARY` | `+201055557551` | Two passengers, stops interleaved |
| `DC-OVER-LIMIT` | `+201055557552` | Six passengers, over the waypoint limit, with `segments` |
| `DC-WAITLIST` | `+201055557561` | Paused: previous passenger, recurring, one day, in another commute |
| `DC-OFFERS` | `+201055557562` | Offers open, withdrawn, accepted |
| `DC-REPLACE` | `+201055557571` | Two passengers and two waiting, 40 hours away |
| `DC-REPLACE-PENDING` | `+201055557572` | An offer waiting on Rana |
| `DC-REPLACE-LOCKED` | `+201055557573` | Inside the lock: Rana refused, Aya has the same stops |
| `CC-WINDOW-PASSED` | `+201055557412` | A commute whose whole window has passed |
| `CC-NO-CAR` | `+201055557405` | No commutes, for the empty list |

**Passengers**, to accept or answer from the other side:

| Name | Phone | Name | Phone |
|---|---|---|---|
| Salma | `+201055557601` | Layla | `+201055557607` |
| Nour | `+201055557602` | Dina | `+201055557608` |
| Hana | `+201055557603` | Reem | `+201055557609` |
| Mariam | `+201055557604` | Rana | `+201055557610` |
| Yasmin | `+201055557605` | Aya | `+201055557611` |
| Farida | `+201055557606` | | |

Good to know:
- Fees, the lock and started runs depend on the clock. Reset the state just before testing, or the 5-hour day will have moved or expired.
- Accepting a seat can answer the same-time refusal if a shared passenger is booked elsewhere. Rana and Aya are kept for the replace states to avoid this.
- **`etaAt: null` has no capture.** We couldn't produce it on a real day: every stop had a time. Keep handling `null`, because the field allows it.

### Sign-ins

The code is `123456`, on a server that has it set. The limits are **5 code requests per phone and 20 per IP address per hour**, and 10 verifications per code per minute. They can't be turned off per account. `RAFEEQ_RATE_LIMITS=off` turns them off for the whole server, and we'll tell you when that's set.

## Still open from Create Commute

We haven't confirmed these on live, because none of these checks has been run there yet:

| Item | Where it stands |
|---|---|
| Suspended message on publish | In PR #42, merged. Not checked on live |
| `CC-READY` weekdays filled | In PR #42, merged, plus a reseed. Not checked on live |
| `PUT /route` returns updated `weekdays[].departureTime` | In PR #43, merged. Not checked on live |
| Too-far stop answering both `pickups.{index}` and `dropoffs.{index}` | In PR #43, merged. Not checked on live |
| `RAFEEQ_GEO_ENGINE=google` | Not on. We'll tell you |

## Deployed, and what's still needed

| Step | Status |
|---|---|
| This branch merged and deployed | Not yet |
| `php artisan migrate` | Runs on deploy |
| `php artisan demo:driver-commutes-states` | Not yet |
| `RAFEEQ_RATE_LIMITS=off` | Not confirmed |
| Live captures | Not yet; every capture so far is local |
