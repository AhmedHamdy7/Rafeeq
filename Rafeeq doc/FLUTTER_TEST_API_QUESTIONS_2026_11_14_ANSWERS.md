# Answers — the testing API (2026-11-14)

Answers to `backend-test-api-questions-2026-11-14.md`. Both errors are fixed: Q-10 and Q-11 were bugs, not
wrong calls from the app. The fixes reach the test server with the next deploy.

| # | Answer |
|---|---|
| Q-1 | 16 job names, all in guide §4.17. An unknown name answers `404 NOT_FOUND` |
| Q-2 | The server runs in staging mode under a production name. ⏳ Turning it off and adding a staging URL are not decided yet |
| Q-3 | Already done: §4.17 now documents all 18 calls |
| Q-4 | `PUT /testing/instapay` was not built. Take it out of your notes |
| Q-5 | A placed point counts exactly like a real one |
| Q-6 | ⏳ Not built. Keep it a manual check for now |
| Q-7 | The only lock left is 3 wrong codes → 5 minutes. Read `error.code` to tell which limit you hit |
| Q-8 | ⏳ Not built |
| Q-9 | Safe, with two exceptions: `match-demands` and the daily jobs |
| Q-10 | ✅ Fixed. It failed whenever the ID or selfie was still waiting for review |
| Q-11 | ✅ Fixed. Karim's account needs no repair |

## Q-1 · Job names

`POST /testing/jobs/{name}/run` takes:

| Name | What it does |
|---|---|
| `generate-trips` | Creates the days of each commute up to the booking horizon, and archives commutes past their end date |
| `roll-memberships` | The daily membership step. It expires old requests, ends leave (paused seats) whose dates are over, and seats recurring members on the new days. **This is the weekly extension of recurring seats and the end of a paused seat** |
| `match-demands` | Matches every active saved request (demand) against the published commutes |
| `expire-demands` | Expires saved requests past their end date |
| `expire-requests` | Expires seat requests past `expiresAt` (the 3-minute ones), and one-day requests whose day has passed |
| `reveal-ratings` | Reveals one-sided ratings whose 7-day window has closed |
| `rating-reminders` | "Last day to rate", in the last 24 hours of the window |
| `trip-reminders` | "Leaves in an hour" / "in 10 minutes" |
| `auto-finish-trips` | Finishes runs well past their promised arrival, with nobody aboard and nothing waiting on a decision |
| `sweep-delays` | Handles mornings that never started (+1 minute, warning at +5) |
| `settle-cash` | Settles cash seats the drop-off didn't settle |
| `sweep-debts` | Late fees, debt reminders and debt suspensions |
| `warn-licences` / `stop-licences` | Licence "expiring soon" push / stops expired licences |
| `warn-registrations` / `stop-registrations` | The same for car registrations |

Any other name answers `404 NOT_FOUND`. The call answers `{job, command, exitCode, output}`.

## Q-2 · Production or staging

- **What the server is today.** The testing calls only work when the server is *not* in production mode. The
  code checks the environment itself, not the setting. Because they work on `rafeeq-production-5fb1`, that server
  runs in staging mode (`APP_ENV=staging`). It has a production name but is not a production server.
- That server also has the OTP request limits and the dashboard's authenticator code turned off. Both switches
  stop working once the server runs in production mode.
- ⏳ **Not decided yet:** whether this server moves to production mode at launch (which turns the testing calls
  off whatever `RAFEEQ_TESTING_API` says), and whether a separate staging URL is added for the tests. We'll send
  you the answer and the date. Adding the build flag for the test URL now is the right move.

## Q-3 · All the testing calls in the guide

§4.17 now documents all 18 calls: `driver/approve`, `DELETE account`, `departure`, `position`, the car
approve/reject, the payment confirm/reject, `wait-timers/expire`, `debt/deadline` and `debt/age`, `suspend` and
`reinstate`, `documents/{key}/expiry`, `bookings/{booking}/age`, `jobs/{name}/run` and `attendance`. Each entry
gives its body, what it answers and its errors. The intro line still said "All three", and it now says "All of
them". Who may call them is the same for every call: any signed-in account, acting only on its own account,
commutes and bookings. `jobs/{name}/run` is the one exception (see Q-9).

## Q-4 · `PUT /testing/instapay`

