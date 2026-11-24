# Backend for review items 31–59 (2026-11-24)

Answers to `2026-11-23-backend-requirements-31-59.md` (items 31–59 without the return trips 38, 39, 40, 46).
Everything below reaches the test server with the next deploy. Old builds keep working: every new request field is
optional with today's behaviour as the default, and every new response field is additive. One wording change is
called out in §5 (the passenger's "removed you" notice).

| # | Item | Change | State |
|---|---|---|---|
| 1 | 32 | Seat price rounds **up** to the next whole pound; why 15 % didn't show | ✅ built · ℹ️ answered |
| 2 | 44 | `409 COMMUTE_PAUSED`; commute status on request rows; `GET /driver/seat-requests/{id}` | ✅ built |
| 3 | 45 | Commute status on `GET /driver/waitlist` rows | ✅ built |
| 4 | 48 | `DELETE /notifications`, mark unread, ids in `data` | ✅ built |
| 5 | 56 | Remove a passenger from one day or all days | ✅ built |
| 6 | 47, 51 | `impact.addedMetres`, Google settings pinned and documented | ✅ built · ⚠️ see §6 |
| 7 | 42 | `debt_payment_confirmed` carries `commuteIds` | ✅ built |
| 8 | 59 | `promisedArrivalLocal` on every day of the public commute | ✅ confirmed |
| 9 | 35, 54 | `requestedDaysMask`, drop-off time on the driver's bookings | ✅ built |
| 10 | 34, 35 | Day screen and Waiting list screen calls | ✅ confirmed |

The API guide (`RAFEEQ_MOBILE_API_GUIDE.md`) is updated for all of it.

---

## 1 · Seat price (item 32)

**Rounding.** Seat = driver's share + commission (rate × share, at least 8 EGP). Only a fraction is rounded **up** to
the next whole pound; a whole-pound result stays as it is. The extra piastres are YuGo's; the driver's share never
changes.

| Share | Rate | Before rounding | Seat |
|---|---|---|---|
| 100 | 10 % | 110 | **110** |
| 100 | 20 % | 120 | **120** |
| 115 | 10 % | 126.5 | **127** |
| 115 | 15 % | 132.25 | **133** |
| 75 | 10 % (→ 8 min) | 83 | **83** (was 85) |
| 133.33 | 10 % | 146.67 | **147** (was 145) |

**App:** no change. Keep showing `seatPricePiastres` and `driverSharePiastres` as they come. Refresh price goldens
if a demo value moved.

**Why the owner saw 125 at "15 %".** Each day freezes the price it was generated with (PAY-44), and days are
generated up to 30 days ahead. A rate change on the dashboard reaches **only days generated after it**: a commute
published before the change keeps the old price on its existing days, and its commute card (`seatPricePiastres` on
`GET /commutes/{id}`) moves at the next generation run (daily, 03:00 Cairo). 115 + 10 % = 126.5 → 125 under the old rounding is
exactly that. The setting itself was read correctly; nothing was cached wrongly.

So the answer to "when does a rate change reach existing commutes": **new days only**. If the owner wants every
upcoming day repriced instead, that is a rule change to PAY-44 and needs a decision first.

**Dashboard:** the field now reads "% added on top of the driver's share", with a live example under it
("Example at 15%: driver's share EGP 115.00 + EGP 18.00 = seat EGP 133.00 …") that follows what is typed.

## 2 · Requests on a paused commute (item 44)

1. `POST /driver/seat-requests/{id}/approve`, `/counteroffer` and `/waitlist` on a paused commute answer
   **`409 COMMUTE_PAUSED`**, message "This commute is paused. Resume it to accept requests." Map it as soon as you
   like; the old `SEAT_UNAVAILABLE` no longer comes back for this case.
2. Every row of `GET /driver/seat-requests` carries two **flat** fields (not a `commute` object, because the
   passenger's own view already uses `commute` for something else):
   ```json
   "commuteStatus": "paused",
   "commutePausedReason": "by_driver"
   ```
   `commuteStatus`: `active` · `paused` · `archived`. `commutePausedReason` is `null` unless paused, with the values
   of `GET /commutes/{id}` (`by_driver`, `commission_unpaid`, `vehicle_suspended`, …).
3. **New: `GET /driver/seat-requests/{seatRequest}`**, the same row as the inbox (with `impact` while `PENDING`).
   Use it when a notification opens one request. `404` for anybody else's.
4. **Confirmed:** deleting a commute closes every open request and waiting-list place on it as `COMMUTE_ARCHIVED`
   at once, and closes its open seat offers. Reloading the Requests tab after a delete is enough.

You can drop the `GET /commutes?status=paused` lookup once this is deployed.

## 3 · Waiting list tab (item 45)

Each `GET /driver/waitlist` row (and `GET /driver/commutes/{commute}/waitlist`) now has `commuteStatus` and
`commutePausedReason`, same as §2, next to `commuteId`, `originLabel`, `destinationLabel` and `direction`. Group by
`commuteId`; a group with `commuteStatus: paused` shows "Paused · seats can't be offered until you resume".

## 4 · Notifications (item 48)

### Delete

```http
DELETE /notifications
{ "ids": ["01J…", "01J…"] }      // or { "all": true }
→ 200 { "data": { "unreadCount": 3 } }
```

Up to 100 ids. Ids that are not the caller's are ignored. A deleted notification never comes back in
`GET /notifications` and stops counting in `unreadCount` and on Home.

### Mark unread

`PATCH /notifications/read` with **`"read": false`**:

```json
{ "ids": ["01J…"], "read": false }
```

Returns `{ "unreadCount": n }`. Without `read` (or `read: true`) it marks read, as today.

You can switch `AppConstants.notificationDeleteAndUnread` on once this is deployed.

### Ids in `data`

| Type | `data` now |
|---|---|
| `debt_due_tomorrow`, `debt_overdue`, `debt_late_fee_added`, `debt_suspension_warning` | `side: driver` |
| `debt_payment_confirmed` | `side` (`driver` / `passenger`); for a driver also `commuteIds` (§7) |
| `debt_payment_rejected` | `side` |
| `coupon_issued` | `side` |
| `driving_suspended_for_debt` | `side: driver` |
| `riding_suspended_for_debt` | `side: passenger` |
| `driver_cancel_fee`, `driver_ended_trip_fee`, `driver_no_show_fee`, `driver_late_fee` | `side: driver`, `debtEntryId` (the line to appeal); `debtEntryIds` (comma-separated) when one act charged several seats |
| `chat_message` | ✅ already: `bookingId` for a booking thread, `seatRequestId` + `commuteId` for a request thread |
| `rating_due` | ✅ already: `bookingId` |
| `rating_due_riders` | ✅ already: `tripId`, `riderCount` |
| `rating_reminder` | ✅ already: `bookingId` (passenger) or `tripId` (driver) |
| `booking_cancelled` | `bookingId`; when the driver removed her from one day also `commuteId`, `scope: day` |
| `removed_from_commute` (new) | `commuteId`, `bookingId`, `scope: all`, `daysCount` |

Open Payments in the role `side` says; fall back to your type mapping when `side` is missing (older rows).
All `data` values are strings, as before.

## 5 · Remove a passenger: one day or all days (item 56)

### Preview

`GET /driver/bookings/{booking}/cancel-preview?scope=day|all` (default `day`):

```json
{
  "scope": "all",
  "daysCount": 12,
  "firstTripDate": "2026-11-29",
  "lastTripDate": "2026-12-24",
  "affectedSeats": 12,
  "feePiastres": 1100,
  "reason": "mid_notice",
  "perTrip": [{ "tripId": "…", "departureAt": "…", "seats": 1, "percent": 10, "feePiastres": 1100 }, …]
}
```

`all` covers **her confirmed bookings on this commute from this one on** (every upcoming day of her seat).
`feePiastres` is the sum of each day's fee by its own notice (usually only the nearest day costs anything).
`scope: day` returns the same keys with `daysCount: 1`.

### Remove

`POST /driver/bookings/{booking}/cancel` with `{ "reason": "…", "scope": "all" }` (`scope` optional, default `day`):

- `day`: as today, one booking.
- `all`: cancels every upcoming confirmed booking of hers on this commute, each with its own fee and each freed day
  offered to that day's waiting list; **ends her recurring seat** (her request becomes `ENDED`, her membership
  `REMOVED`, so no new days are booked for her).

Response: the booking, plus

```json
"meta": { "scope": "all", "daysCount": 12, "cancelledBookingIds": ["…", "…"] }
```

You no longer need the one-by-one fallback in the app.

### What she is told

One notification per removal:

| Scope | Type | Body (en) |
|---|---|---|
| `day` | `booking_cancelled` | "Omar removed you from 8/11." ⚠️ was "Omar cancelled the booking for 8/11." |
| `all` | `removed_from_commute` (new) | "Omar removed you from this commute (12 days)." |

Open `removed_from_commute` on the commute (`commuteId`). An unknown type still shows as a plain message on old builds.

## 6 · One Google road (items 47, 51)

**Done:**

- `GET /driver/seat-requests/{id}/route-preview` → **`impact.addedMetres`**: the extra distance of that day's run with
  her on board, "your road now" (stops without `isProposed`) vs "with her" (all stops). Never negative. The same
  field is on the inbox row's `impact`.
- Every Google Routes call the server makes for a road is pinned to the app's settings: `travelMode: DRIVE`,
  `routingPreference: TRAFFIC_UNAWARE`, `computeAlternativeRoutes: false`, stops as `intermediates` in order.
  (Only the computed leave time of an arrive-by day uses `TRAFFIC_AWARE` with a departure time; no map depends on
  it.) Documented in the guide under the route preview.
- The day's itinerary (`navigation-route`, `route-preview`, `addedMinutes`, `addedMetres`, pickup / drop-off times)
  already uses exactly the app's stops: driver's start → that day's confirmed pickups and drop-offs (plus her two
  points in a preview) → driver's end. Suggested points are not stops there.
