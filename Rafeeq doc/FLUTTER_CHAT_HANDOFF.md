# Chat: handoff to the mobile team

The chat backend is finished: three threads, the call button, reporting, and live delivery over Reverb. The app still runs every thread on `ChatDemoStaticSource`. This file is everything you need to replace it.

**How this was checked:**
- All responses below were captured on 2026-11-07 from the real API in a local test run. The test clock is frozen, so dates show September and October 2026. Names and numbers are test data.
- The socket events were received by a real WebSocket client from a real Reverb server, using the real `/broadcasting/auth`. That run used a seat-request thread; a booking thread is covered by automated tests only.
- **None of this has been run on live yet.** Ask the backend team when Reverb is on there (see [Before you start](#before-you-start)).

The API guide has the longer reference: [RAFEEQ_MOBILE_API_GUIDE.md](RAFEEQ_MOBILE_API_GUIDE.md), sections 4.15 and 6.3.

## Before you start

Get these from the backend team for each environment:

| Value | Note |
|---|---|
| `REVERB_HOST` | Public host, for example `api.example.com` |
| `REVERB_PORT` / scheme | `443` / `wss` behind nginx |
| `REVERB_APP_KEY` | The key only. **The secret never goes in the app.** |

Locally, the backend must have `BROADCAST_CONNECTION=reverb`, and both `php artisan reverb:start` and `php artisan queue:work` must be running. With `log`, the API works but nothing reaches the socket.

## Rules the app must keep

- **No Messages tab.** Chat always opens from the request, booking or waiting-list place it's about.
- **Chat is not consent.** Never add an "agree" button to a message. Points change only through a counteroffer or a pickup-change request.
- **Never tell someone they were blocked.** A blocked pair gets the same `CHAT_NOT_OPEN` and `canSend: false` as a closed thread, so show the same text.
- **Don't compute the window.** Read `meta.opensAt`, `meta.closesAt` and `meta.canSend`.
- **Never show or store the phone number.** Pass the result of `/call` straight to the dialler.

## The three threads

| Thread | Between | Open while | API |
|---|---|---|---|
| Seat request | The passenger and the driver she asked | `PENDING` or `COUNTEROFFERED` | `GET` / `POST /seat-requests/{id}/messages` |
| Waiting list | A member and the driver | The same thread while `WAITLISTED` | Same as above |
| Booking | A confirmed passenger and her driver, for that day | 24 h before departure until 2 h after the run | `GET` / `POST /bookings/{id}/messages` |

When a request is approved its thread closes and the booking thread takes over. **The history doesn't carry over**, so keep the entry point on the request card. A closed request thread can still be read.

## Where chat opens

### Passenger

| Screen | Button | Thread |
|---|---|---|
| `requestSentScreen` | Message the driver | Seat request |
| `bookingDetailScreen` | Footer **Message**, and the driver card | Booking |
| `waitlistDetailScreen` | Message | Seat request |
| `offerDetailScreen` | Message | Seat request |
| `proposalScreen` | Ask the driver | Booking |
| `appNavBarScreen` → Home, next-commute card | Chat | Booking |
| `activeTripScreen` | Chat (**hidden today, turn on**) | Booking |
| `pastTripScreen` | Chat history, read-only | Booking |

### Driver

| Screen | Button | Thread |
|---|---|---|
| `appNavBarScreen` → Requests, request cards | Chat | Seat request |
| `appNavBarScreen` → Requests, waiting-list cards | Chat | Seat request |
| `driverNavScreen`, passenger card | Chat (**hidden today, turn on**) | Booking |
| `driverSummaryScreen` | Chat history, read-only (**hidden today, turn on**) | Booking |
| `driverRequestPreviewScreen`, `driverCounterScreen` | Chat. A suggestion, **not in the design**: check with design first | Seat request |

Driver Home's chat button stays off. It does nothing in the design either.

## What to build

1. A data source and repository in `lib/feature/chat/data/` for the three threads, the call and the report.
2. `ChatArguments` that take a `bookingId` or a `seatRequestId` instead of demo thread keys.
3. One socket connection per signed-in user (see [Live delivery](#live-delivery-reverb)).
4. The thread states from `meta`:

   | State | How to tell | Show |
   |---|---|---|
   | Open | `canSend: true` | The input box |
   | Not open yet (booking) | `canSend: false`, now is before `opensAt` | "Chat opens 24 hours before the trip" |
   | Over (booking) | `canSend: false`, now is after `closesAt` | History, read-only |
   | Answered (request) | `canSend: false`, `requestStatus` is no longer live | History, read-only |
   | Anything else | `canSend: false`, including inside the window | History and a neutral "You can't send messages here". This is the blocked and cancelled case, so don't explain why. |

5. On `CHAT_CONTACT_INFO_NOT_ALLOWED`, keep the text in the box and show `error.message`.
6. Turn on the hidden buttons.
7. Mute the thread's own pushes while it's open. `AppPushNotifications.muteChat(bookingId)` takes a booking id today, and **seat-request pushes carry `seatRequestId` with no `bookingId`**, so it needs to handle those too.
8. **Report message** on the other person's messages only.
9. Polling while the thread is open and the socket is down.

## REST API

Base URL: `{host}/api/v1`, with a bearer token. Limits: 1–500 characters, 20 messages a minute (`429` with `Retry-After`). All limits can change on the server.

### Read a thread

`GET /seat-requests/{id}/messages` or `GET /bookings/{id}/messages`. Newest first; supports `page` and `perPage`.

Opening a thread marks the other person's messages read. Messages read by this same request still show `readAt: null` in its response, and `readAt` only matters on your own messages.

**Seat request** → `200`

```json
{
  "success": true,
  "data": [
    {
      "id": "01m6vbd6naax0b11m8eay8pvet",
      "mine": false,
      "body": "ممكن أقابلك عند البوابة ٢؟",
      "containsContactInfo": false,
      "readAt": null,
      "sentAt": "2026-09-26T05:00:00+00:00"
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 1,
    "lastPage": 1,
    "hasMore": false,
    "canSend": true,
    "requestStatus": "PENDING"
  }
}
```

After approval, the same call returns the history with `"canSend": false` and `"requestStatus": "APPROVED"`.

**Booking, inside the window**: the `meta` → `200`

```json
{
  "page": 1,
  "perPage": 1,
  "total": 2,
  "lastPage": 2,
  "hasMore": true,
  "canSend": true,
  "opensAt": "2026-09-25T05:30:00+00:00",
  "closesAt": "2026-09-26T19:30:00+00:00"
}
```

**Booking, before the window** → `200`

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 0,
    "lastPage": 1,
    "hasMore": false,
    "canSend": false,
    "opensAt": "2026-10-25T04:12:00+00:00",
    "closesAt": "2026-10-26T18:12:00+00:00"
  }
}
```

### Send a message

`POST /seat-requests/{id}/messages` or `POST /bookings/{id}/messages` with `{ "body": "..." }` → `201`

```json
{
  "success": true,
  "data": {
    "id": "01m6vbd753pz9z355hs6x2ypzc",
    "mine": true,
    "body": "أنا قريب، عربية كيا بيضا",
    "containsContactInfo": false,
    "readAt": null,
    "sentAt": "2026-09-26T05:00:00+00:00"
  }
}
```

**Phone number or email** → `422`

```json
{
  "success": false,
  "error": {
    "code": "CHAT_CONTACT_INFO_NOT_ALLOWED",
    "message": "Phone numbers and email addresses cannot be sent in trip chat. Keep the conversation here, where the platform can protect you both.",
    "fields": {
      "body": [
        "Phone numbers and email addresses cannot be sent in trip chat. Keep the conversation here, where the platform can protect you both."
      ]
    }
  }
}
```

**Empty body** → `422` `VALIDATION_FAILED`, with the error in `error.fields.body`.

**Booking thread closed** → `409`

```json
{
  "success": false,
  "error": {
    "code": "CHAT_NOT_OPEN",
    "message": "Messages for this trip are not open right now.",
    "fields": {
      "opensAt": ["2026-10-25T04:12:00+00:00"],
      "closesAt": ["2026-10-26T18:12:00+00:00"]
    }
  }
}
```

**Request thread closed** → `409`. A blocked pair gets the same response with the request's current status, for example `"pending"`. Note that `status` is lowercase here and `meta.requestStatus` is uppercase.

```json
{
  "success": false,
  "error": {
    "code": "CHAT_NOT_OPEN",
    "message": "Messages for this trip are not open right now.",
    "fields": {
      "status": ["approved"]
    }
  }
}
```

On any `CHAT_NOT_OPEN`, keep the typed text and refetch the thread to update the state.

### Report a message

`POST /messages/{message}/report` with `{ "reason": "..." }` (3–500 characters) → `201`

```json
{
  "success": true,
  "data": {
    "incidentId": "01m6vbd795n4qpx6fvfgw954de"
  }
}
```

Reporting your own message returns `404 NOT_FOUND`, so show the action only when `mine` is `false`.

### Call

`GET /bookings/{booking}/call` → `200`

```json
{
  "success": true,
  "data": {
    "phone": "+201012345678",
    "name": "مريم"
  }
}
```

| Caller | When |
|---|---|
| Passenger → driver | From the booking's acceptance until her seat stops being live. A finished ride still counts. |
| Driver → passenger | Only the **next** passenger to pick up, only after the run has started, until she is collected. |

Otherwise `409 CALL_NOT_AVAILABLE` with `error.fields.reason`: `booking_not_live`, `trip_not_started` or `not_next_pickup`. Someone who isn't on the booking gets `404`. These refusals come from the API guide and weren't captured in this run.

### Push and inbox

A chat notification says who wrote, never what. Its `type` is `chat_message`, and `data` says which thread to open.

Booking:

```json
{
  "type": "chat_message",
  "category": "trip",
  "title": "New message from مريم",
  "body": "Open the trip to read it.",
  "data": { "bookingId": "01m6vbd6xd8pfsh1gd2vgyf2fv" }
}
```

Seat request:

```json
{
  "type": "chat_message",
  "category": "trip",
  "title": "New message from سارة",
  "body": "Open the trip to read it.",
  "data": {
    "seatRequestId": "01m6vbetv3qccy4xc30d18qzyp",
    "commuteId": "01m6vbesgj2q2tt0e90brnmvv6"
  }
}
```

## Live delivery (Reverb)

Reverb speaks the Pusher protocol, so use a Flutter Pusher client that lets you set a custom host and auth endpoint. Keep **one connection per signed-in user** and subscribe to **one channel**, not one per thread:

```
private-user.{userId}     userId = user.id from GET /auth/me
```

### Connecting

1. Open `wss://{REVERB_HOST}:{port}/app/{REVERB_APP_KEY}?protocol=7&client=flutter&version=1`.
2. The server sends `pusher:connection_established`, with `socket_id` inside its `data`.
3. Call `POST {host}/broadcasting/auth`. **This path has no `/api/v1`.** Send your bearer token and:

   ```json
   { "socket_id": "<socket_id>", "channel_name": "private-user.<userId>" }
   ```

   The response is `{ "auth": "<key>:<signature>" }`. Any channel except your own gets `403`.
4. Send this over the socket:

   ```json
   { "event": "pusher:subscribe", "data": { "channel": "private-user.<userId>", "auth": "<auth>" } }
   ```

5. Wait for `pusher_internal:subscription_succeeded`. Each event's `data` arrives **as a JSON string**, so decode it. Answer `pusher:ping` with `pusher:pong` if your client doesn't do it for you.

On sign-out, disconnect. On token refresh, reconnect so the next subscription uses the new token.

### `chat.message`

Goes to the receiver only, never to the sender. Exactly one of `bookingId` and `seatRequestId` has a value, `message` matches the API's message shape, and `mine` is always `false`.

```json
{
  "id": "01m6vaqw84pbj9dggp6pfq217g",
  "type": "chat.message",
  "occurredAt": "2026-09-26T05:00:00+00:00",
  "payload": {
    "bookingId": null,
    "seatRequestId": "01m6vaqqyt88gvhkymy2fq86ch",
    "message": {
      "id": "01m6vaqw84pbj9dggp6pfq217g",
      "mine": false,
      "body": "أنا عند البوابة ٢",
      "containsContactInfo": false,
      "readAt": null,
      "sentAt": "2026-09-26T05:00:00+00:00"
    }
  }
}
```

### `chat.read`

Goes to the person who wrote the messages. Everything they wrote in that thread is now read.

```json
{
  "id": "01M6VAQX2GHSTZ380Z2928V6TH",
  "type": "chat.read",
  "occurredAt": "2026-09-26T05:00:00+00:00",
  "payload": {
    "bookingId": null,
    "seatRequestId": "01m6vaqqyt88gvhkymy2fq86ch",
    "readAt": "2026-09-26T05:00:00+00:00"
  }
}
```

### What to do with each event

| When | Do this |
|---|---|
| `chat.message` for the thread on screen | Add it, skipping any `message.id` you already have. Then call `GET .../messages?perPage=1`: **the socket doesn't mark anything read**, and that call is what marks it and sends the other person `chat.read`. |
| `chat.message` for another thread | Ignore it. The push and inbox still cover it. |
| `chat.read` for the thread on screen | Set `readAt` on your messages that don't have one. |
| Socket reconnects, or the app returns to the foreground | Refetch the open thread. **Events missed while disconnected aren't replayed.** |
| Socket is down and a thread is open | Poll `GET .../messages`. The API is the source of truth. |

### What isn't on the socket

- **Your own message.** It comes back in the `201`, so a second phone on the same account sees it only on its next fetch.
- **A thread closing.** Read `meta.canSend` when you fetch, and handle `409 CHAT_NOT_OPEN` when you send.

Messages go through the queue, so expect up to about 3 seconds' delay, and more when the queue is busy.

## Test it

1. Open the same thread on two devices, one as the passenger and one as the driver.
2. Send a message each way. It should appear with no refresh, and the sender's ticks should change.
3. Send `01012345678`. The text should stay in the box with the server's message.
4. Turn off Wi-Fi on one device, send from the other, then reconnect. The message should appear after the refetch.
5. Stop the socket server. The thread should keep working through polling.
6. Block the other person. Both sides should see the neutral read-only state, with no mention of blocking.
7. Approve a request. Its thread should turn read-only and the booking thread should start empty.
8. With a thread open, confirm its own push doesn't pop up, on both a booking thread and a request thread.

## Still open

- **Q-054 (calls and chat):** confirm the real number going to the dialler, with no masking, for the beta.
- **Retention:** product docs say chat is kept 90 days, but the server currently keeps it 12 months. This doesn't change anything in the app. The backend team is settling it.
