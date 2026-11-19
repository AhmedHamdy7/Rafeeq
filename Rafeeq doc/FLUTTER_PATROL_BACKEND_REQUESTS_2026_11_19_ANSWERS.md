# Answers: backend requests from the Patrol run (2026-11-19)

Answers to `backend-requests-2026-11-19.md`. Everything below reaches the test server with the next deploy. Guide sections: 4.7 (seat requests and offers), 4.9 (live trip), 4.11 (Home), 4.17 (testing calls).

## Part 1: server fixes

| # | Answer |
|---|---|
| S1 | ✅ **Fixed.** `nextJourney.live` is filled from the same itinerary as `GET /trips/{trip}/location`, and `driverDelay: "passed_your_stop"` now also comes from the car's GPS |
| S2 | ✅ **Fixed.** A rejected proposal no longer blocks a new one |
| S3 | ✅ **Fixed.** `lastSearchedAt` is set when a run matches the saved search's own filters |
| S4 | ✅ **Fixed.** New `endedAt` and `updatedAt` on every seat request. The age call moves them |
| S5 | ✅ **Already sent.** `409 SEAT_OFFER_CLOSED` with `error.fields.status: ["taken_by_other"]`, a one-item list |
| S6 | ✅ **Fixed.** "You must be at least 18 years old to use YuGo." Every member-facing text that said Rafeeq now says YuGo |
| S7 | ✅ **The server switches.** Send `switchFromBookingId` on accept. Don't cancel the other seat first |
| S8 | ✅ **Fixed: the group stays open for the mornings nobody took.** This was a bug, not a rule |
| S9 | ✅ **Use `pausedCommuteReasons`, not the verification card.** No change |
| S10 | ✅ **From the later of the GPS arrival and her agreed pickup time.** New `graceStartsAt` and `graceEndsAt` for the passenger |

### S1 · Home live fields and "passed your stop"

**Cause:** Home read the stops straight from the database. Until something had built the day's itinerary there were none, so every `live` field was `null`. The location call builds the itinerary first.

**Now:** once the run has started, Home uses the same itinerary as the location call. So `pickupEtaAt`, `progressPercent` and `stopsBeforeYou` match what `GET /trips/{trip}/location` answers.

**"Passed your stop" from GPS.** Before, `driverDelay: "passed_your_stop"` needed a later stop on the route to be marked reached. Now it is also set when both of these are true:

- The car's last position is on the day's route, within 150 m of it.
- The car is at least **300 m further along that route** than her pickup.

It needs no arrival at her stop and no check-in. On Home, `phase` is `driver_delayed` with `canLeaveWithoutFee: true`, and `live` stays filled. The same `driverDelay` is on `GET /bookings/{booking}`. `POST /bookings/{booking}/exit-without-fee` accepts it, with `cancelledReason: "passed_your_stop"`.

### S2 · A rejected proposal no longer blocks the next one

`POST /driver/commutes/{commute}/change-proposals` now answers `409 CHANGE_PROPOSAL_ALREADY_OPEN` only while a proposal is **`PENDING`**.

If a `REJECTED` one exists, sending a new proposal closes it as `WITHDRAWN` in the same call. Revising the rejected one (`PATCH`) still works as before.

### S3 · `lastSearchedAt` on saved searches

**Cause:** a saved search keeps only these filters:

- `origin`, `destination`
- `daysMask`
- `arrivalWindowStart`, `arrivalWindowEnd`
- `maxWalkMinutes`, `maxDetourMinutes`
- `minRating`

A run that also set `seatsNeeded`, `flexibilityMinutes`, `womenOnly`, `rules` or `tier` (or came from a recent search that carried them) didn't count as the same search.

**Now:** a run matches a saved search when those saved filters are equal. Coordinates are compared to 3 decimals. Filters a saved search doesn't keep are ignored.

Only point searches are remembered. A search by area (`areaPlaceId`) still never sets `lastSearchedAt`.

### S4 · When a seat request ended

`GET /seat-requests` and `GET /seat-requests/{seatRequest}` gain:

| Field | Meaning |
|---|---|
| `endedAt` | When the request ended: the driver's answer (`respondedAt`) if there was one, otherwise when it closed. `null` while it is `PENDING`, `APPROVED`, `WAITLISTED` or `COUNTEROFFERED`. |
| `updatedAt` | Last change to the request. |

Use `endedAt` for "Ended · shown for 7 days". It is set for `COMMUTE_ARCHIVED`, `CLOSED_DATE_PASSED`, `TERMS_CHANGED` and the other ended statuses.

