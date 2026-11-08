# Driver Home: everything mobile needs

> **Replies to:** `driver_home_backend_gaps.md` · **Date:** 2026-11-04 · **Backend branch:** `fix/home-driver-gaps`
>
> **Status:** built and tested on the branch. **Not merged or on staging yet.** The JSON below is based on real responses from a local server, shortened, with a few sample values swapped in.
>
> **Full field reference:** Mobile API Guide §4.4 (verification), §4.11 (Driver Home), §4.14 (notifications).

## 1. What you need to change in the app

| # | Do this | Gap |
|---|---|---|
| 1 | Draw the commute card from `nextRun.phase`. Stop working out the state from time and status. | DH-8 |
| 2 | Read the warning, late and expiry times from `nextRun.startDeadlines`. Never hard-code 5, 10 or 20. | DH-2 |
| 3 | Show `expiredRun` at the top of Home when it isn't `null`. | DH-3 |
| 4 | Use `promisedArrivalLocal` for "arrive by". Drop that part when it is `null`. | DH-4 |
| 5 | Use `startedLocal` for "started 06:52". Remove the extra `GET /trips/{trip}` call. | DH-5 |
| 6 | Use `nextRun.waitlistCount`, `laterThisWeek[].waitlistCount` and `waitlist.count` for the tiles and rows. | DH-6, DH-7 |
| 7 | Show "chose to join your waiting list" only when `rejoinedWaitlist` is `true`. | DH-9 |
| 8 | Use `canStart` and `startOpensLocal` for the Start button. Still handle a refusal on Pre-start, because things can change between the two calls. | DH-10 |
| 9 | Draw the verification card from `verification`. Remove the second call to `GET /driver/verification` on Home. | DH-11 |
| 10 | No Submit button: the application goes to review by itself when the last item arrives (D-0047). A complete application that is still a draft (for example an expired licence) is `in_progress`; call `POST /driver/application/submit` to show the reason. | DH-16 |
| 11 | Use `actionItem` for "replace only this document", including the two car items. | DH-13 |
| 12 | Handle the new notifications and `pausedReason` values in section 5. | COM-26, PROF-27 |

## 2. Behaviour that changed

These can break what you have today, so please check them first.

| Before | Now |
|---|---|
| A run left `nextRun` the second its departure passed. | It stays, on its own local day, until the sweep expires it at +20. `departsInMinutes` goes negative. |
| `overall` read `under_review` as soon as a car was added. | `under_review` only after the driver submits. A complete draft is `in_progress`. |
| Car items read `under_review` before submit. | They read `in_progress` until the application is submitted. |
| A rejected car or application read `under_review`. | It reads `action_required`, with `actionItem`. |
| An expired licence or a driver suspension left upcoming days in place. | Commutes pause and upcoming seats are cancelled with no fee, inside the 16-hour lock too. |
| A passenger who added driving could end up with `["DRIVER"]` only. | They keep `PASSENGER`. Existing accounts are backfilled, so `capabilities` should no longer be empty on `HOME`. |

## 3. `GET /driver/home`

