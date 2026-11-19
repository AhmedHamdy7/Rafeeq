# Answer: `/my-bookings` still empty after a recurring approval (2026-11-19)

Answer to `backend-my-bookings-500-not-deployed-2026-11-19.md`.

## Short answer

**Your 02:44 and 02:48 runs came before the fix was live.** The fix is commit `72b7adf` on `main`, pushed at **02:47 UTC**. The test server deploys from `main`, and a deploy takes a few minutes after the push.

- `g117` at 02:44 ran 3 minutes before the push.
- `g146` at 02:48 ran 1 minute after the push, before the new build was serving.
- `g59` at 02:51 passed, which is consistent with the deploy having finished by then.

**Please re-run `g117`, `g146` and `g187`.** If any of them fails, send the time (UTC) and we'll check that run.

## What we checked

We rebuilt your exact `g117` setup as a backend test:

1. A commute for one weekday, three days out.
2. Mariam's recurring request for that weekday, approved by Omar.
3. `GET /my-bookings?scope=upcoming` as Mariam.

| Code | Answer |
|---|---|
| Before `72b7adf` | `500`, the same crash as the first report |
| `72b7adf` and later | `200`. Every booking the approval created is `CONFIRMED` and listed, with `commuteGroupId` set |

The test stays in the suite (`tests/Feature/Booking/PatrolServerFixesNovember19Test.php`), so this can't come back unnoticed.

**Why your snapshot showed "no rows":** a `500` has no `data` array, so a snapshot that reads the rows sees an empty list. That matches your note. It also explains the `404` in `g146`: the group id was taken from that empty list.

**Server log:** we can't read the test server's log for 02:44 and 02:48 from here, and our error tracker has no entries for it. The timing and the test above are what we're going on.

## Tip for the tests

Assert the status code of `GET /my-bookings` (and other setup calls) before reading rows. A `500` then fails the setup with its real cause, instead of showing up later as "no rows" or a `404`.

## Finding 33

Noted: `conflictingBookingId` is sent on the seat offer, and the missing note is fixed in the app. Nothing to do on the server.
