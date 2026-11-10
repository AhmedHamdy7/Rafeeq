# Backend asks of 2026-11-11: answers

**Date:** 2026-11-11 · **Answers:** `docs/blockers/backend-asks-2026-11-11.md` (mobile repo) · **Full contract:** `RAFEEQ_MOBILE_API_GUIDE.md`, change log entry of 2026-11-11 (now **209 endpoints**)

Most items are done, and the rest are answered. Four are answered differently from the ask:

- **B4:** a product rule forbids moving the time in the morning.
- **A6:** Live Activity pushes wait until after the beta.
- **B1:** the Friday deadline can't be moved.
- **B8:** auto-share has no provider yet.

The changes are on the backend branch `feat/maps-redesign-gaps` and are **not deployed yet**. We will tell you
when they are live.

| # | Status | What you need to do |
|---|---|---|
| A1 | ✅ Done | Read `stopsBeforeYou`. |
| A2 | ✅ Done | Call `POST /pickup-requests/{id}/keep-current`. Handle the new status and push. |
| A3 | ✅ Done | Send the arrival without `lat` / `lng` when you have no fresh fix. Handle `409 NO_RECENT_LOCATION`. |
| A4 | ✅ Confirmed | Nothing. |
| A5 | ✅ Confirmed | Nothing. |
| A6 | ⏳ Later | Keep the static value for the beta. |
| A7 | ✅ Confirmed | Straight line. Nothing to change. |
| B1 | ✅ Done, two parts not done | Use the three `/testing` calls once we switch them on for staging. |
| B2 | ✅ Done (interim) | Call `GET /search/commutes/browse`. |
| B3 | ✅ Done, and a fix | Call `POST /commutes/{commute}/dropoff-preview`. |
| B4 | ❌ Not built: product rule | Keep "about 8:00 AM" until the 12-hour lock, then show the time as fixed. |
| B5 | ✅ Done | Call `PATCH /seat-requests/{id}`. |
| B6 | ✅ Done | Read `instapay` on `GET /account/debt`. Remove the hard-coded values. Staff set it on the dashboard. |
| B7 | ✅ Confirmed (one difference on 5) | See the answers below. |
| B8 | ✅ Filter done · ❌ auto-share not | Use `?tripSessionId=`. Don't promise auto-share. |
| B9 | ✅ Answered | Nothing on logout. Stay on registration tokens. |
| B10 | ✅ Done | Handle `409 VEHICLE_LOCKED`. |
| B11 | ✅ Sent | The guide in this repo is current. |
| C | ⏳ Merged, not confirmed live | We will tell you after the deploy. |

---

## Part A

### A1 · Stops before her pickup

`stopsBeforeYou` is on both endpoints. It counts the pickups and drop-offs the car still makes before her pickup.
It is only a number, with no names and no route.

```json
// GET /trips/{trip}/location (passenger with a confirmed seat)
{ "position": { "...": "..." }, "pickupEtaAt": "...", "carToPickupPolyline": null, "stopsBeforeYou": 1 }

// GET /home → nextJourney.live
"live": { "pickupEtaAt": "...", "progressPercent": 40, "stopsBeforeYou": 1, "...": "..." }
```

| When | Value |
|---|---|
| Run started, the car is going to someone else first | `1`, `2`, … |
| She is the next stop | `0` |
| Driver tapped "I've arrived" at her pickup | `0` |
| On board, before the start, or for the driver | `null` |

- `GET /trips/{trip}/location` now builds the day's itinerary if the driver hasn't opened navigation yet, so
  `pickupEtaAt` and `stopsBeforeYou` are filled from the start of the run.
- **Fixed:** while the run was live, `/home` → `nextJourney.live` could answer `500`. It read stop columns
  that the home query never loaded. This is fixed and covered by a test.

### A2 · "Keep my pickup"

**New:** `POST /pickup-requests/{pickupRequest}/keep-current` (the passenger, no body).

- It closes the request with the new final status **`ALTERNATIVE_DECLINED`**. Nothing moves: her pickup stays as
  it is (BOOK-37).
- It returns the updated row (same shape as `GET /pickup-requests`).
- The row leaves the open list on both sides (`?status=PENDING,SUGGESTED_ALTERNATIVE`).
- The driver gets the push **`pickup_change_alternative_declined`**:

  | `type` | Who | `data` |
  |---|---|---|
  | `pickup_change_alternative_declined` | Driver | `pickupRequestId`, `commuteId`, plus `commuteGroupId` (member) or `seatRequestId` (new joiner) |

  Title "Mona kept their pickup". Body: "Mona prefers their current pickup to {place}. Nothing changes." It is
  in English and Arabic, in the Booking category like the other five.