- `polyline` / `routePolyline` stay in the responses so older builds keep drawing. Ignore them in the new build.

**⚠️ Not changed yet, needs the owner:** the commute's **published road** still goes driver's start → the driver's
suggested pickup points → end. That road is what the **seat price** (route km), the **matching corridor**
(distance-to-route) and the **detour tiers** (`tier`, `withinLimit`, `PICKUP_DETOUR_TOO_LONG`) are measured against.
Switching it to start → end would make it match what the app draws, but it changes every price on a commute with
suggested points and moves the detour tiers (D-0034 / D-0042), so it waits for the owner's decision. Until then, a commute with
suggested pickups can be priced and checked on a slightly longer road than the one drawn.

## 7 · Paid commission, commute still paused (item 42)

`debt_payment_confirmed` for a driver carries **`commuteIds`**: the driver's commutes still paused for
`commission_unpaid`, comma-separated (absent when there are none). Open the commute when there is exactly one, the
commute list when there are several, Payments when it's absent. The driver still resumes it himself.

`GET /account/debt` → `payments[]` with `status: pending_confirmation` is unchanged, so "Payment under review" and
"Commission paid · your commute is ready to resume" work from it as planned.

## 8 · Promised arrival on the public commute (item 59)

Confirmed and now covered by a test: `GET /commutes/{id}/public` → `upcomingTrips[].promisedArrivalLocal` and
`promisedArrivalAt` are filled on **every** upcoming day that has a promise, booked or not. The value is the day's
own (`Y-m-d H:i:s`).