```json
{
  "greetingName": "Mariam",
  "verification": {
    "overall": "verified",
    "completed": 5,
    "of": 5,
    "licenceExpiresAt": "2027-12-12T00:00:00+00:00",
    "actionItem": null,
    "suspensionReason": null
  },
  "expiredRun": null,
  "nextRun": {
    "commuteId": "01m6…",
    "tripId": "01m6…",
    "groupId": null,
    "status": "SCHEDULED",
    "phase": "scheduled",
    "originLabel": "Rehab Gate 2",
    "destinationLabel": "Smart Village B6",
    "departureAt": "2026-11-04T06:40:00+00:00",
    "departureLocal": "2026-11-04 08:40:00",
    "departsInMinutes": 130,
    "isToday": true,
    "promisedArrivalAt": "2026-11-04T07:50:00+00:00",
    "promisedArrivalLocal": "2026-11-04 09:50:00",
    "startedAt": null,
    "startedLocal": null,
    "canStart": false,
    "startOpensAt": "2026-11-04T05:10:00+00:00",
    "startOpensLocal": "2026-11-04 07:10:00",
    "startDeadlines": {
      "warningAt": "2026-11-04T06:45:00+00:00",
      "warningLocal": "2026-11-04 08:45:00",
      "lateAt": "2026-11-04T06:50:00+00:00",
      "lateLocal": "2026-11-04 08:50:00",
      "expiresAt": "2026-11-04T07:00:00+00:00",
      "expiresLocal": "2026-11-04 09:00:00"
    },
    "leaveBy": { "at": "2026-11-04T06:11:00+00:00", "local": "2026-11-04 08:11:00", "lateWarning": true },
    "attendance": { "coming": 0, "away": 0, "awaiting": 0, "total": 0 },
    "seatsTotal": 3,
    "seatsTaken": 1,
    "seatsOpen": 2,
    "waitlistCount": 2,
    "collectPiastres": 12500,
    "keepPiastres": 11551,
    "passengers": []
  },
  "laterThisWeek": [
    {
      "commuteId": "01m6…",
      "tripId": "01m6…",
      "originLabel": "Rehab Gate 2",
      "destinationLabel": "Smart Village B6",
      "departureAt": "2026-11-05T05:00:00+00:00",
      "departureLocal": "2026-11-05 07:00:00",
      "seatsTotal": 3,
      "seatsTaken": 0,
      "seatsOpen": 3,
      "waitlistCount": 1
    }
  ],
  "notices": [],
  "pendingRequests": { "count": 0, "items": [] },
  "waitlist": { "count": 2 },
  "stats": { "onTimeRate": 95, "completedTrips": 40, "avgDetourMinutes": 0 }
}
```

An account with no driver profile gets the same keys, empty: `nextRun` and `expiredRun` are `null`, and `waitlist.count` is `0`. `verification` is `null` unless the account holds the driver capability.

### `nextRun.phase`

| `phase` | State | When |
|---|---|---|
| `scheduled` | `F3D-01` | Not started, more than 30 minutes before departure |
| `starting_soon` | `F3D-02` | Not started, within 30 minutes of departure |
| `in_progress` | `F3D-03` | `PREPARING`, `EN_ROUTE` or `IN_PROGRESS` |
| `not_started` | `F3D-04` | Past departure, before `lateAt` |
| `late` | `F3D-05` | At or past `lateAt`, before it expires |

### New `nextRun` fields

| Field | Meaning | `null` when |
|---|---|---|
| `promisedArrivalAt` / `Local` | The day's promise, per-day overrides included | None was promised |
| `startedAt` / `Local` | When the driver tapped start | Not started |
| `canStart` | The same checks `POST /trips/{trip}/start` runs, plus a suspended account | Never: always a bool |
| `startOpensAt` / `Local` | When the start window opens, 90 minutes before departure today | Already started |
| `startDeadlines` | The three times, from server settings | Started, or more than 3 hours before departure |
| `waitlistCount` | People who could take **this date**: a recurring member whose days include it, or a one-day request for this trip. Leaves out anyone paused or away that day. | Never: `0` when nobody is waiting |

`waitlist.count` is distinct people across all this driver's commutes, so one person waiting on two days counts once.

`pausedCommutes` is how many of this driver's commutes are paused. `nextRun` can be `null` while this is above 0, and `pendingRequests`, `waitlist` and `notices` can still have content, so show them when they do.

### `expiredRun`

The run that expired today. It goes away by itself at the end of that local day, and there is nothing to call.

```json
{
  "tripId": "01m6…",
  "commuteId": "01m6…",
  "originLabel": "Rehab Gate 2",
  "destinationLabel": "Smart Village B6",
  "departureAt": "2026-11-04T04:00:00+00:00",
  "departureLocal": "2026-11-04 06:00:00",
  "expiredAt": "2026-11-04T04:20:22+00:00",
  "expiredLocal": "2026-11-04 06:20:22"
}
```

### `notices[]`

`rejoinedWaitlist` is new. It is always `false` on `seat_opened`.

