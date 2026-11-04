# Driver Requests: answers to the backend gaps

Answers to `driver_requests_backend_gaps.md`. Every response is captured in `FLUTTER_DRIVER_REQUESTS_RESPONSES.md`, and the shapes are in `RAFEEQ_MOBILE_API_GUIDE.md` §4.7.

Items that still wait on Product are marked **Waiting on Product**. What's built for them today is safe to use.

| # | Item | Answer |
|---|---|---|
| G1 | Day on an inbox row | Added |
| G2 | Route impact | Added, without a named passenger (**Waiting on Product**) |
| G3 | Filter and count | Added |
| G4 | Waiting list across commutes | Added: `GET /driver/waitlist` |
| G5 | Row and day chips | Added |
| G6 | Offers for the driver | Added: `GET /driver/seat-offers` |
| G7 | Counteroffer options | Added: `GET /driver/seat-requests/{id}/counteroffer-options` |
| G8 | Responses and seeder | Added |
| G9 | Demo accounts on the live server | Not done yet; the backend team has to run it |
| G10 | Unverified driver | Answered |
| G11 | Names and the other commute | Stays as it is |
| G12 | Rating and "New Passenger" | No label in the beta |
| G13 | Waitlist button and skipped days | No button; the wording is **Waiting on Product** |
| G14 | `joinedWaitlistAt` | Fixed |
| G15 | Statuses and events | Answered |

## G1. The day on an inbox row

Each row on `GET /driver/seat-requests` now has `tripDate`, `departureLocal`, `originLabel` and `destinationLabel`.

The day is the requested day for a trial, and the next requested weekday for a recurring request. `tripDate` and `departureLocal` are `null` when no day remains.

## G2. Route impact

Each `PENDING` row has `impact`, and so does `route-preview`. It's `null` on any other status, and when no day remains.

```json
"impact": {
  "tripId": "...",
  "tripDate": "2026-10-18",
  "addedMinutes": 4,
  "maxDetourMinutes": 10,
  "withinLimit": true,
  "promisedArrivalLocal": "2026-10-18 08:10:00",
  "projectedArrivalLocal": "2026-10-18 08:14:00",
  "canAccept": true,
  "blockReason": null
}
```

- **Which day:** `tripDate` says. On a recurring request it's the next requested weekday.
- **`canAccept`:** runs the same check as `/approve`. Disable Accept when it's `false`, and keep handling the `409`, because the run can change after the read.
- **`promisedArrivalLocal`:** `null` on a day with no promise.

**Waiting on Product:** there's no `blockedBy`. The backend stores a promised arrival for the run, not an agreed pickup time for each passenger, so it can't say "Would miss Salma's agreed pickup (07:05)". `blockReason` is `promised_arrival` when Accept is blocked. Until Product decides, show the arrival times and not a name.

Today Accept is blocked only inside the 16 hours before departure. Product is checking whether that should apply to every day, so don't assume `canAccept` is always `true` on a later day.

## G3. Filter and count

`GET /driver/seat-requests?status=pending,counteroffered` works as on `GET /seat-requests`, and `meta.total` counts the filtered list.

`pendingRequests.count` on `/driver/home` counts `PENDING` only, both kinds. It equals `meta.total` for `?status=pending`, not for `pending,counteroffered`.

**Decided cards:** keep them only until the next refresh. To read them later, filter by status, for example `?status=approved,rejected`. Product is confirming this.

## G4. Waiting list across commutes

`GET /driver/waitlist` is paginated across all the driver's commutes, with the same row as the per-commute list.

Use `meta.waitingCount` for the tab count. It counts each person once and equals `waitlist.count` on `/driver/home`. `meta.total` counts rows, so one person on two commutes is two rows.

## G5. Waiting list row and day chips

**On each row, on both lists:** `pickup.label`, `dropoff.label`, `availableToday`, `tripDate` (trial only, otherwise `null`), `originLabel`, `destinationLabel` and `offer`.

**Chips:** `meta.days`, up to 14 upcoming days:

```json
{ "date": "2026-10-20", "tripId": "...", "commuteId": "...", "departureLocal": "2026-10-20 07:05:00", "isFull": true, "waitingCount": 2 }
```

**The filter:** send `?tripId=` rather than a date, because a driver can run two commutes on one date. A member marked not available that day stays on the list, as in the design. The chip's `waitingCount` leaves that member out, because it counts who can be offered the seat, so a chip can show 2 above 3 rows.

## G6. Offers for the driver to read

`GET /driver/seat-offers?status=open,accepted` returns one item per seat round on an upcoming day:

```json
{
  "releaseEventId": "...", "tripId": "...", "commuteId": "...",
  "date": "2026-10-20", "departureLocal": "2026-10-20 07:05:00",
  "status": "ACCEPTED", "recipientsCount": 2,
  "acceptedBy": { "seatRequestId": "...", "publicFirstName": "Laila" },
  "members": [
    { "offerId": "...", "seatRequestId": "...", "publicFirstName": "Laila", "status": "CONFIRMED" },
    { "offerId": "...", "seatRequestId": "...", "publicFirstName": "Mona", "status": "OFFER_CLOSED" }
  ]
}
```

Each waiting list row also has `offer`: `{ id, tripId, date, status }`, or `null`.

- **What the driver may see:** who was offered a seat, who got it and whose offer closed, as the approved design shows (`F3D-12`, `F3D-13`).
- **Declines stay hidden:** a member who declined reads `OFFERED` until someone accepts, then `OFFER_CLOSED`.
- **Laila's card:** someone who accepted leaves the waiting list, so "Confirmed · Tue" comes from `members[]`, not from `GET /driver/waitlist`.
- **How long it stays:** until that day departs. Product is confirming this.

