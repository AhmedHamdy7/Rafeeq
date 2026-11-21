# Answers: backend requests of 2026-11-21

Answers to `BACKEND_REQUESTS_2026-11-21.md`. Items 1 to 9 are built; item 10 is a standing rule. Everything reaches the test server with the next deploy. Guide sections: 4.18 (content), 4.6 (bookings), 4.2 (Home), 4.14 (safety).

| # | Request | State |
|---|---|---|
| 1 | Legal and help content, before sign-in | ✅ built |
| 2 | Departure and pickup time | ✅ built |
| 3 | Canceled trip on Home | ✅ built |
| 4 | Chat after the ride | ✅ built (1 hour) |
| 5 | Saved demand matches | ✅ built (`GET /matches?demandId=`) |
| 6 | Seats on the commute detail | ✅ already there |
| 7 | Trusted-contact SMS | ✅ built |
| 8 | Test number | ✅ built |
| 9 | Coupons | ✅ checked, nothing promises one |
| 10 | Error codes | 🔶 standing rule, one new code |

## 1 · Legal and help content

`GET /content/{key}` is now **public**: no token needed. Terms and Privacy open from the Phone screen before sign-in. The other keys are public too; none holds anything private.

`key` is one of:

| Key | What it is |
|---|---|
| `terms` | Terms of Service |
| `privacy` | Privacy Policy (**new**) |
| `help_verification` | Help topic: verification (**new**) |
| `help_contributions` | Help topic: contributions (**new**) |
| `help_cancellations` | Help topic: cancellations (**new**) |
| `help` | The general help article and FAQ |
| `support_contact` | How to reach support |
| `guidelines` | Community guidelines |

Each answers `{ key, version, ar, en, updatedAt }`, plain text with line breaks. `404 NOT_FOUND` while staff haven't written it (both languages empty) and for an unknown key. Show the in-app screen with error + Retry on `404` or a network failure.

⚠️ **The text is written by staff on the dashboard's Content page.** Until staff write Terms, Privacy and the three help topics, those keys answer `404`. Keep the browser links as the fallback until then.

**The site and `?lang=`:** `terms.html`, `privacy.html` and the other landing pages now read `?lang=ar|en` and show that language. It also sets the page's saved language, so the choice stays when she follows a link on the site.

## 2 · Departure and pickup time

**Scheduled departure on a booking.** Every booking (list, detail, the Home card) now carries:

| Field | Meaning |
|---|---|
| `departureAt` | ISO 8601, the scheduled departure of her day |
| `departureLocal` | The same instant on the commute's own clock, `Y-m-d H:i:s` |

Both sit next to `pickupEta` / `pickupEtaLocal`, and are present wherever `trip` is. `trip.departureAt` is unchanged.

**Push when her pickup moves.** The push `pickup_time_changed` is now sent only when her pickup ETA moves by **more than 5 minutes** (it was 1 minute). The data carries `bookingId`, `scheduledTripId`, `pickupEta`, `pickupEtaLocal`. Open the booking from it.

**From 12 hours before departure, a new rider may not move a passenger's pickup by more than 5 minutes.**

- Approving a request or accepting a seat offer answers `409 BOOKING_MOVES_FROZEN_PICKUP`.
- `error.fields`: `maxShiftMinutes` (the largest move any passenger would get), `allowedShiftMinutes` (`"5"`), `lockedFrom`. It names nobody.
- It sits beside `BOOKING_BREAKS_FROZEN_PROMISE` (arrival) and is checked together with it; if both apply, the arrival code wins.
- On a request's route preview (`routeImpact`), `canAccept` is `false` for either reason, and **`blockReason` is now `promised_arrival` or `frozen_pickup`** (it was always `promised_arrival`). Disable Accept and explain which one.
- For a recurring request, a day that fails is skipped with `reason: breaks_arrival` and `code` set to the exact code. Read `code` to tell them apart.
- A day with no `promisedArrivalAt` is now judged on the pickup rule too. Before, it was not judged inside the lock at all.