```json
{
  "kind": "passenger_switched",
  "rejoinedWaitlist": true,
  "bookingId": "01m6…",
  "tripId": "01m6…",
  "commuteId": "01m6…",
  "person": { "publicFirstName": "Salma" },
  "seatsReleased": 1,
  "seatsOpen": 3,
  "departureAt": "2026-11-04T06:40:00+00:00",
  "departureLocal": "2026-11-04 08:40:00",
  "at": "2026-11-04T04:30:22+00:00"
}
```

## 4. Verification

`verification` on Home is `GET /driver/verification` without `items`. A suspended account can read both, because reads still pass; only a permanent ban is refused.

```json
{
  "overall": "action_required",
  "completed": 3,
  "of": 5,
  "licenceExpiresAt": null,
  "actionItem": {
    "key": "driving_licence",
    "status": "action_needed",
    "rejectionReason": "The licence photo is too blurry to read. Please upload it again."
  },
  "suspensionReason": null
}
```

### `overall`

| `overall` | Meaning |
|---|---|
| `not_started` | Nothing done yet |
| `in_progress` | Still filling it in, **including a complete draft nobody is reviewing yet** |
| `under_review` | Submitted, with a reviewer |
| `action_required` | One item needs replacing, or the application was rejected |
| `verified` | Approved |
| `expiring` | Licence expires soon. Still verified, still allowed to drive. |
| `renewal_required` | A document expired. `actionItem.key` says which. |
| `suspended` | Driver access suspended. Passenger mode still works. |

### `actionItem`

| Field | Values |
|---|---|
| `key` | `government_id` · `selfie` · `driving_licence` · `vehicle_registration` · `vehicle_details`, or `application` when the reviewer rejected the whole application |
| `status` | `action_needed` or `expired` |
| `rejectionReason` | The reviewer's own words. Show it as its own sentence, not inside yours. |

`actionItem` is `null` when nothing is waiting, and for a suspended driver. A suspended driver gets `suspensionReason` instead. Both reasons are written by staff, so **they arrive in the language staff wrote them in**, not the app's language.

### A rejected car paper

A reviewer can now reject the registration or the car details alone. That item reads `action_needed`, the other car item reads `in_progress`, and the application goes to `REJECTED` so it can be edited.

| Item | How the driver fixes it |
|---|---|
| `vehicle_registration` | `POST /driver/vehicles/{vehicle}/documents` with any registration type |
| `vehicle_details` | `PATCH /driver/vehicles/{vehicle}` with a change. A save that changes nothing doesn't count. |

That call clears the reason, and the application goes back to review by itself (D-0047). Until the item is fixed, submit answers `422 DRIVER_APPLICATION_INCOMPLETE` with the item's key in `missing`.

### Licence date

`licenceExpiresAt` is `null` until the licence is approved. After that it is the reviewer's date or, if they gave none, the date the driver typed. This is what makes `expiring` and `renewal_required` fire.

## 5. Expired licences and suspensions

| | Expired licence | Driver suspended |
|---|---|---|
| **Trigger** | A daily job at 00:10 UTC | Staff, from the dashboard |
| **`overall`** | `renewal_required` | `suspended` |
| **Application `status`** | `EXPIRED_DOCUMENTS` | `SUSPENDED` |
| **`pausedReason`** | `licence_expired` | `driver_suspended` (new value) |
| **Notification** | `driver_licence_expired` with `itemKey`, `expiresAt` | `driver_suspended` with `driverProfileId` |
| **Upcoming seats** | Cancelled, no fee | Cancelled, no fee |

For an expired licence, the driver resends the licence; it goes to review by itself (D-0047). Resume answers `422 DRIVER_LICENCE_EXPIRED` until a reviewer approves it.

`driver_item_rejected` can now also carry `itemKey: vehicle_registration` or `vehicle_details`.

## 6. Answers to your questions

