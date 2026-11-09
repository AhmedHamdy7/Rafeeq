# Chat: answers to the mobile team's follow-up

**Date:** 2026-11-09 · **Answers:** `chat-backend-followup.md` (mobile repo, `docs/integrations/`) · **Earlier answers:** `FLUTTER_CHAT_GAPS_ANSWERS.md`

## 1. The two-account live test: passed

**Confirmed on production on 2026-11-09.** The queue worker runs on Railway, and the live events reach the other account over Reverb.

If live delivery stops later, this tells you where to look. `chat.typing` goes straight to the socket. `chat.message`, `chat.read` and `chat.event` go through the queue. So:

| What arrives | What it means |
|---|---|
| `chat.typing` and `chat.message` | The socket and the worker both work. |
| `chat.typing` only | Reverb works, but the worker is down. Pushes are stopped too. |
| Nothing | Reverb isn't reachable, or the app isn't subscribed to `private-user.{id}`. |

### Queue delay

When the queue is empty, the worker checks it every 3 seconds (`--sleep=3`), so a queued event takes up to about 3 seconds. Typing isn't queued and doesn't wait.

## 2. Call on request chats: confirmed

Keep it as it is. No `/seat-requests/{id}/call` will be added. Calls start at booking acceptance (D-0038, SAFE-18), and a request hasn't been accepted. Removing Call from request and waiting-list chats is the right change to the design.

**One more thing for booking chats.** Call isn't always available on an open booking chat either:

| Caller | When |
|---|---|
| Passenger → driver | From the booking's acceptance until her seat stops being live. |
| Driver → passenger | Only the next passenger to pick up, after the trip has started, until she is picked up. |

So on the driver's side, `/call` returns `409 CALL_NOT_AVAILABLE` most of the time, with `reason`:
- `trip_not_started` before the trip starts;
- `not_next_pickup` for anyone who isn't the next pickup;
- `booking_not_live` once the booking has ended.

Show Call on open booking chats, and when the `409` comes back, explain its `reason` instead of showing a general error.
