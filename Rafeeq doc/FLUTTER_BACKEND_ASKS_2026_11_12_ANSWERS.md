# Backend asks of 2026-11-12: answers

**Date:** 2026-11-12 · **Answers:** `docs/blockers/backend-asks-2026-11-12.md` (mobile repo) · **Full contract:** `RAFEEQ_MOBILE_API_GUIDE.md`, change log entry of 2026-11-12 (now **223 endpoints**)

The changes are on the backend branch `feat/backend-asks-2026-11-12` and are **not deployed yet**. We will tell you
when they are on Railway.

| # | Answer | What you need to do |
|---|---|---|
| A-1 | ⏳ Staff | Nothing. Staff resolve Karim's three disputes on the staging dashboard. |
| A-2 | ✅ Fixed (a bug) + rule | Re-run MG-6 after the deploy. |
| A-3 | ✅ Done | Match `seat_counteroffer_accepted` in N-3. |
| A-4 | ✅ Rule | Trigger it by checking in the passenger **before** hers. |
| A-5 | ✅ Rule | Treat `EXITED_DUE_TO_DRIVER_DELAY` + `trip_expired` as "expired". Drop `EXPIRED` and `PENDING` from History. |
| A-6 | ✅ Yes | Nothing. It was already in the guide; the line is now clearer. |
| A-7 | ✅ Rule, a fix, and a testing call | Use `POST /testing/trips/{trip}/position` in TD-14. |
| A-8 | ✅ Done | Use `POST /testing/driver/vehicles/{vehicle}/approve` (or `/reject`) in MG-16. |

---

## A-1 · Karim's three leftover disputes

This is a staff action on the staging dashboard (**Payments** → appeals), not code. We will resolve them and tell
you. Nothing blocks you.

## A-2 · When an all-accepted proposal takes effect (Q-1)

**Rule: A, at once.** The answer that completes unanimity applies the proposal in the same request.
`POST /change-proposals/{id}/respond` answers `status: "APPLIED"`. For an `all_future_on_this_weekday` change, the
old waiting list is cleared and `waitlist_cleared_by_change` is sent in that same request. There is no job and no
wait.

| Status | When |
|---|---|
| `PENDING` | Waiting for answers. |
| `APPLIED` | The last affected passenger accepted (or nobody was affected). Final. |
| `REJECTED` | One passenger rejected. Final; the driver can revise and ask again. |
| `EXPIRED` | The affected morning's departure passed first. Final. |

`withdrawn` only works while it is `PENDING`. After `APPLIED` it answers `409 CHANGE_PROPOSAL_NOT_LIVE`.

**Why MG-6 stayed `PENDING`: a bug, fixed.** Unanimity was counted per **booking**. A recurring rider holds one
booking per affected weekday, but her answer was saved on only one of them, so the proposal could never become
unanimous. Now one answer from her counts for **all** her affected days. The driver's `passengers[]` still lists one
row per day, and all her rows read `ACCEPTED`.

## A-3 · The driver's notification for an accepted counteroffer

It was missing, and the decline already had one. **New:**

| `type` | Who | `data` |
|---|---|---|
| `seat_counteroffer_accepted` | Driver | `seatRequestId`, `commuteId`, `bookingIds` (comma-separated) |

Title "Your suggested spot was accepted". Body: "{name} agreed to meet at the point you suggested. Her seat is
confirmed." In English and Arabic.

## A-4 · What sends `driver_heading_to_you`

It is sent **once**, to the **next** passenger in route order, when the driver **finishes the pickup before hers**.
Finishing means he checks that passenger in (`POST /trips/{trip}/check-in`) or marks her a no-show. Route order is
the itinerary's, the same order as the driver's navigation screen.

- The **first** passenger of the run never gets it. She gets `trip_started` when the run starts.
- Moving to `EN_ROUTE`, or a location point near her pickup, doesn't send it.