| Question | Answer |
|---|---|
| DH-2: free exit at +5 or +10? | **+5** (D-0020, TRIP-03). +10 records the violation. The +10 title is copy; please reword it. |
| DH-3: how long does `expiredRun` stay? | **Until the end of that local day** (D-0032). |
| DH-3: how does History read an expired day? | `GET /driver/bookings`: the booking's `trip.status` is `EXPIRED`. A day nobody booked has no booking, so it isn't listed. |
| DH-4: an estimate when no arrival was promised? | **No.** The field is `null` and the app drops "arrive by". |
| DH-5: `status` while the car is at a pickup? | **`EN_ROUTE`.** |
| DH-7: one person on two days? | **Counts once.** |
| DH-8: the soon window? | **30 minutes**, a server setting (D-0032). Use `phase`. |
| DH-9: is a switch also a `seat_opened`? | **No, one event and one notice** (D-0032). Draw both cards from it with its date if the design needs both. The design's Sun and Tue cards are two separate events: Tue is its own `seat_opened`. |
| DH-9: are seats offered automatically? | **Yes** (D-0019, BOOK-20). §8 question 9 was out of date and is corrected. |
| DH-11: a whole account suspended? | The `user.suspension` banner with Appeal (D-0018). `GET /driver/home` still answers and `canStart` is `false`. `verification.overall` describes the driver profile, so it may still read `verified`. |
| DH-12: suspension reason in the request's language? | **No.** It is the sentence staff wrote. Show the fixed sentence as well if the language matters. |
| DH-13: `rejectionReason`, fragment or sentence? | **The reviewer's own words.** Show it as its own sentence. |
| DH-13: `renewal_required` for a car paper? | `actionItem.key` says which item expired. The copy for the car paper is still needed from design. |
| DH-14: a short place name for Later rows? | **Not added.** Use the full label with an ellipsis. |
| DH-15: old data or a bug? | **Both.** Older and seeded accounts never got capability rows, and these are backfilled. Adding driving also didn't restore a missing `PASSENGER`, which is fixed. A driver-only sign-up stays `["DRIVER"]` and uses "Become a Passenger" (PROF-06). |
| DH-15: ID and selfie counted without the driver capability? | **Intended.** They are the same documents on the same account. |
| DH-16: automatic submit? | **Yes, since D-0047.** The application goes to review when the last item arrives. |
| DH-17: does the admin always send a date? | **No, it never did.** The typed date is now copied at approval, and existing approved licences are backfilled. |
| Can a driver who can't drive still have runs? | **Those days get cancelled** (section 5). Until the daily job runs, `nextRun` may still show a run for an expired licence, so keep ignoring it in `suspended` and `renewal_required`. `canStart` is `false` there. Days of a paused commute no longer appear on `nextRun` or `laterThisWeek`. |
| `Level X of 5`? | **Show the API's number.** It counts approved items only. |

## 7. Guide corrections

All four are fixed in the guide.

| Where | Now says |
|---|---|
| §4.11 `nextRun` | The soonest unfinished run on **any** day |
| §4.11 `nextRun` | Lists `commuteId`, `tripId`, `groupId` |
| §4.4 `overall` | Includes `action_required` |
| §8 question 9 | Seats are offered automatically |

## 8. Still open

| Item | Owner |
|---|---|
| D-0032 (the soon window, the expired note, one notice per switch) still needs the other partners' approval, so these three could change. | Product |
| DH-10: at T−2 h 10, is Start disabled, or active with the window explained on tap? The API supports both. Until decided, follow the design and show the server's refusal. | Product |
| "No commutes yet" on a suspended driver with paused commutes. | Product |
| The copy for an expired car registration. | Design |

## 9. Test accounts

Every state, its phone number and its real responses are in [FLUTTER_DRIVER_HOME_RESPONSES.md](FLUTTER_DRIVER_HOME_RESPONSES.md).

Reset every account, or one account when a late state has run out:

```bash
php artisan demo:driver-home-states
```

```bash
php artisan demo:driver-home-states --state=F3D-05 --phone=+201055557005
```

## 10. Does the app submit the licence?

**No. Don't call `POST /account/verifications/driving_licence/submit`.** PROF-16 has one submit, so the backend handles it:

- `POST /driver/application/submit` also sends the licence to review.
- Approving the driver approves the licence with the typed date, so `licenceExpiresAt`, `expiring` and `renewal_required` work.
- If a reviewer sent the licence back, the driver can't be approved until it is replaced and resubmitted.
