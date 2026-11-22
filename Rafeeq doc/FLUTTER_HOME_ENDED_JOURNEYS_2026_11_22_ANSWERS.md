# GET /home — ended journeys no longer walk through one at a time (HOME-1)

Backend change, no new fields and no new endpoints. Home now sees at most one old ended journey instead of all of them in a row.

## What changed

When rule ② of `nextJourney` applies (a journey that ended and she has not seen: cancelled by the driver, expired, exited without fee, or completed and not rated), the server now:

1. Returns only the newest one, sorted by when it ended (`cancelledAt` for cancelled, expired and exited; the check-out or run end for completed, falling back to departure).
2. Stamps every older unseen ended booking of hers as seen (`outcomeSeenAt` is set by the server). The app never receives them.
3. If the newest one ended more than 24 hours ago, stamps it as seen too and does not return it. `/home` then goes straight to the soonest upcoming confirmed journey, or to a completed ride waiting for stars, or to `nextJourney: null`.

The 24 hours is a server setting (`RAFEEQ_HOME_ENDED_FRESH_HOURS`) and can change without an app release. The 7-day upper bound stays.

## What does not change

- Rule ① (happening now) and rule ③ (soonest upcoming) keep their priority over ②.
- A completed ride still inside its rating window is returned by rule ④ with `canRate: true`, even when it is older than 24 hours and was stamped seen. `canRate` comes from the rating window, not from `outcomeSeenAt`.
- `POST /bookings/{booking}/seen` is idempotent, keeps the first timestamp, and keeps its 404 and 409 behaviour.
- A journey she cancelled herself is never shown on Home.
- A fresh ended journey (inside the cutoff) returns the full card: `phase`, `endedAt`, `cancellationFeePiastres`, `alternatives[]`.
- Every stamped booking is still listed by `GET /my-bookings?scope=past`.

## What the app should do

Nothing new is required. Keep calling `POST /bookings/{id}/seen` when she dismisses the ended card, as before.

Things to stop doing or stop expecting:

- Do not loop on `/home` to clear old ended journeys. One refresh is enough, and a passenger returning after a week or on a new phone lands directly on her current state or an empty Home.
- Do not treat `isSeen: true` on a returned card as an error. A completed ride that was stamped seen can still come back through rule ④ with `canRate: true`; show the rating card as usual.
- An ended journey that disappears from Home without a `seen` call from this device is expected. Point the passenger to Trips History (`GET /my-bookings?scope=past`) for old outcomes.

## Cases

| Her bookings | `/home` `nextJourney` |
|---|---|
| 5 ended-unseen, all older than 24h, nothing upcoming | `null`, no `seen` calls needed |
| Same, plus one confirmed upcoming booking | the upcoming booking |
| Ended-unseen 3h old and another 2 days old | the 3h one; the 2-day one is stamped and never returned |
| One ended-unseen 3h old | returned; after `seen`, the next `/home` moves on |
| Completed ride 30h old, inside the rating window, nothing upcoming | returned by rule ④ with `canRate: true` |