**To trigger it in N-6:** two passengers on the run. Start, `EN_ROUTE`, check in the first one → the second gets
`driver_heading_to_you` with `tripId` and `bookingId`.

## A-5 · When a booking ends as `EXPIRED`

**Never, today.** `EXPIRED` (and `PENDING`) exist in the model but nothing writes them: a booking is created already
`CONFIRMED`. A run the driver never started expires at +20 minutes, and her booking ends
`EXITED_DUE_TO_DRIVER_DELAY` with `cancelledReason: "trip_expired"` (no fee, the driver's fault).

The endings that really occur: `COMPLETED`, `NO_SHOW`, `ENDED_EARLY`, `CANCELLED_BY_PASSENGER`, `CANCELLED_BY_DRIVER`,
`EXITED_DUE_TO_DRIVER_DELAY`. Show `EXITED_DUE_TO_DRIVER_DELAY` + `trip_expired` as "Expired, the driver never
started". Guide 4.7 now says so.

## A-6 · A time edit with nobody booked

**Yes.** A change proposal that affects nobody (no confirmed booking on those days) is applied at once:
`POST /driver/commutes/{commute}/change-proposals` answers `201` with `status: "APPLIED"`, and no pushes are sent.
Guide 4.7 already said this under the proposal endpoint.

The direct `PUT /commutes/{commute}/schedule` still answers `409 COMMUTE_NOT_EDITABLE` on a published commute. The
proposal endpoint is the path, and with nobody affected it behaves like a direct save.

## A-7 · `already_passed`

**The rule:** the server takes the car's **last reported position** and her **pickup**, finds where each falls along
the commute's route (the closest point on the line), and compares them. If the car's place along the route is at or
past her pickup's, the answer is `already_passed`. Distance doesn't matter, only order along the route.

**What we changed:** the route used was a fresh origin → destination route, not the route the driver **published**
(which goes via his pickup points). On real roads the two can differ, and a car 1 km before a pickup on the published
route could fall "past" it on the other line. It now measures along the **published** route, as the pickup-detour
and ETA calculations already do.

We couldn't see your TD-14 data, so we can't promise that was the whole cause. Two other things also give
`already_passed`:
- her pickup is **behind** the car on the route (for example near the origin, while the car is 1 km before a later
  pickup);
- the position the server holds is older or different from the one you think you sent.

**New testing call, so the test doesn't depend on coordinates:**

`POST /testing/trips/{trip}/position` puts the car on the published route, `metres` before a point, as a fresh
location point from the driver.

```json
{ "beforeBookingId": "01J…", "metres": 1000 }
{ "lat": 30.0684, "lng": 31.1015, "metres": 1000 }
```

Answers `{tripId, lat, lng, metresBeforeTarget}`. The run must be started (`409 TRIP_NOT_STARTED`). For TD-14, place
the car before **her** pickup (use `lat` / `lng` of the point she will request, since she has no booking yet), then
send her request: it is accepted and reaches the driver's 3-minute window.

## A-8 · Approve a replacement car under review

**New**, on the caller's own car, `404` unless `RAFEEQ_TESTING_API=true`:

- `POST /testing/driver/vehicles/{vehicle}/approve` → the car is `APPROVED` with its approved seats. Then
  `POST /driver/vehicles/{vehicle}/activate` does the real swap (her bookings are cancelled, the passengers go back to
  the waiting list with `vehicle_replaced_seat_cancelled`).
- `POST /testing/driver/vehicles/{vehicle}/reject` with optional `item` (`vehicle_details` ·
  `vehicle_registration`) and `reason` → the car is `REJECTED`. Her driver status stays approved.

Both send `driver_item_approved` / `driver_item_rejected` with `vehicleId`. Both answer `{id, verificationStatus,
approvedSeats, isActive, rejectedItem, rejectionReason}`. `422 VEHICLE_NOT_ALLOWED` if the car isn't `pending` or the
driver isn't approved.

Activating before approval is unchanged: `422 VEHICLE_NOT_ALLOWED`.