`POST /testing/seat-requests/{id}/age {days}` moves it back, and its answer now includes `endedAt`.

### S5 · "Someone was quicker"

Nothing to change on the server. The refusal is already:

```json
{ "error": { "code": "SEAT_OFFER_CLOSED", "fields": { "status": ["taken_by_other"] } } }
```

Like every field error, `fields.status` is a **list**. Read `fields.status[0]`. `"taken_by_other"` → "Someone was quicker. This seat has been taken." Any other value (`closed`, `withdrawn`, `ineligible`) → "no longer available". Tested.

### S6 · YuGo in the under-age refusal

`PUT /account/profile/basic` now answers `fields.dateOfBirth: ["You must be at least 18 years old to use YuGo."]`. The Arabic reads "…لاستخدام YuGo." The age comes from the `profile.minimum_age_years` setting.

These member-facing texts also changed from Rafeeq / رفيق to YuGo, in both languages:

- the OTP SMS
- the auto-share SMS
- the driver eligibility blockers
- `NO_RECENT_LOCATION` and `ACCOUNT_HAS_DEBT`
- the "Someone from YuGo is on it" notification
- the seat price note

The staff dashboard is unchanged.

### S7 · An offer for a morning she already has a seat

**Rule:** BOOK-34 / BOOK-24 / BOOK-27. Accepting an overlapping offer **is the switch**. It happens in one step, so she never holds two seats and never zero.

**The app should not cancel the other seat first.** Send:

```json
POST /seat-offers/{offer}/accept
{
  "switchFromBookingId": "<conflictingBookingId from GET /seat-offers>",
  "switchReason": "voluntary",
  "switchScope": "this_date",
  "joinOldWaitlist": false
}
```

- `switchReason`: `voluntary` (normal cancellation fees, PAY-21) or `driver_delay` (no fee, only when her old driver is late).
- `switchScope`: `this_date` or `all_future_weekdays`. With `all_future_weekdays`, `meta.releasedDates` lists the other mornings she gave up.
- `joinOldWaitlist`: puts her back on the old driver's list (BOOK-26).

**Without `switchFromBookingId`:** the answer stays `409 BOOKING_ALREADY_BOOKED` with `fields.overlapsBookingId`, and the offer stays open. That is the cue for the "Accept & switch" dialog.

### S8 · A grouped offer, one morning taken by someone else

**Rule:** BOOK-16 and BOOK-21. Each morning is its own seat, and the first acceptance wins that seat only. The group **stays open for the mornings nobody took**.

There were two bugs, both fixed:

1. **A "send to all" batch held only one morning per member.** A database key allowed one offer per person per batch, so the other mornings were silently dropped. When someone took that one morning, her whole "group" was closed. Now she gets one offer per morning, all with the same `offerGroupId`.
2. **Accepting a group booked only its first morning.** The rest answered `ineligible`. Now every open morning in the group is booked.

Also fixed: when someone accepts, other members' offers for that morning close only if the car is now full. Before, they closed even with seats left.

After someone takes Monday, `POST /seat-offers/groups/{group}/accept` books her other mornings. It answers `200` with the bookings, and `meta.offers[]` has one row per morning:

```json
{ "offerId": "01…", "status": "ACCEPTED" | "TAKEN_BY_OTHER" | …, "bookingId": "01…" | null, "error": null | "SEAT_OFFER_CLOSED" }
```

Count the `ACCEPTED` rows for "You got 1 of 2 days". `409 SEAT_OFFER_CLOSED` (with `fields.offers`) only comes when **no** morning could be booked.

### S9 · Driver Home with a suspended car

**Rule:** COM-46, PROF-33 and PROF-36. The verification card (PROF-22) is hidden once the driver is verified, and she still is. The car's suspension is a **paused-commute message**.

`GET /driver/home` already has what you need: `pausedCommuteReasons` contains `"vehicle_suspended"`. Show the COM-46 message in its order (account suspended, commission, licence, vehicle, paused by the driver). Every message says "Cancelled bookings don't come back automatically".

Which button to show:

- **No renewal sent yet** (`vehicle_registration.status` is not `under_review`): **Upload registration**, or **Support**.
- **Renewal under review** (`vehicle_registration.status: "under_review"`, and the vehicle's `registrationRenewalSubmittedAt` is set): "We're reviewing your new car registration. We'll tell you as soon as it's approved." (PROF-33). No upload button.

`overall: verified` and `actionItem: null` are correct here. In the checklist, the car items read `suspended` or `under_review` (PROF-36).

### S10 · The passenger's 5-minute grace

**Rule:** TRIP-11. Grace is 5 minutes from the **later** of the driver's GPS arrival and her agreed pickup time.

The server already counted it this way, but the passenger had no field for it (the wait timer is driver-only). New, for her:

| Where | Fields |
|---|---|
| `GET /home` → `nextJourney.live` | `graceStartsAt`, `graceEndsAt`. Set in `driver_arrived` only, `null` in every other phase. |
| `GET /trips/{trip}/location` | `graceStartsAt`, `graceEndsAt`. Set while the driver has arrived for her and no outcome is recorded yet. Otherwise `null`. |

- **Count down to `graceEndsAt`.** It is the same moment as the driver's `expiresAt`.
- Before `graceStartsAt` (the driver came early), show "Driver arrived" with no countdown.
- When `graceEndsAt` is in the past, show "Grace ended".
- `POST /testing/trips/{trip}/wait-timers/expire` moves `graceEndsAt` into the past, so her countdown ends too.

## Part 2: testing calls

Full bodies and answers are in guide 4.17.

| # | Answer |
|---|---|
| T1 | ✅ **Fixed.** New `kind`s on `debt/seed`; `credits` takes any `reason` text |
| T2 | ✅ **Fixed.** `paymentStatus: "waived"` works, plus `feeLinePiastres` / `feeLineReason` |
| T3 | ✅ **Fixed.** New endings for canceled by the driver, exited (driver late) and never started |
| T4 | ✅ **Fixed.** Reject works on an approved, active car |
| T5 | ✅ **Fixed.** `/auth/me`, faults by phone number, and `fields` |
| T6 | ✅ **New** `POST /testing/change-proposals/{proposal}/expire`. Also fixed: the server never expired overdue proposals |
| T7 | ⛔ **Not built.** A pickup-change request has no expired state, and no rule says it expires |
| T8 | ✅ **New** `POST /testing/seat-requests/{seatRequest}/close` |
| T9 | ✅ **New** `POST /testing/trips/{trip}/cancel-by-platform` |
| T10 | ⛔ **Not built.** There is no "on hold" state apart from a suspension |
| T11 | ✅ **New** `POST /testing/driver/application/state` |
| T12 | ✅ **New** `POST` / `DELETE /testing/driver/blocker` |
| T13 | ✅ **Fixed.** `skipEvidence: true` on the appeal decide call |
| T14 | ✅ **New** `POST /testing/payments/instapay` |
| T15 | ✅ **Use `/testing/faults`** with a `*` path segment. No new call |

### T1 · Debt lines and credits

`POST /testing/debt/seed`

```json
{ "side": "driver" | "passenger", "kind": "…", "amountPiastres": 2750, "count": 1,
  "bookingId": "01…", "disputed": false, "direction": "increase" | "decrease", "reasonCode": "…" }
```

| `kind` | Writes | Default reason code |
|---|---|---|
| `commission`, `no_show_fee`, `late_cancel_fee`, `late_fee` | As before | As before |
| `fee` (new) | A fee | driver `driver_cancel`, passenger `no_show` |
| `coupon` (new) | A negative line | `coupon` |
| `credit` (new) | A negative line | `staff_credit` |
| `payment` (new) | A negative line ("payment received") | `instapay_payment` |
| `adjustment` (new) | Adds; `direction: "decrease"` takes off | `staff_adjustment` |
| `write_off` (new) | A negative line. Leave out `amountPiastres` to forgive all she owes on that side | `staff_write_off` |

- `reasonCode` overrides the code. `amountPiastres` is 1 to 500000.
- `disputed: true` on a line that takes debt down answers `422`.
- The answer is unchanged: `201 {lineIds, side, owedPiastres, balancePiastres, …}`.
- ⚠️ These write the statement line only. No `payments[]` or `coupons[]` row appears on `GET /account/debt`.

`POST /testing/credits {amountPiastres, reason}`

- `amountPiastres` is from 1. `reason` is any text up to 255 characters.
- If `reason` isn't a known code, the line's code is `staff_credit` and your text is kept as a note.
- Answers `201 {creditId, side, reasonCode, creditPiastres, balancePiastres}`.
- A passenger credit lands on her next upcoming ride as `feeLinePiastres < 0` with `feeLineReason: "credit"`. That is the Trips card total.

### T2 · Booking fields

`POST /testing/bookings/{booking}/fields`:

- `paymentStatus` takes any case, so `"waived"` works. It reads back as `WAIVED`.
- New `feeLinePiastres` (−500000 to 500000) and `feeLineReason`. They work on any of her bookings, past ones too. Use them to put a credit or a fee on a History row.
- The answer is unchanged: the booking as `GET /bookings/{booking}` returns it.

### T3 · Past bookings with the missing endings

`POST /testing/seed/past-bookings {count, ending}`. `ending` takes any case and spelling ("canceled by the driver", "exited (driver late)", "never started" all work).

| `ending` | Booking | `cancelledReason` | The day |
|---|---|---|---|
| `CANCELLED_BY_DRIVER` | `CANCELLED_BY_DRIVER` | `removed_by_driver` | completed |
| `DRIVER_CANCELLED_DAY` (new) | `CANCELLED_BY_DRIVER` | `day_cancelled` | cancelled by the driver |
| `EXITED_DUE_TO_DRIVER_DELAY` | `EXITED_DUE_TO_DRIVER_DELAY`, payment `WAIVED` | `not_started` | completed |
| `NEVER_STARTED` (new) | `EXITED_DUE_TO_DRIVER_DELAY`, payment `WAIVED`, attendance `driver_no_show` | `trip_expired` | `EXPIRED` |

- `NEVER_STARTED` also answers to `TRIP_EXPIRED` and `DRIVER_NO_SHOW`.
- `EXITED_DUE_TO_DRIVER_DELAY` also answers to `EXITED_DRIVER_LATE` and `DRIVER_LATE`.
- Also fixed: both exit endings now have `cancelledAt` and a cancel code, so the past-trip note can render (finding 5).
- Without `ending` it still cycles the first seven.

### T4 · Reject an approved car

`POST /testing/driver/vehicles/{vehicle}/reject {item?: "vehicle_details" | "vehicle_registration", reason?}` now works on an approved, active car.

Afterwards:

- The car is `REJECTED` on `GET /driver/vehicles`.
- `GET /driver/verification` reads `overall: "action_required"`, with `actionItem.key` set to that item and its `rejectionReason`.

⚠️ The testing API can't approve that car again (approve needs a pending car). Run `POST /testing/reset`, or upload the item again.

### T5 · Faults

`POST /testing/faults` changes:

- **(a)** `/auth/me` can be matched, by the caller's token. The OTP request route already could be.
- **(c)** Optional `fields` object, in error mode only. Each value is a string or a list. `{"phone": "x"}` comes back as `error.fields.phone: ["x"]`.
- **Wildcards:** a `*` path segment matches any one segment.

**(b) A signed-out device.** New, with no token:

- `POST /testing/faults/by-phone`: the same body plus `phone`.
- `GET /testing/faults/by-phone?phone=`: the open rules.
- `DELETE /testing/faults/by-phone?phone=`: answers `{cleared}`.

How a by-phone rule matches:

- requests that carry that `phone` in the body;
- the OTP verify call for that number (by `challengeId`);
- any token of that number's account, so it keeps working after sign-in.

Test numbers only. Any other number answers `403 TEST_NUMBER_ONLY`.

For "already published" or "account on hold" publish refusals, use the `error` mode with the real `code` and a `fields` object.

### T6 · Expire a change proposal

`POST /testing/change-proposals/{proposal}/expire`, no body.

- Answers the proposal with `status: "EXPIRED"` and `expiresAt` set to now.
- Who: the commute's driver, or a passenger with a booking on it. Anyone else gets `404`.
- A proposal that isn't `PENDING` or `REJECTED` answers `409 CHANGE_PROPOSAL_NOT_LIVE`.

🔴 **Server bug found and fixed.** Nothing ever ran the expiry, so a proposal stayed `PENDING` after its morning. The every-minute job now expires them at the original departure (COM-17). You get the same `EXPIRED` without the testing call.

### T7 · Pickup-change expire: not built

A pickup-change request has no expired status. Its statuses are `PENDING`, `APPROVED`, `SUGGESTED_ALTERNATIVE`, `REJECTED` and `ALTERNATIVE_DECLINED`. Nothing on the server expires one, and the business rules (BOOK-37) don't say it should.

The driver's "Expired" pickup-change card has no state behind it. Please take it to the product owner. Until there is a rule, don't show that card.

### T8 · Close a one-day request as if its day passed

`POST /testing/seat-requests/{seatRequest}/close {status: "CLOSED_DATE_PASSED"}`

- Answers `{id, status: "CLOSED_DATE_PASSED", scheduledTripId}`.
- Who: the passenger or the commute's driver.
- It uses the same step as the nightly job.
- Only an open one-day request (a trial, or a waiting-list place for one day). An answered request answers `409 SEAT_REQUEST_NOT_PENDING`; a recurring one `422`.

### T9 · Cancel a run as staff

`POST /testing/trips/{trip}/cancel-by-platform {reason}` (required, up to 120 characters).

- Answers `{id, status: "CANCELLED", cancelledBy: "PLATFORM", cancelledReason, bookingsCancelled}`.
- Who: the run's driver, or a passenger with a booking on it.
- A run that has started answers `409 TRIP_NOT_CANCELLABLE`.

What the screens read:

| Who | Where | Value |
|---|---|---|
| Driver | `GET /driver/trips?scope=past` row | `cancelledBy: "PLATFORM"` |
| Passenger | her booking | `status: "CANCELLED_BY_DRIVER"`, `cancelledReason: "commute_stopped"` |

**Show `commute_stopped` as "Canceled by YuGo".** It is the existing code for a stop the driver didn't choose.

The driver pays no fee and gets no reliability event. ⚠️ Passengers get no push or inbox message from this call yet: the only text for a cancelled day says the driver cancelled, which would be false.

### T10 · Account hold: not built

The backend has no "on hold for review" state apart from a suspension. Under AUTH-19 (D-0018, D-0048):

- A **suspension** shows a banner with **Pay** and **Appeal**.
- The full **Restricted** screen, with a reference, is only for a **permanent ban**.

Both already have testing calls:

- `POST /testing/driver/suspend {scope: "account", reason?}`: temporary, with a review time and a case number. Commutes pause with `account_suspended`.
- `POST /testing/account/ban`.

There is no paused reason "account under review". A Restricted screen with "Within N h" for something that isn't a suspension doesn't match today's rule. Please check that design with the product owner before we build anything.

### T11 · Put the driver application in any state

`POST /testing/driver/application/state {state, count?, item?, reason?}` answers exactly what `GET /driver/verification` returns.

It wipes the caller's ID, selfie, licence, cars and documents, then rebuilds them through the real actions. A caller who already has commutes gets `409 CONFLICT`.

| `state` | Result |
|---|---|
| `not_started` | `overall: "not_started"` |
| `in_progress` | `count` items filled (1–4, default 2), in this order: ID, selfie, licence, car details, registration. `completed` counts approved items only, so it is at most 2 here. |
| `ready_to_submit` | Everything in, not sent. `overall` stays `in_progress` (PROF-15 has no "ready" status). |
| `submitted` / `under_review` | The same state: `overall: "under_review"` |
| `action_required` | `item`: `government_id`, `selfie`, `driving_licence`, `vehicle_registration`, `vehicle_details` or `application` (default) |

⚠️ Staff must give a reason in the real flow, so a reason is always set. If `reason` is left out, a default is used. "Action required without a reason" can't happen on the server, so the app doesn't need that state.

### T12 · Driver eligibility blockers

`POST /testing/driver/blocker {kind}` answers what `GET /driver/eligibility` returns. `DELETE /testing/driver/blocker` removes it and restores her date of birth.

| `kind` | What it does | Blocker |
|---|---|---|
| `under_age` | Sets her date of birth one month short of the minimum age | `below_minimum_age` |
| `generic` | A testing flag | `not_eligible`, `nextStep: "CONTACT_SUPPORT"` |

"You do not meet the minimum age to drive with YuGo" is app copy. The server's `message` says "You need to be older to drive with YuGo."

### T13 · A second appeal without a new file

`POST /testing/reliability/appeals/{appeal}/decide {decision: "declined", skipEvidence: true}`

- It attaches a placeholder photo to the event (and its group), so `appealRefusal` is `null` and she can appeal again at once.
- Declining that second appeal gives `appealsLeft: 0` and `appealRefusal: "NO_APPEALS_LEFT"`.

### T14 · Hide YuGo's InstaPay account

`POST /testing/payments/instapay {enabled: false}` answers `{enabled}`.

- Only the caller's `GET /account/debt` then has `instapay: null` → "Contact support for the account".
- `{enabled: true}` and `POST /testing/reset` put it back.

### T15 · Failing the photo upload after a report

No new call:

```json
POST /testing/faults
{ "path": "/incidents/*/evidence", "method": "POST", "mode": "error", "status": 500 }
```

Her next evidence upload, on any report, fails with the `X-Testing-Fault` header. Then show "Your report was sent, but the photo didn't upload".

## Waiting on the product owner

- **T7:** should a pickup-change request expire, and when?
- **T10:** is there an "on hold for review" state apart from a suspension? Today's rule is a banner for a suspension and the Restricted screen for a ban only.
- **T9:** what should a passenger be told when staff cancel her day?
