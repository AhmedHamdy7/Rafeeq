# Answers: what the designer's frames of 2026-11-16 needed (2026-11-16)

Answers to `backend-designer-followups-2026-11-16.md`. Everything below reaches the test server with the next deploy. Guide sections: 4.11 (Home, `ratingPrompt`), the Trips list (`GET /my-bookings`), 4.12 (live shares), 4.17 (testing calls), 6.3 (socket).

| # | Answer |
|---|---|
| F1 | ✅ Built: `ratingPrompt.unusualKind` |
| F2 | ✅ Built: `ratingPrompt.pickupLocal` and `ratingPrompt.pickupLabel` |
| F3 | ✅ Built, option **A**: `groupId` and `includeCancelled=1` on `GET /my-bookings`. No new route |
| F4 | Confirmed: `event` is `account.banned`, `data` is the envelope (a JSON string), channel `private-user.{id}` |
| F5 | Confirmed: `sharedWithContactId`. Also new: `sharedWithName` |
| F6 | ✅ Built: `POST /testing/bookings/{booking}/rating-prompt` |
| F7 | `POST /testing/account/ban` already broadcasts. Also built: `POST /testing/realtime/account-banned` |

## F1 · `ratingPrompt.unusualKind`

```json
"ratingPrompt": { "bookingId": "01…", "tripId": "01…", "driverName": "Ahmed",
                  "tripDate": "2026-11-16", "pickupLocal": "07:05", "pickupLabel": "Rehab Gate 2",
                  "reason": "unusual", "unusualKind": "late" }
```

- Values: `dispute` · `report` · `late` · `ended_early`.
- Set only when `reason` is `unusual`, `null` otherwise.
- When several apply, the first in your order: `dispute` > `report` > `late` > `ended_early`.
- What each means on the server:
  - `dispute`: she disputed the attendance on that booking.
  - `report`: a safety report was filed on that booking.
  - `late`: the driver reached her pickup more than 5 minutes after her agreed time (or a driver-late record exists for that run).
  - `ended_early`: the booking is `ENDED_EARLY`.

## F2 · Time and pickup on `ratingPrompt`

- `pickupLocal`: her agreed pickup time that day, commute wall clock (Cairo), `HH:mm`. Same time she saw on her Home card. `null` only if the run has no departure.
- `pickupLabel`: her meeting point's label. `null` when the booking has none. A rated booking is always `COMPLETED` or `ENDED_EARLY`, and both show the exact place, so you won't see it hidden for privacy here.

So the line is `{tripDate} · {pickupLocal} · {pickupLabel}`, dropping any part that is `null`.

## F3 · Her next days in one group

Option A, on the call you already make:

```
GET /my-bookings?scope=upcoming&groupId={group}&includeCancelled=1&perPage=3
```

- `groupId`: only bookings whose `commuteGroupId` is that group.
- `includeCancelled=1`: only with `scope=upcoming`. Also returns her own day cancels (`status: "CANCELLED_BY_PASSENGER"`) whose journey hasn't departed yet.
- Order: soonest departure first, cancelled days mixed in by date.
- Without `includeCancelled`, `scope=upcoming` is unchanged (`PENDING` and `CONFIRMED` only).
- A cancelled row is the normal booking resource. Its meeting point label is hidden like any non-confirmed booking, so take the label from the group or from before she cancelled if you need it.
- An unknown `groupId` returns an empty list, not an error.

You can stop keeping cancels in session memory. Show a row with `CANCELLED_BY_PASSENGER` as "Not coming · seat released", struck through.

## F4 · The `account.banned` frame

What arrives on the socket (Pusher protocol, via Reverb):

```json
{
  "event": "account.banned",
  "channel": "private-user.01JB…",
  "data": "{\"id\":\"01JB…\",\"type\":\"account.banned\",\"occurredAt\":\"2026-11-16T09:12:44+00:00\",\"payload\":{\"caseNumber\":\"RF-…\",\"permanent\":true,\"activeTripId\":\"01…\"}}"
}
```

1. **`event`** is exactly `account.banned`. No leading dot, no class name. The dot only matters in Laravel Echo's `.listen('.account.banned')`. On the raw frame there is none.
2. **`data`** is the envelope `{id, type, occurredAt, payload: {caseNumber, permanent, activeTripId}}`, not the payload directly. ⚠️ Pusher sends `data` as a **JSON string**, so decode it first, as you do for the chat events.
3. **Channel:** the same `private-user.{id}` the chat events use. No new subscription needed.

Your current rule (`event` or `data.type` is `account.banned`, read from `data.payload`) is right once `data` is decoded. Deduplicate on `data.id` like the other events.

## F5 · The contact on an automatic live share

- The key is **`sharedWithContactId`**. It is set on automatic shares (`source: "AUTO"`) as well as on manual shares to a named contact.
- New: **`sharedWithName`**, the contact's name, on every `GET /safety/live-shares` row (and on the share returned by `POST /trips/{trip}/live-share` and `DELETE /safety/live-shares/{share}`). `null` when no contact was named, or the contact was deleted since.

So you can show "Shared with {sharedWithName}" directly and skip the `GET /safety/emergency-contacts` lookup.

## F6 · Testing call: rating prompt of a given reason

```
POST /testing/bookings/{booking}/rating-prompt
{ "reason": "first_ride" | "weekly" | "unusual",
  "unusualKind": "late" | "ended_early" | "dispute" | "report" }    // required with unusual
→ 200 { "ratingPrompt": { …as on GET /home… } }
```

- Caller: the passenger, on her own booking.
- The booking must be `COMPLETED` or `ENDED_EARLY`, not rated by her, and still in the rating window. Otherwise **`409 BOOKING_NOT_RATEABLE`**.
- From then on `GET /home` prompts for that booking with that reason and kind, ahead of any other, until she rates it or calls `POST /bookings/{booking}/rating-prompt/dismiss`.
- Calling it again on the same booking replaces the reason. Calling it on another booking moves the forced prompt there.
- `422` if `reason` is `unusual` with no `unusualKind`, or a value is unknown. `404` if it isn't her booking.

Patrol flow: finish a ride (`duet_05`), then call this with `weekly` or `unusual` and read `GET /home`.

## F7 · Testing call: `account.banned` without a ban

**`POST /testing/account/ban` already broadcasts `account.banned`**, the same as a real ban, with `activeTripId` set when she is on a run. Use it for the full flow.

To test the event on its own, there is also:

```
POST /testing/realtime/account-banned   { "activeTripId"?: "01…" | null }
→ 202 { "sent": true }
```

- Sends the same event to the caller's `private-user.{id}`. Bans nothing and changes nothing else, so `GET /home` and `GET /auth/me` still show her as active.
- `caseNumber`: her latest case number, or `null` if she never had one.
- `activeTripId`: what you send. Leave it out to use the run she is on now (or `null` if none). Send `null` to force "no active trip".

## Errors added

| Code | Status | When |
|---|---|---|
| `BOOKING_NOT_RATEABLE` | 409 | F6, the booking can't be rated |

## Nothing changed for

- The rating-prompt rules themselves (`first_ride`, `weekly`, `unusual`), the dismiss call and when the prompt disappears.
- `GET /my-bookings` without the new parameters.
- The ban itself.
