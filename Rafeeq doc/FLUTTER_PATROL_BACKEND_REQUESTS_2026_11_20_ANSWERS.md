# Answers: backend requests after the Patrol gap pass (2026-11-20)

Answers to `backend-requests-2026-11-20.md`. Everything below reaches the test server with the next deploy. Guide sections: 4.4 (driver eligibility), 4.7 (change proposals and seat offers), 4.11 (Home), 4.12 (live shares), 4.17 (testing calls). The guide's change log has a 2026-11-20 row.

Each section says what you asked, what we found, and what the app should do now.

## Part 1: server behaviour

| # | Answer |
|---|---|
| B1 | ✅ **Fixed.** An edit refuses a pickup more than 3 km from the current route |
| B2 | ✅ **Fixed.** The car switch checks the seats each day really offers |
| B3 | ✅ **New `conflictingSeatRequestId`.** One correction to the copy: the request is not moved to the waiting list, it becomes a request to join it |
| B4 | ✅ **Both.** `nextJourney.tripSessionId` on Home, and `?tripId=` on `GET /safety/live-shares` |
| B5 | ✅ **On purpose.** She may switch. Keep the flag |
| B6 | ✅ **Both are reachable**, under conditions the test didn't set up. Keep the lines |
| B7 | ✅ **5 minutes without a new point.** `AGREED_PICKUP_UNVERIFIED` is rare but possible. Keep the line |
| B8 | ✅ **Objects.** The guide was wrong and is fixed. The code is `below_minimum_age` |
| B9 | ✅ **No.** `expiring` always comes with `licenceExpiresAt`. Drop the no-date message |
| B10 | ✅ **No, for the passenger.** A `NO_SHOW` booking always has `noShow`. Drop the note |
| B11 | ✅ **Yes, it sends a push.** New `meta` on the call and a new `GET /testing/push-log` to check delivery |

### B1 · An edit refuses a pickup far off the road

**Cause:** an edit checked only the detour minutes, against a straight origin → destination route. A pickup 15 km off could still pass that check.

**Now:** `POST /driver/commutes/{commute}/change-proposals` and `PATCH /driver/change-proposals/{proposal}` check each pickup against the route the commute drives today. This includes a change applied at once because nobody is booked. A pickup more than **3 km** from that route answers:

- `422 COMMUTE_ROUTE_INVALID`
- `error.fields["pickups.{index}"]`: "That pickup point is more than 3 km from your route. Move it closer to your route." (Arabic too)

The index is the point's place in `pickupPoints`. The detour-minutes check still runs on points that pass the 3 km check, with the same field key. Your "This change can't be saved" screen works as it is.

**Drafts did not change.** `PUT /commutes/{commute}/route` still checks detour minutes only. Your Create picker's "Too far from your route" is the app's own check, so drafts already behave like edits in the app. The 3 km figure is the passenger-point limit from D-0042. The business repo doesn't say whether it also applies to a driver's suggested points. So this is **interim** until product confirms. If they want the server to refuse at 3 km on drafts too, it's a small change.

### B2 · Switching car with per-day seats

**Cause:** the check always counted the commute's default `seatsTotal`, even when every day had its own seats.

**Now:** the default counts only when at least one weekday has no seats of its own. Every per-day figure is still checked. In your case (2 seats on every day, default 5), switching to the 4-seat car works, with no `PATCH …/seats` first. If one day still uses the default 5, the switch is still refused.

The same check runs on publish, resume, duplicate, `GET /driver/vehicles/replace-preview` and activate, so they all agree.

### B3 · `conflictingSeatRequestId` on seat offers

New on `GET /seat-offers` and `GET /seat-offers/{offer}`:

| Field | Meaning |
|---|---|
| `conflictingSeatRequestId` | Her own pending seat request on another commute whose time overlaps this seat, or `null`. Only on an `OPEN` offer |

Read the driver and date from `GET /seat-requests/{seatRequest}`. Only a request of `kind: "seat_request"` counts. One that is already a waiting-list application doesn't, and neither does a during-ride request. "Overlaps" uses the same time-window rule as `conflictingBookingId`.

**What accepting really does:** the request is **not** put on Ahmed's waiting list. It stays `PENDING`, and its `kind` changes to `"waitlist_application"` (BOOK-34). Ahmed then decides: if he adds her, she is on his waiting list. If he declines, she isn't. So the copy should say:

> This overlaps your request with Ahmed on Thu 26 Nov. If you accept, that request becomes a request to join Ahmed's waiting list.

### B4 · The passenger's session id

Both are added:

- **`nextJourney.tripSessionId`** on `GET /home`. It is the run's session id once the driver has started, and `null` before.
- **`GET /safety/live-shares?tripId={trip id}`** returns the caller's links for that trip's run. `?tripSessionId=` still works.

The guide now says on every field that `tripSessionId` is the run's session id, not the trip id: the live-share fields, the list call and `nextJourney`.

## Part 1: questions

### B5 · A freed seat offered to someone booked elsewhere that morning

**On purpose.** BOOK-24 lets a passenger with a confirmed seat wait on another commute's list and switch to it. So a driver's replacement pick is not refused for her. `REPLACEMENT_NOT_ELIGIBLE` only covers these cases:

- `same_passenger`
- `not_waiting_for_this_day`
- `needs_more_seats`
- `already_offered`

She takes it with **Accept & Switch**: send `switchFromBookingId` (the offer's `conflictingBookingId`). Without it, accept answers `409 BOOKING_ALREADY_BOOKED` with `error.fields.overlapsBookingId`. Keep showing `hasOtherConfirmedCommute`.

### B6 · "Over the limit" and "would miss the promised arrival"

Both can happen. Your test didn't hit them for the reasons below.

**`withinLimit: false`.** Each open request is measured on top of the run as it is now. That means the driver's own stops plus every custom pickup **already accepted**. Other open requests are not counted. So your three requests (6, 12, 12 min) each read `withinLimit: true` while all three were open. Accept two of them, and the third is measured against a run that already carries their minutes. It then reads `withinLimit: false` once the run total passes 30. That answers your second question too: each impact counts the ones already accepted, and not the other open ones. A request can't be sent over the limit (`422 PICKUP_DETOUR_TOO_LONG`), so this only happens after accepting others.

**`blockReason: "promised_arrival"` (`canAccept: false`).** This applies only inside the **12-hour lock** before departure (`lockHours`). Before the lock, a new rider may still move the departure earlier, so a projected 11:41 against an 11:30 promise doesn't block yet. Inside the lock, the departure is fixed, and a request that would make the car late can't be accepted. To test it, move the run into the lock with `POST /testing/trips/{trip}/departure`. The Accept toast ("would make others late") is the same rule: `skippedDays[]` with `reason: "breaks_arrival"`, only for locked days.

Keep both lines.

### B7 · Where alternatives start after a stopped run

**When a position counts as expired:** the car's last position is kept for **5 minutes after the last point the driver's phone sent**. The age of the point itself doesn't matter. `/testing/trips/{trip}/location/age` makes the point look old, but it also keeps it for another 5 minutes. That's why you still saw `WHERE_THE_CAR_STOPPED`. To test the expired case, stop sending positions and wait 5 minutes. You then get `LAST_STOP_REACHED`, the same as your "none at all" row.

**`AGREED_PICKUP_UNVERIFIED`:** this is used for a passenger who was aboard, when there is no position and no stop marked reached. Checking her in marks her pickup stop reached, but only if the day's stop list was built. The list is built when the driver's app asks for the route or location. So it is rare, for example a run started and checked in without the app ever loading the route. Keep the "We couldn't confirm where the car stopped" line.

### B8 · Eligibility blockers

**Objects are the contract.** The guide was wrong and now matches what is sent:

```json
{ "reason": "below_minimum_age", "nextStep": "NONE", "message": "…" }
```

- `reason` (lower case): `account_not_active` · `phone_not_verified` · `profile_incomplete` · `date_of_birth_missing` · `below_minimum_age` · `already_a_driver` · `application_under_review` · `driver_suspended` · `not_eligible`
- `nextStep`: `CONTACT_SUPPORT` · `VERIFY_PHONE` · `COMPLETE_PROFILE` · `OPEN_DRIVER_DASHBOARD` · `WAIT_FOR_REVIEW` · `NONE`
- `message`: ready to show, in her language

The under-age code is **`below_minimum_age`**. `UNDER_MINIMUM_AGE` and `GOVERNMENT_ID_NOT_VERIFIED` were never sent. You can drop the plain-code reading.

### B9 · "Expiring" with no date

**Can't happen.** `overall: "expiring"` is set only when the licence has a date in the next 30 days, and `licenceExpiresAt` is that same date. Remove the no-date message.

### B10 · A `NO_SHOW` booking without `noShow`

**Not for the passenger.** A booking becomes `NO_SHOW` only when her attendance is a passenger no-show, and `noShow` is built from that same record. Two cases where the key is missing, neither of them a passenger's own booking:

- The **driver** reading the booking. `noShow` is only for the passenger.
- A booking forced to `NO_SHOW` with a testing call that doesn't write the attendance.

You can remove the note.

### B11 · Push from `/testing/notifications`

**Yes.** It sends through the same path as a real notification. That means one inbox row, plus one push per signed-in device with a push token, if her push setting for that category is on. The push goes out from the queue worker a moment after the `201`.

To tell where it went:

**`meta` on `POST /testing/notifications`:**

| Field | Meaning |
|---|---|
| `inboxWritten` | Whether the inbox row was written (her in-app setting) |
| `pushAllowed` | Whether her push setting for that category is on |
| `pushTokens` | Her signed-in devices that have a push token |
| `pushIds` | The push rows created, one per token |

**New `GET /testing/push-log`** → `{ pushProviderConfigured, devices[], pushes[] }`:

- `devices[]`: `id`, `platform`, `deviceModel`, `appVersion`, `hasPushToken`, `pushTokenEnd` (last 8 characters, to compare with the token the app holds), `lastSeenAt`, `revokedAt`.
- `pushes[]`: her latest 20 pushes, newest first, with `id`, `type`, `title`, `createdAt`, `sentAt`, `failedReason` and `state`:
  - `sent`: FCM accepted it
  - `failed`: `device_unavailable`, `no_push_provider`, `token_rejected`, or the provider's error
  - `queued`: not sent yet

For g242, look at `meta.pushTokens` first. If it is `0`, the emulator never registered its token for Karim's account. If it is `1`, read `push-log`: `queued` means the worker is behind, `failed` gives the reason, and `sent` with no row on the device points at the app.

## Part 2: testing calls

| # | Call | Answer |
|---|---|---|
| T16 | `POST /testing/seat-requests/{request}/close` | ✅ Takes `"EXPIRED"` and `"ENDED"` |
| T17 | `PATCH /testing/account/fields` | ✅ New |
| T18 | `POST` / `DELETE /testing/commutes/{commute}/pause` | ✅ New |

### T16 · Close a request as expired or ended

`{ "status": "EXPIRED" | "ENDED" }` closes **any** open request, one-day or recurring, with that status. It also takes the request off the waiting list, and `endedAt` is set. `CLOSED_DATE_PASSED` works as before (one-day only). The passenger or the commute's driver can call it. It answers `409 SEAT_REQUEST_NOT_PENDING` when the request is no longer open.

### T17 · Clear her gender

`PATCH /testing/account/fields { "gender": null }` → `{ id, gender: null }`. `"woman"` and `"man"` set it back, and `PUT /account/profile/basic` works too.

A finished profile can't have no gender in the database, so "not set" is stored as the old `prefer_not_to_say`. The API already shows that as `gender: null`. A seat request on a women-only commute then answers `403 SEAT_REQUEST_WOMEN_ONLY` with `error.fields.reason: ["GENDER_NOT_SET"]`. That is the real case G-MT-12 covers.

### T18 · The women-only paused banner

`POST /testing/commutes/{commute}/pause { "reason": "women_only_check" }` → `{ id, status: "PAUSED", pausedReason: "gender_review", pausedAt }`. `"gender_review"` is accepted as the reason too.

🔴 **The server's reason is `gender_review`**, so read that in `pausedReason` / `pausedCommuteReasons`, not `women_only_check`.

- It works on the caller's own **women-only** commute. A commute open to everyone answers `422`, and anybody else's commute `404`.
- It pauses the same way staff do, so its confirmed seats are cancelled.
- `DELETE` resumes it without a review (`status: "PUBLISHED"`, `pausedReason: null`) and fills in the days that went by.
