# Chat: answers to the mobile team's requests

**Date:** 2026-11-09 · **Answers:** `chat-backend-requests.md` (mobile repo, `docs/integrations/`) · **Shapes:** `FLUTTER_CHAT_HANDOFF.md` and section 4.15 of `RAFEEQ_MOBILE_API_GUIDE.md`

## 1. Live delivery

Not confirmed yet. We need to run the two-account test with you on production.

What your checks already tell us:

- **Production broadcasts through Reverb.** Your `403` for another user's channel shows it. With `BROADCAST_CONNECTION=log`, `/broadcasting/auth` checks nothing and returns `200` for every channel.
- **Events go through the queue.** `chat.message` and `chat.read` are queued broadcasts, so they reach the socket only while a worker runs: `RAFEEQ_RUN_WORKERS=true` on the web container, or a separate `queue:work` service. We still have to confirm this on Railway. If the worker is down, pushes stop too, not only the socket.
- **Delay:** up to about 3 seconds on a busy queue, as the handoff says. We'll measure it in the test.

Keep the 10-second polling fallback.

## 2. The other person: done

Both `GET /seat-requests/{id}/messages` and `GET /bookings/{id}/messages` now return:

```json
"meta": {
  "canSend": true,
  "requestStatus": "PENDING",
  "otherParty": {
    "userId": "01m40k6whhbr6k1ym5ptrn059y",
    "publicFirstName": "داليا",
    "trustLevel": 3,
    "verifiedLevels": ["phone", "government_id", "selfie"],
    "sameOrganisation": false,
    "rating": 4.9,
    "ratingCount": 27,
    "completedTrips": 41,
    "onTimeRate": 0.96,
    "role": "driver"
  },
  "commute": {
    "originLabel": "Rehab Gate 2",
    "destinationLabel": "Smart Village B6",
    "departureAt": "2026-11-09T04:40:00+00:00"
  }
}
```

- `otherParty` is the same person card used on every other screen. `role` is the other person's role in this thread.
- `rating` is `null` until the person has been rated, and `ratingCount` is `0` then.
- **No `profilePhotoUrl`.** The API doesn't publish a photo for anyone yet, so draw initials from `publicFirstName`, as on the other screens.
- `commute.departureAt` is `null` on a request that isn't for one dated trip.
- There is never a phone number, full name or gender.

Opening a chat from its push now works: take `bookingId` or `seatRequestId` from the push, call the thread, and read the name and route from `meta`.

## 3. Q-054: the number for Call

Confirmed for the beta (D-0038, SAFE-18). `GET /bookings/{id}/call` returns the real number, and the app passes it to the dialler without showing or storing it. There is no masking or relay number. Masking is still open in Q-054 for after the beta.

## 4. Retention: 90 days

Final: **90 days** (TRIP-28, SAFE-19). The server kept messages for 12 months, which was wrong. It now deletes them after 90 days. A reported message is copied into the report, so a report keeps its evidence after the message is deleted.

## 5. Nice to have

- **History after approval: done in a simple way.** The booking thread's `meta` now has `seatRequestId`, the request the booking came from (`null` if there isn't one). That request thread stays readable, so the app can load it and show it above the booking thread as earlier messages. The two threads stay separate on the server.
- **System events: done, as `meta.events` instead of items in `data`.** Both threads return `meta.events`, oldest first, for example `[{ "event": "picked_up", "at": "…" }]`. Merge them with the messages by time and write the wording in the app. They sit in `meta` so that `data` stays messages only and pagination doesn't change. The values follow the design: `request_sent`, `waitlist_joined`, `counteroffered`, `booking_confirmed`, `driver_arrived`, `picked_up`, `ride_completed`. The ride moments (`driver_arrived`, `picked_up`, `ride_completed`) also arrive live on `chat.event` to both people. The full table and shapes are in `FLUTTER_CHAT_HANDOFF.md`.
- **Typing indicator: done.** Call `POST /bookings/{id}/typing` or `POST /seat-requests/{id}/typing` (no body, `202`) while the user types, at most every 3 seconds. The other person gets `chat.typing` with `{ bookingId, seatRequestId }` on `private-user.{id}`. It goes straight to the socket, not through the queue, so it isn't late. The server sends at most one per person per thread every 3 seconds, and extra calls get `202` without sending anything. There is no "stopped" event: hide the bubble after 5 seconds with no new `chat.typing`, or when their message arrives. A closed thread or blocked pair gets `409 CHAT_NOT_OPEN`, the same as sending.

## Your questions

1. **Case: stable.** `meta.requestStatus` is always uppercase, and `error.fields.status` on `CHAT_NOT_OPEN` is always lowercase.
2. **`429` on send: yes.** It always has `Retry-After`, in seconds. A test now checks it.
3. **A thread closing:** nothing on the socket, and that isn't changing. Keep refetching on `CHAT_NOT_OPEN`, and read `meta.canSend` on every fetch.