- **Refusal:** `409 PICKUP_REQUEST_NOT_PENDING` unless the row is `SUGGESTED_ALTERNATIVE` (for example, already
  answered).
- **Difference from your ask:** the 12-hour lock and "group not active" are **not** checked. Nothing moves, so
  there is nothing to lock. She can always keep what she already has.

Add `ALTERNATIVE_DECLINED` to your status enum. Show it as closed, like `REJECTED`.

### A3 · "I've arrived" from the notification or the Live Activity

**Done, the first option.** `POST /trips/{trip}/wait-timers` (and `POST /trips/{trip}/status` with `AT_PICKUP`)
now accept the arrival **without `lat` / `lng`**:

- With `lat` / `lng`: no change.
- Without both: the server uses the **last point the phone sent** to `POST /trips/{trip}/location`, if it is
  **under 2 minutes old** (by `recordedAt`). It checks the 150 m radius the same way, so a point too far away still
  answers `409 NOT_AT_PICKUP` with `distanceMeters`.
- No point, or the last point is older than 2 minutes: **`409 NO_RECENT_LOCATION`** ("We couldn't get your
  location. Open Rafeeq and tap I've arrived again." It is in English and Arabic.) Open Rafeeq on that error.
- Sending only one of `lat` / `lng` is a `422`.

This still follows TRIP-10, because the driver taps and GPS checks it. Arrival detected by the server alone is
still not built (that needs a partners' decision; see the 2026-11-10 answer).

**For the app:** keep sending a fresh fix when you have one. On iOS in the background, send the call without
`lat` / `lng`, and keep the background batches running so a recent point exists.

### A4 · Background batches: confirmed

1. **Yes.** Batches of 1–60 points in `recordedAt` order are accepted, also 30–60 s late. The newest point drives
   the passenger's map.
2. **Yes, a late batch can't trigger a check by mistake.**
   - If every point in a batch is older than the newest one we already have, the points join the trail. The
     current position, `lastLocationAt` and the "off route" check are not touched.
   - "Off route" is checked only on the newest point, and only while the run is `IN_PROGRESS`.
   - The early-drop question reads the current position, which a late batch can't move back.
3. **Yes.** `POST /trips/{trip}/location` has no rate limit. Two calls a minute for 90 minutes is fine.

Points are refused one by one, not the whole batch, when they are:

- more than a few seconds in the future;
- from before the start window;
- worse than the accuracy limit.

`rejected` in the answer says how many.

### A5 · `position.recordedAt`: confirmed

It is the **phone's** time of that fix, not the time of the response. It stays at that time while no newer point
arrives. `position` becomes `null` 5 minutes after the last fix. `lastLocationAt` keeps the same time after that,
so "Last seen N min ago" = `now − lastLocationAt`.

### A6 · Live Activity pushes: after the beta

We'd rather not build it for the beta. Our push goes through FCM (NOTIF-03), and ActivityKit `update` pushes need
a separate APNs token flow and server work. **Keep the static value** for the beta, updated by the app on arrival
and wait. We will come back to it after the beta.

### A7 · `detourTiers`: straight line

**Straight line**, as you read it. The distance from the point to the route polyline is a straight line to the
nearest point on the line. Only the **minutes** are by road. Your check matches ours for distance. The server can
still refuse on minutes, so call `pickup-preview` / `dropoff-preview` before she sends.

---

## Part B

### B1 · Test environment

Three test-only calls are done. They answer **`404`** unless the server has `RAFEEQ_TESTING_API=true` and
is not `APP_ENV=production`. When they are on, **any signed-in account** on that server can call them. Each
call acts only on the caller's own account and commutes.

| Call | What it does |
|---|---|
| `POST /testing/driver/approve` | Approves the caller as a reviewer would: every pending verification item, each car as **Comfort** with AC and approved seats = its seats, and the application. Answers `{driverStatus, vehicles[{id, approvedSeats, tier, verificationStatus}]}`. Send the application first (it goes by itself when the 5 items are in). |
| `DELETE /testing/account` | Deletes the caller's account like `DELETE /account`: commutes archived, seats cancelled, requests withdrawn, demands cancelled, sessions ended, and the number freed for the next sign-up. It skips the checks that block a real deletion. End a live run first (`cancel-active`). |
| `POST /testing/trips/{trip}/departure` | Moves one day of the caller's commute: `{"minutesFromNow": 5}` or `{"departureAt": "<ISO>"}`. The promised arrival and stop times move with it. Use it for the 90-minute start window (`minutesFromNow: 5` → start now), the 12-hour lock (`minutesFromNow: 600`) and the late timers (a negative value). |

- **Clock for the Friday deadline: not possible.** The debt ledger is append-only, so we can't move a debt into
  an earlier week. For the overdue and blocked states, staff can record a debt from the dashboard, or we seed
  one for a test number. Tell us which numbers.
- **OTP `123456` only for test numbers: not done for now.** We don't have the final list of test numbers yet,
  so `123456` still works for **every** number on staging, as today. We will limit it before launch.
- **We will tell you** when `RAFEEQ_TESTING_API` is on for staging.

### B2 · Browse all commutes in Discover (interim)

**New:** `GET /search/commutes/browse`. It is paged (`page`, `perPage`), and **the soonest next day comes first**.
There is one card per commute, and it takes no points.

- **The card is the search card**, so the same parser works. The fields that need her points are `null`:
  `meetingPoint`, `dropOffPoint`, `score`, `tier`, `detourMinutes`, `extraMinutes`. `otherDays` is `[]`.
- **Filters:** `womenOnly`, `tier`, `daysMask`.
- **What it leaves out:** her own commutes, commutes with no free seat, and the same commutes search hides
  (blocked users, trust level, unapproved drivers).
- It is **not** saved to recent searches.
- **Interim:** no product rule covers browsing without points (BOOK-54 describes the search). "Nearest first" is
  not built, because it needs a point. We are asking the partners to confirm.

### B3 · Drop-off preview, and the "+0 min"

**New:** `POST /commutes/{commute}/dropoff-preview`.

```json
// request
{ "lat": 30.0684, "lng": 31.1015, "pickup": { "lat": 30.0624, "lng": 31.3614 }, "scheduledTripId": "01J…" }
// response
{
  "addedMinutes": 4.2, "addedKm": 1.8, "runTotalMinutes": 7.0, "maxDetourMinutes": 30,
  "pointMeters": 900, "tier": "on_way", "extraMinutes": 5, "withinLimit": true,
  "tripId": "01J…", "estimatedDropoffAt": "…", "estimatedDropoffLocal": "…"
}
```

- `pickup` is optional. Send it once she has chosen her pickup, so the preview measures both points together, as
  the seat request will.
- The response is the same as `pickup-preview`, with `estimatedDropoff*` in place of `estimatedPickup*`.

**About "+0 min" (fixed):** `addedMinutes` was "the route with her stop" minus the **saved** route's duration.
The saved duration came from the driver's map at publish time, but the new route came from the server's engine.
When the saved one was longer, a far point came out as 0. Now both routes come from the same engine, so the
difference is real.

**Is it by road?** Only when the server runs the Google engine (`RAFEEQ_GEO_ENGINE=google`). With the default
`straight_line` engine, it is a straight-line estimate and stays low for a point close to the line. We are still
checking the real staging and production setting.

### B4 · Morning recalculated departure: not built (product rule)

COM-16 locks the departure and the pickup times **12 hours before** departure. Moving them on the morning of the
trip would break that lock, and it would also break the time her wait is judged from (D-0043). What already
exists:

- **Until the lock**, the departure is re-timed when someone joins. It only moves earlier, and the promised
  arrival never moves (COM-15). Pushes: `departure_time_changed` (driver; `scheduledTripId`, `commuteId`) and
  `pickup_time_changed` (passengers; `bookingId`, `scheduledTripId`, `pickupEta`, `pickupEtaLocal`).
- **On the morning**, the driver sees `leaveBy {at, local, lateWarning}` on `GET /driver/home` in the 3 hours
  before departure. It comes from traffic (TRIP-29). It is advice for him. The passengers' time doesn't change.

**For the app:** "about 8:00 AM" is right until the lock. After the lock, show the time as fixed.

### B5 · Edit a seat request's points

**New:** `PATCH /seat-requests/{seatRequest}`. It is her own request, **while it is pending**.

- **Body:** `pickup` and `dropoff`, the same shape as on `POST /commutes/{commute}/seat-requests` (a `suggestionId`,
  or `lat`/`lng`/`label`). `introMessage` is optional.
- It is checked the same way (tiers, travel order): `422 PICKUP_DETOUR_TOO_LONG` beyond the red tier.
- It is refused with **`409 SEAT_REQUEST_NOT_PENDING`** once the driver has answered, and for a request sent
  during a live ride (that request has only 3 minutes to be answered).
- It answers the updated request. The driver sees the new points in his inbox.

### B6 · InstaPay payee

`GET /account/debt` now has `instapay`:

```json
"instapay": { "link": "https://ipn.eg/S/…", "handle": "rafeeq@instapay", "displayName": "Rafeeq" }
```

- Staff set the three values on the dashboard (**Settings → Debt**: link, handle, account name). It changes
  without a release, and staging and production each have their own.
- It is **`null`** while neither the link nor the handle is set. Hide the pay button then.
- A value that isn't set is `null` inside the object, e.g. a handle with no link.

### B7 · Confirmations

1. **Pricing: confirmed.** 10% commission, **8 EGP minimum**, no maximum (D-0003, D-0026). Both are admin settings
   (`pricing.commission_rate_percent`, `pricing.min_commission_piastres`). The app shows only
   `seatPricePiastres` and `driverSharePiastres`, which is right.
2. **`fuelType`: confirmed.** `POST /driver/vehicles` accepts it missing.
3. **Counteroffer: confirmed.** A driver's custom `{lat, lng, label}` plus `note` is checked against the detour
   tiers. A refusal is `422 PICKUP_DETOUR_TOO_LONG` with `addedMinutes`, `runTotalMinutes`, `maxDetourMinutes`,
   `pointMeters` and `maxPointMeters`.
   - ⚠️ Not the same for pickup **changes**: `suggest-alternative` on a pickup change still doesn't check the
     driver's place against the tiers. This is the known gap from 2026-11-10.
4. **Statements: confirmed.** `GET /account/debt/statements` has one `commission` line per seat, written when
   that seat is paid at drop-off. "I've paid" (`POST /account/debt/payments`) goes to staff's payments queue as
   `pending_confirmation`.
5. **Seat request accepted: confirmed.**
   - A single approved request becomes `APPROVED` when a seat is free, and `WAITLISTED` only when the trip is full.
   - Its booking is `CONFIRMED` and shows under `GET /bookings?scope=upcoming`.
   - **One difference:** the seat request itself still shows on `GET /seat-requests` with its final status. Filter
     that list with `?status=PENDING,WAITLISTED,COUNTEROFFERED` for the Requests tab.

### B8 · Live shares

- **Done:** `GET /safety/live-shares?tripSessionId={id}` keeps one run's links.
- **Auto-share: not delivered.** There is no SMS or WhatsApp provider yet. `autoShareTrips` is stored, and nothing
  is sent when a run starts. The app must not promise it. Keep the switch hidden or marked "coming soon" until we
  tell you a provider is live.

### B9 · Push

- **Signing out stops pushes:** yes. `POST /auth/logout` clears that device's push token, and revoking a device
  (or deleting the account) clears it too. The server never sends to a cleared token. You don't need to delete it
  in the app, but it does no harm.
- **Firebase installation IDs:** the server sends with FCM HTTP v1 to a **registration token**
  (`message.token`). It does not send to an installation ID. Keep sending the registration token
  (`FirebaseMessaging.getToken()`) as `pushToken`. If the new plugin stops giving one, tell us before you upgrade.

### B10 · Car documents while the application is under review

Done. A car change refused because the application is under review now answers **`409 VEHICLE_LOCKED`**.

- **Message:** "Your car is being reviewed and can't be changed right now. You can change it once the review is
  done." It is in English and Arabic.
- **Which calls:** add a car, edit a car, and upload a car document.
- `DRIVER_APPLICATION_LOCKED` stays for the licence, submit and withdraw.

### B11 · API guide

`RAFEEQ_MOBILE_API_GUIDE.md` in this repo is current (2026-11-11, 209 endpoints). Its change log has the
2026-11-10 entries (Patrol B1–B10, `NO_ARRIVAL_MARKED`, `NO_SHOW_TOO_EARLY`, `COMMUTE_TRIP_IN_PROGRESS`, maps
redesign) and today's. The new testing calls are in section 4.17.

---

## Part C · Live status

PRs #45 and #46 and the 2026-11-10 maps answers are **merged into `main`**. We haven't confirmed that they are
deployed on `rafeeq-production`, or that the test accounts are reset. We will tell you when both are done, and
then you can remove the Call fallback.

---

## New and changed codes

| Code | Status | Where |
|---|---|---|
| `NO_RECENT_LOCATION` | 409 | `POST /trips/{trip}/wait-timers`, `POST /trips/{trip}/status` (`AT_PICKUP`) without `lat` / `lng` |
| `VEHICLE_LOCKED` | 409 | `POST /driver/vehicles`, `PATCH /driver/vehicles/{vehicle}`, `POST /driver/vehicles/{vehicle}/documents` (was `DRIVER_APPLICATION_LOCKED`) |
| Status `ALTERNATIVE_DECLINED` | — | Pickup requests |
| Push `pickup_change_alternative_declined` | — | Driver |