It is `null` in two cases, both expected:

- a **Return** commute, timed by when it leaves: show `promisedDepartureLocal` and `estimatedArrivalLocal` (both on
  every day row);
- a day generated **without** a promised arrival (a commute timed by departure before arrive-by was required).

So on the Match screen: `promisedArrivalLocal ?? estimatedArrivalLocal`, never "—". If you saw "—" on a Going
commute whose days all have a promise, the app was most likely reading a commute-level field: there is none on
`/public`; read the selected day's row.

## 9 · Passenger details on the driver's bookings (items 35, 54)

Every row of `GET /driver/bookings` (also with `?tripId=` / `?commuteId=`) has:

| Field | Meaning |
|---|---|
| `commitment` | ✅ already there: `trial` · `recurring` |
| `requestedDaysMask` | **New.** The weekdays she rides: her request's mask, else her membership's `committedDaysMask`, else `null`. |
| `pickupEtaAt` / `pickupEtaLocal` | ✅ already there |
| `dropoffEtaAt` / `dropoffEtaLocal` | **New.** When the car should reach her drop-off that day: from the day's itinerary, else from the route. `null` when neither can say. `…Local` is `Y-m-d H:i:s`. |
| `commuteGroupId` · `tripDate` | ✅ confirmed on every row (`commuteGroupId` is `null` on a one-off seat with no group). |

`membership` (`{committedDaysMask, commitment, status}`) is also on each row, as on the passenger's bookings.

## 10 · Confirm only (items 34, 35)

- **Day screen (34):** yes, those are the right calls: `GET /driver/bookings?tripId=…&status=CONFIRMED` (that day's
  passengers, now with drop-off times), `GET /driver/commute-occurrences/{trip}/navigation-route` (the map) and
  `GET /driver/trips?commuteId=…&scope=upcoming` (the day's seats). Nothing new.
- **Waiting list screen (35):** `GET /driver/commutes/{commute}/waitlist` has `requestedDaysMask`,
  `waitlistPosition`, `joinedWaitlistAt`, `tripDate`, the passenger, and now `commuteStatus`. Nothing new needed.

## New error and notification codes

| Code | Status | When |
|---|---|---|
| `COMMUTE_PAUSED` | 409 | Approve, counteroffer or waitlist on a paused commute |

| Type | To | Open |
|---|---|---|
| `removed_from_commute` | passenger | the commute (`commuteId`) |
