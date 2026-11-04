# Driver Home: answers to backend gaps (round 2)

Branch `fix/driver-home-gaps-2`. Real responses are in `FLUTTER_DRIVER_HOME_RESPONSES.md`, captured on a local server.

| # | Item | Answer | Status |
|---|---|---|---|
| 1 | Demo accounts on the live server | Not run yet. Access follows from the backend team | ⏳ Waiting on backend |
| 2 | `promisedArrivalLocal` is `null` | Seed data, fixed. `null` is also real behaviour | ✅ Done |
| 3 | Suspended driver's car items | New status `suspended` | ✅ Done |
| 4 | Licence item after a rejection | Now `in_progress` | ✅ Done |
| 5 | `user.suspension` | On `GET /auth/me`. Captured | ✅ Done |
| 6 | Passenger gender | Won't be added. Keep using the name | ✅ Final |
| 7 | `canStart` at T-2 h 10 | Disable the button until starting is allowed. Wording not decided | 🟡 Rule done, wording waiting on Product |
| 8 | Suspended driver with paused commutes | Two different messages. New `pausedCommutes` and `pausedCommuteReasons`. Wording not decided | 🟡 Field done, wording waiting on Product |
| 9 | Stats and notices with no `nextRun` | Yes, they can have content. Show them | ✅ Done |

## 1. Demo accounts on the live server

The accounts don't exist on the live server yet. The `States and phone numbers` table in `FLUTTER_DRIVER_HOME_RESPONSES.md` says which account shows which state. The sign-in code will come from the backend team with the confirmation that the accounts are ready.

## 2. `promisedArrivalLocal` is `null`

The seeder only set the promise on today's run, so the next commute in `F3D-06` had none. It now has one on every day.

`null` is still possible for real: a day saved with a fixed departure and no promised arrival has no promise. Keep dropping the line. The `NO-PROMISE` account shows this case.

## 3. Suspended driver's car items

`vehicle_registration` and `vehicle_details` now read `suspended` with `rejectionReason: null`. The driver has nothing to replace, so don't offer an upload. The reason is `verification.suspensionReason`.

This is a new value for `items[].status`, on the car items only. `actionItem` stays `null`, and `completed` stays `3` of `5`.

## 4. Licence item after a rejection

The licence now reads `in_progress` whenever no reviewer has the application: after a rejected car, a rejected application, or a withdrawal. The car items already behaved this way. It goes back to `under_review` when the driver submits again.

A licence renewal on an approved driver still reads `under_review`, because a reviewer does have it.

## 5. `user.suspension`

- **Endpoint:** `GET /auth/me`, on `data.user`. It isn't on `/driver/home`. See the full response under `Whole account suspended`.
- **Check:** `user.accountStatus` is `SUSPENDED`.
- **They can differ, because they are two different suspensions:**

| | `verification.suspensionReason` | `user.suspension` |
|---|---|---|
| Who it stops | The driver role only. Passenger mode still works | The whole account |
| `verification.overall` | `suspended` | Can still be `verified` |
| Text | Written by the reviewer | `user.suspensionReason` is a fixed category |
| Account to test | `SUSPENDED` | `ACCOUNT-SUSPENDED` |

So don't use `verification` to detect a whole-account suspension. An account suspended this way gets `overall: verified` and `suspensionReason: null` on `/driver/home`.

## 6. Passenger gender

It won't be added. Business rule SAFE-18: other users never see a passenger's gender. Keep the name in English, and use wording in Arabic that doesn't depend on gender.

The moved-passenger card is `F3D-08` (`passenger_switched`). `F3D-07` is `seat_opened`.

## 7. `canStart` at T-2 h 10 (DH-10)

Product decided: the button is disabled until starting is allowed, and says why (TRIP-02, D-0033). The wording for the disabled state is still to come.

Disable it while the current time is before `startOpensAt`. Don't use `canStart` alone: it is also `false` for an expired licence or a suspension, and an unverified driver who taps Start must still see the verification gate (PROF-19).

## 8. Suspended driver with paused commutes

`GET /driver/home` now returns `pausedCommutes`, a count of this driver's paused commutes for any reason. It is `0` when there are none, and it covers a non-suspended driver who paused everything too.

Product decided: a driver with paused commutes never sees "No commutes yet", and a suspended driver and a driver who paused see different messages (TRIP-02, D-0033). The wording is still to come.

`pausedCommuteReasons` lists why, each reason once. Choose the message from it, because `verification.overall` stays `verified` for a debt suspension and a whole-account suspension:

| Reason | Message |
|---|---|
| `by_driver` | Driver paused |
| `commission_unpaid`, `driver_suspended`, `account_suspended` | Suspended |
| `licence_expired`, `vehicle_suspended` | Keep the verification card you already show |

If the list has both, the suspended message wins.

## 9. Stats and notices with no `nextRun`

Yes. `pendingRequests`, `waitlist` and `notices[]` can all have content when `nextRun` is `null`, for example when every commute is paused. `stats` is always present. Show them whenever they have content.

The capture is under `No nextRun, with notices, requests and a waiting list`, account `NO-RUN-ACTIVITY` (`+201055557028`).