## G7. Counteroffer options

`GET /driver/seat-requests/{id}/counteroffer-options` returns the day, the requested points, the route and the costed options. See the guide for every field.

- **"Your first stop":** remove the label. It comes from the old route model (business G1). The route start is the driver's own endpoint and is never an option. The design's "Rehab Gate 2" is an ordinary advertised stop, sent as a `suggestionId`.
- **Drop-off:** both ends can change, so show Pickup, Drop-off and Both as in `C04`.
- **New pin:** allowed, as in `C04` ("Place the new drop-off"). `canDropPin` is `true`, and you send `{lat, lng, label}`.
- **"saves 6 min":** `changeFromRequestedMinutes` is negative when an option saves time.
- **`walkMeters`:** a straight line, not a walking route, so show it as "about 450 m".
- **`refusalCode`:** the code `/counteroffer` would answer, or `null`. Disable the option when it's set.

## G8. Real responses and seeder

Responses are in `FLUTTER_DRIVER_REQUESTS_RESPONSES.md`. Reset any state with `php artisan demo:driver-requests-states --state=REQ-INBOX`.

| State | Phone | Shows |
|---|---|---|
| `REQ-EMPTY` | `+201055557301` | Nothing in the inbox or on the waiting list |
| `REQ-INBOX` | `+201055557302` | Recurring, trial, application, suggested and custom points, no rating, in another commute |
| `REQ-DECIDED` | `+201055557303` | One row per decided status |
| `REQ-BREAKS-PROMISE` | `+201055557304` | `canAccept: false` |
| `REQ-OVER-LIMIT` | `+201055557305` | `withinLimit: false` |
| `REQ-RECURRING-SOME-FULL` | `+201055557306` | `/approve` with `skippedDays` |
| `REQ-TRIAL-FULL` | `+201055557307` | `/approve` answers `SEAT_UNAVAILABLE` |
| `WL-DAYS` | `+201055557311` | Several days, a full day, not available today |
| `WL-OFFER-OPEN` | `+201055557312` | `F3D-12` |
| `WL-OFFER-ACCEPTED` | `+201055557313` | `F3D-13` |

**Limits:**
- The local server measures routes in straight lines, so captured minutes are smaller than the live ones, and `addedMinutes` is often `0`.
- `DRIVER_COMMISSION_OVERDUE` and `BOOKING_WAITLIST_FULL` aren't captured. Both use the standard error shape.

## G9. Demo accounts on the live server

Not done yet. After this is merged and deployed, the backend team runs `demo:driver-home-states` and `demo:driver-requests-states` on the server and sends the sign-in code. Both commands refuse to run when `APP_ENV=production`, so the server's environment needs checking first.

## G10. Unverified driver

Captured for a driver who hasn't started verification:

| Call | Answer |
|---|---|
| `GET /driver/seat-requests` | `200`, empty |
| `GET /driver/waitlist` | `200`, empty |
| `GET /driver/seat-offers` | `200`, empty |
| `GET /commutes` | `403 VERIFICATION_REQUIRED` |

The Requests tab no longer needs `GET /commutes`. Decide `F3U-02` from `verification` on `/driver/home`.

## G11. Names and the other commute

This stays (SAFE-18, COM-29). Other users never see a full name, and the driver sees only whether a member is free or already on another commute. Show "Rana", one initial and "In another commute", and ask Design to update those cards.

## G12. Rating and "New Passenger"

- **`rating`:** returned today once the passenger has been rated, otherwise `null`. Ratings shipped, so the guide's "Phase 10" note no longer applies to it.
- **"New Passenger":** don't show it. The label is hidden in the beta (BOOK-42, SAFE-20, D-0025), even though the design has it. With `rating: null`, show no stars.

## G13. Waitlist button and skipped days

**Waitlist on a `seat_request`:** no button. The driver's actions are accept, reject and counteroffer (BOOK-11, COM-13). The guide said otherwise and is now corrected. On a full trial day the row turns into `waitlist_application` by itself, so after `SEAT_UNAVAILABLE`, reload and use the row's new `kind`.

**`skippedDays`:** the shape is a list of objects, `[{ "tripDate": "2026-10-20", "reason": "SEAT_UNAVAILABLE" }]`. **Waiting on Product:** the wording and where it appears. Until then, keep the toast.

## G14. `joinedWaitlistAt`

Fixed. Both shapes now return when the person was added to the list: when the driver approved them, or when a platform displacement or their own pause put them back.

## G15. Statuses and events

**Statuses:** all 12 can reach the inbox, unless you filter them out.

| Status | Show as |
|---|---|
| `PENDING` | Open card with its buttons |
| `COUNTEROFFERED` | "Counteroffer sent · waiting for {name}" |
| `APPROVED` | Accepted |
| `REJECTED` | Rejected |
| `WAITLISTED` | Added to waiting list |
| `COUNTEROFFER_DECLINED` | Counteroffer declined |
| `WITHDRAWN` | The passenger withdrew |
| `EXPIRED` | Closed; only a request made during a ride expires |
| `CLOSED_DATE_PASSED` | Closed; the day passed |
| `COMMUTE_ARCHIVED` | Closed; the commute was removed |
| `TERMS_CHANGED` | Closed; the commute's terms changed |
| `ENDED` | Closed; the seat has ended |

The open tab needs only `pending,counteroffered`. The labels for the closed statuses are suggestions; the design has none.

**New requests:** there's no socket event. Refetch on `seat_requested` and `waitlist_application_received` from `GET /notifications`. Push has no provider yet, so poll while the tab is open.

**Chat:** `/seat-requests/{id}/messages` works for a `WAITLISTED` member (BOOK-35). The thread closes when they leave the list.