It doesn't exist. The payee comes only from the dashboard settings, and `GET /account/debt` returns it as
`instapay`.

## Q-5 · How long a placed car counts

`/testing/trips/{trip}/position` sends its point through the same code as a point from the driver's phone,
timed *now*, with 5 m accuracy. So:

- **It ages out after 2 minutes**, like a real point. Call it again just before "I've arrived" if more than 2
  minutes have passed.
- **The passenger sees it** in `GET /trips/{trip}/location` and in `/home` → `nextJourney.live`, and the live
  location event goes out.
- **ETAs and distances** are worked out from the latest point, whichever way it arrived, so they move as they
  would for a real point.
- ⚠️ **The driver's phone can overwrite it.** If the test phone is still sending GPS, its next point is newer
  and replaces the placed one. Stop location sending on the driver's phone in tests that place the car.

## Q-6 · A push on demand

⏳ Not built, and not decided yet. Keep `pickup_change_requested` as a manual check for now. We'll tell you if
`POST /testing/push` is added.

## Q-7 · The OTP lock for test numbers

The test numbers can't skip the lock. These are the rules on the test server:

| Limit | On the test server | `error.code` |
|---|---|---|
| 5 requests per hour per number, 20 per hour per IP | **Off** | `TOO_MANY_REQUESTS` |
| 45-second resend wait, 3 resends at most | **Off** | `AUTH_OTP_RESEND_COOLDOWN` / `AUTH_OTP_MAX_RESENDS` |
| **3 wrong codes → that number is locked for 5 minutes** (for that purpose) | **On** | `AUTH_OTP_LOCKED` |

All three answer `429`. With the first two off, the `429` you see should be `AUTH_OTP_LOCKED`, which means a
test typed a wrong code 3 times. To stay under it, always type the right code. If a test checks the wrong-code
error, use one throwaway number for it, apart from the cast. If you see `TOO_MANY_REQUESTS` instead, tell us:
it would mean the limits are on again on that server.

## Q-8 · Reset the caller's own data

⏳ Not built, and not decided yet. Until then, the only full reset is `DELETE /testing/account`, which also
removes the verification. Since Q-11 is fixed, deleting passengers is safe again.

## Q-9 · `jobs/{name}/run` on a shared server

Each job acts only on records that are already due by their own dates and times. It never expires, charges or
suspends something early. Every job except one is also on the server's own schedule (every minute to daily), so
calling it does now what the schedule would do soon anyway.

- `expire-requests` only expires requests already past `expiresAt`. It runs every minute anyway.
- `sweep-debts` only fines or suspends accounts that are really overdue. It runs hourly anyway. Your
  `debt/deadline` and `debt/age` calls only move *your* debt lines, so other people are never pushed over a line.
- **`match-demands` isn't on the schedule.** Calling it sends match pushes to *everybody's* active saved
  requests that weren't matched before. It only adds rows once, so repeats send nothing new. Call it only when a
  test needs it.
- **The daily jobs** (`generate-trips`, `roll-memberships`, `warn-*`, `stop-*`) run earlier than their usual
  time when called. They are safe to repeat, but `roll-memberships` seats every recurring member on the new
  days, and `stop-licences` / `stop-registrations` stop anybody whose document really expired.

## Q-10 · `POST /testing/driver/approve` answered `500`

**Cause:** it failed whenever the ID or the selfie was still waiting for review. Our own test had them approved
before submit, so it never hit this. The app's order (all five items sent, then submit, nothing approved yet) is
correct and is what a real driver does.

**Fixed.** The call now approves the driver from exactly the state you described. The call itself is unchanged:
same body, same answer.

## Q-11 · Driver Home answered `500` after a deleted passenger

**Cause:** a deleted member's past seats and requests stay on the driver's commute (the record outlives the
person), but the lists couldn't load the deleted person and failed. A real `DELETE /account` did exactly the same
thing.

**Fixed** for every list that shows the other person. That includes `GET /driver/home` (`notices`),
`GET /driver/seat-requests`, bookings, wait timers and attendance, and the passenger's view of a deleted driver.
A deleted member now appears with `publicFirstName: null`. Show them as "Deleted account" or your usual
fallback. Nothing else about them is sent.

**Karim (`+20 10 5550 1001`):** his data was never damaged. Only reading it failed. His Home and requests load
again after the deploy, with no repair.
