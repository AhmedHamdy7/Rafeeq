# Return trips, round 3: backend answers (2026-11-24)

Answers `docs/feedback/2026-11-24-backend-requirements-return-trips-3.md` in the app repo. All the new
fields are additive, and nothing that already existed changed shape. The full detail is in
`RAFEEQ_MOBILE_API_GUIDE.md`, under "Change proposals" and "still-driving".

| # | Ask | Answer |
|---|---|---|
| 1 | Estimated arrival on a Return's change proposal | ✅ Added `estimatedArrivalTime` to `changes` / `previous` |
| 2 | Her own pickup time on the passenger's proposal | ✅ Added `myPickup` |
| 3 | Which days a leave-time change can still reach | ✅ Added `timeLocked` / `timeLockedAt`. ⚠️ A locked day is **not** refused (see §3) |
| 4 | When "Are you still driving?" fires on a Return | ✅ Confirmed; added `expectedArrivalAt` |

⚠️ **Time format:** every time on a proposal is `H:i:s` (`"17:15:00"`), the same as `promisedDepartureTime`
and `passengers[].previousPickupLocal`. Cut it to `HH:MM` for display.

---

## 1. `estimatedArrivalTime` on a Return proposal

```json
"direction": "return",
"changes":  { "promisedDepartureTime": "17:15:00", "departureTime": "17:15:00", "estimatedArrivalTime": "18:25:00", … },
"previous": { "promisedDepartureTime": "17:00:00", "departureTime": "17:00:00", "estimatedArrivalTime": "18:10:00", … }
```

- **Driver's payloads** (the create and revise answers, and `GET /driver/change-proposals`): the time
  is the end of the route.
- **Passenger's payloads** (`GET /change-proposals` and `GET /change-proposals/{proposal}`): the time
  is **her drop-off**. If her point can't be placed on the route, it falls back to the end of the
  route.
- The new time is the old one moved by the same amount as the leave time.
- On an applied proposal, `changes` is today's time and `previous` is the time before the change.
- `null` on a Going proposal, and on a Return proposal that doesn't move the leave time (a pickup-only
  change). The key is always there, so check for `null`, not for whether the key exists.

There is no `GET /driver/commutes/{commute}/change-proposals`. The driver's list is
`GET /driver/change-proposals` (with an optional `?commuteId=`).

## 2. `myPickup` on the passenger's proposal

This is on every passenger proposal (Going and Return), in `GET /change-proposals`,
`GET /change-proposals/{proposal}` and the answer to `POST /change-proposals/{proposal}/respond`:

```json
"myPickup": {
  "label": "Smart Village B6",
  "previousPickupLocal": "17:20:00",
  "newPickupLocal": "17:35:00"
}
```

- These are the same numbers the driver sees for her in `passengers[]`.
- `newPickupLocal` is `null` when the change doesn't move her pickup.
- Both times are `null` if her point can't be placed on the route.
- `myPickup` is `null` when the booking the proposal was asked about is no longer live (cancelled
  or finished).

## 3. Locked days

**On each `upcomingTrips[]` row of the driver's own `GET /commutes/{commute}`:**

```json
"timeLocked": false,
"timeLockedAt": "2026-11-25T05:00:00+00:00"
```

- `timeLocked`: this day is already inside the schedule lock.
- `timeLockedAt`: when the lock starts, in UTC.
- Passengers never get these two fields.

**The lock is 12 hours, not 16.** It is an admin setting, `commute.schedule_lock_hours`, with a
default of 12 (COM-16, D-0041). Don't hard-code the hours in the copy: build the time from
`timeLockedAt`, or leave the number out.

**🔴 A proposal for a locked day is not refused.** Every change to a published commute's time is a
proposal, and a proposal on a locked day is the case COM-17 is written for:
- Every confirmed passenger on that day must accept it.
- If nobody is booked, it applies at once.
- `409 COMMUTE_SCHEDULE_LOCKED` is only for pickup-point changes.

So the design's note "Tuesday's leave time is locked … Your change starts Thursday" does not match
the rules. A change can still reach Tuesday once its riders accept it. I've flagged this to the
owner as a product question.

Until the owner answers, use these fields as follows:
- `timeLocked` to warn "Tuesday is less than 12 hours away: your riders must accept the change".
- The proposal's own answer for the start date: `tripDate` on a one-day change, or `effectiveFrom` on
  an all-future change (you can send `effectiveFrom` when you create it). There is no draft
  proposal before you send one.

## 4. "Are you still driving?" on a Return

The trip session (`GET /trips/{trip}` and the answers to `start`, `status`, `complete` and `still-driving`) carries:

| Field | Meaning |
|---|---|
| `autoFinishCheckAt` | When to first show the dialog: the arrival below plus 20 minutes (`trip.auto_finish_grace_minutes`). Each "Still driving" tap sets it to 15 minutes from the tap (`trip.still_driving_extension_minutes`). |
| `expectedArrivalAt` | **New.** The arrival the check counts from (UTC). On a Going day it is the promised arrival. On a Return it is the estimated arrival as it stood when the run set off. A Return's estimate moves while riders join, but stops moving once the run starts. |

- Both fields are set when the run **departs**: the first move to `IN_PROGRESS`, after the first
  pickup. They are not set at start, because a run still collecting riders can't be late arriving.
  They are `null` before that.
- Both are `null` if the day has no arrival to count from. That run is never closed automatically.
- Show the dialog from `autoFinishCheckAt` in both directions, and stop computing it from the
  promise.
- For "{minutes} minutes past your expected arrival ({time})", count the minutes and show the time
  from `expectedArrivalAt`.