## 3 · Canceled trip on Home

`GET /home` has a new top-level key **`canceledTrip`**: `null`, or

| Field | Meaning |
|---|---|
| `bookingId`, `tripId`, `commuteId` | What the card opens |
| `originLabel`, `destinationLabel` | Route labels, in her language |
| `driverName` | The driver's first name |
| `date` | The day, `Y-m-d`, on the commute's clock |
| `departureAt`, `departureLocal` | Its scheduled departure |
| `cancelledAt` | When the driver cancelled |
| `searchedFrom` | `{ lat, lng, label }`, the origin the alternatives search starts from (agreed pickup, or where the car stopped if she was aboard). `null` if it can't be worked out |

It appears when the driver cancelled a run that had **started**, and it stays until **the day ends** (the commute's own day) or **she books an alternative**: a new confirmed or pending booking, or a pending seat request, made after the cancellation.

It does not depend on `nextJourney` or on `POST /bookings/{booking}/seen`: marking the outcome seen does not remove it. For the alternatives list, call `GET /bookings/{booking}/alternatives` with `bookingId`.

## 4 · Chat after the ride

`canSend` stays `true` until **1 hour after the ride is completed**, then `false`. It was 2 hours. No app change.

⚠️ This is the default of the dashboard setting *Chat stays open after the trip*. If staff changed that setting on the live server earlier, their value wins; set it to 60 minutes there.

## 5 · Saved demand matches

Read them from **`GET /matches?demandId={demandId}`** (paged, newest first). Without `demandId` it returns the matches of all her saved demands, as before. A demand that isn't hers matches nothing (empty page, no error).

Each row already carries what you asked for: `id`, `demandId`, `commuteId`, route (`originLabel`, `destinationLabel`), the day (`tripId`, `tripDate`, `departureLocal`), `seatsAvailable`, `seatPricePiastres`, `otherDays[]`. Open the commute with `commuteId`. Send `POST /matches/{match}/opened` when she taps one, as now, so Home's count of new matches goes down.

`matchCount` on the demand is unchanged.

## 6 · Seats on the commute detail

✅ Already there, on the passenger's view **`GET /commutes/{commute}/public`**. Each entry of `upcomingTrips[]` has `seatsAvailable` and `isFull` for that day (up to the next 20 days).

`GET /commutes/{commute}` (no `/public`) is the **driver's** own commute; its `upcomingTrips[]` now has `isFull` too.

## 7 · Trusted-contact SMS

"`<Name>` added you as a trusted contact on YuGo" is now sent **only to the contact picked for auto-share, and when she is picked**: on `POST /safety/emergency-contacts` with `autoShareTrips: true`, on `PATCH /safety/emergency-contacts/{id}` with `autoShareTrips: true`, or on `PUT /safety/auto-share` with a `contactId`. Adding a contact without picking her sends nothing. Picking the same contact again sends nothing more.

`GET /testing/safety/contact-messages` shows it, as before.

## 8 · Test number

**`01000000000`** (`+201000000000`) is a reserved test number. Add it as a trusted contact and pick it for auto-share like any other. **No SMS is ever sent to it**, for the "added you" notice or for a live-trip share. The message shows as `SENT`, so "Shared with {name}" works in G-PF-2 and G-SF-11.

The list is a server setting (`RAFEEQ_SILENT_PHONES`, comma-separated); ask us to add more.

## 9 · Coupons

Checked: no booking, cancel, no-show or trip field, and no notification text about a ride's outcome, mentions or promises a coupon.

Two places still mention coupons, both **after staff issue one from the dashboard**, and we left them on purpose:

- The notification `coupon_issued` ("You have a coupon …"), sent when staff issue it.
- `coupons[]` on `GET /account/debt`, the coupons she was given.

If you want either gone, tell us and we remove it.

## 10 · Error responses

Standing rule, noted. **New this round:** `BOOKING_MOVES_FROZEN_PICKUP` (409, item 2).

If you know another failure that still arrives as a generic code or only as a message, send us the call and we add a code.
