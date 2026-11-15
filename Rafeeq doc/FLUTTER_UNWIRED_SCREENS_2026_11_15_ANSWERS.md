# Answers: what the last unwired screens need (2026-11-15)

Answers to `backend-unwired-screens-2026-11-15.md`. Everything below reaches the test server with the next deploy. Guide sections: 4.7 (`noShow`, the dispute), 4.12 (reports), 4.14 (notifications, push), 4.17 (testing calls).

| # | Answer |
|---|---|
| 1 | ✅ Built: `noShow` on her booking, with one difference: it appears as soon as the driver marks her, not only once the booking is `NO_SHOW` |
| 2 | ✅ `201` with the booking. Codes differ from your guesses; the real ones are below |
| 3 | ✅ Built: `no_show_dispute_decided` |
| 4 | ✅ Built: `POST /testing/bookings/{booking}/dispute/decide` |
| 5 | ✅ Built: `caseNumber`, `RP-` and six digits |
| 6 | ✅ Built: `POST /testing/incidents/{incident}/decide` and `POST /testing/incidents/{incident}/age` |
| 7 | ✅ Names already matched. Added `notificationId` |
| Q1 | Not now. Remove the question |
| Q2 | Yes, a suspended account can file a report |
| Q3 | Free text, up to 50 characters |

## 1 · `noShow` on her booking

On `GET /bookings/{booking}` and every row of `GET /my-bookings`, in the shape you proposed:

```json
"noShow": {
  "confirmedAt": "2026-11-15T05:12:00+00:00",
  "disputableUntil": "2026-11-16T05:12:00+00:00",
  "dispute": {
    "status": "OPEN",
    "reason": "I was at the gate from 7:00. The car never came to Gate 2.",
    "disputedAt": "2026-11-15T06:01:00+00:00",
    "resolution": null,
    "resolvedAt": null,
    "note": null
  }
}
```

What differs from the ask, and why:

- **It is set when the driver marks her, not when the booking turns `NO_SHOW`.** The booking stays `CONFIRMED` until the run ends, but Live trip already shows "Dispute no-show", and she can dispute from that moment. So read `noShow`, not `status`, to choose the screen. It is `null` when she wasn't marked a no-show.
- **Passenger only.** The driver's view of the same booking has no `noShow` key at all.
- `disputableUntil` is `null` once the window passed **or** she already disputed, so "show the button while it is set" is the whole rule.
- `refunded` also removes the no-show. The only difference from `overturned` is `paymentStatus: REFUNDED`.

What a decision moves:

| `resolution` | No-show on her ladder | Its fee | Reliability event | `paymentStatus` |
|---|---|---|---|---|
| `upheld` | stays | stays on her debt | stays | `NOT_DUE` |
| `overturned` | removed | dropped, or credited back if already charged (PAY-10) | reversed | `NOT_DUE` |
| `refunded` | removed | dropped, or credited back if already charged (PAY-10) | reversed | `REFUNDED` |

The no-show fee is never on this booking's `feeLinePiastres`; it rides on her **next** ride's fee line (PAY-16). So when a fee is removed, that next booking's `feeLinePiastres` drops. The booking `status` and the attendance `status` don't change; the decision is in `noShow.dispute`.

Staff decide these disputes on the dashboard's Payments page ("No-show disputes"). Before today there was no way to decide one at all.

## 2 · `POST /bookings/{booking}/dispute`

**Answer:** `201` with the booking as `GET /bookings/{booking}` returns it, `noShow.dispute` filled. ⚠️ It used to answer `200` with the attendance row; if the app reads `disputedAt` from the answer, switch to `noShow.dispute.disputedAt`.

| Case | Code |
|---|---|
| Nobody recorded her yet (`PENDING`), or the booking was never on a run | `409 ATTENDANCE_NOT_DISPUTABLE`, `error.fields.currentStatus`: `PENDING` or `NONE` |
| More than 24 hours since `confirmedAt` | **`422`** `ATTENDANCE_DISPUTE_WINDOW_CLOSED`, `error.fields.closedAt` |
| She already disputed it | `409 ATTENDANCE_ALREADY_DISPUTED` |
| `reason` under 10 or over 255 | `422 VALIDATION_FAILED`, `error.fields.reason` |
| Not her booking | `404` |

The window-closed code stays `422`, as it has always been. The first and third had the same code before (`ATTENDANCE_NOT_CONFIRMABLE`); each has its own now.

A dispute on a ride she **did** take ("I wasn't in that car") is still accepted, but `noShow` stays `null` for it and there is no screen or decision for it yet. Only no-show disputes can be decided today.

## 3 · `no_show_dispute_decided`

- **To:** the passenger. Inbox row and push, category `trip` (her Trip switch).
- **`data`:** `bookingId`, `tripId`, `resolution` (`upheld` · `overturned` · `refunded`).
- **Tap:** Past trip for `bookingId`.
- Text: "Your no-show dispute was decided" / "We reviewed your dispute about :date. Open the trip to read the decision." (Arabic too).

## 4 · `POST /testing/bookings/{booking}/dispute/decide`

As you asked: the passenger who disputed, body `{ "resolution": "upheld" | "overturned" | "refunded", "note"?: "…" }` (`note` up to 255). It runs the same action the dashboard runs, so the fee, the reliability event, `paymentStatus` and the notification all move. Answers the booking. `409 ATTENDANCE_DISPUTE_NOT_OPEN` when there is no open no-show dispute; `404` if it isn't hers; `422` for an unknown resolution.

For a no-show **with a fee** (so you can see it drop), first call `POST /testing/attendance` `{kind: "no_show", count: 2}`: the next no-show is then on the fee rung.

## 5 · `caseNumber`

On every report: the `POST /incidents` answer, `GET /incidents` and `GET /incidents/{incident}`. `RP-` and six random digits, e.g. `RP-308712`, the same shape as a suspension's `RF-482913`. Reports filed before today got one too.

## 6 · Deciding a report

`POST /testing/incidents/{incident}/decide`, the person who filed it, body `{ "status": "UNDER_REVIEW" | "RESOLVED" | "CLOSED", "resolution"?: "…" }`:

- `UNDER_REVIEW` takes it, from `OPEN` or `APPEALED`.
- `RESOLVED` / `CLOSED` set `resolution` (default `Decided by the test run.`, up to 255) and `resolvedAt`, open the 48-hour appeal (`appeal.appealableUntil`) and send `report_resolved` / `report_closed` with `incidentId`.
- After an appeal it works again, so both rounds can be played. The second appeal still needs a new file first (`appeal.needsNewEvidence: true`).
- `409 INCIDENT_CLOSED` on a decided report that wasn't appealed; `404` for somebody else's.
- Answers the report as `GET /incidents/{incident}`.

`POST /testing/incidents/{incident}/age` `{ "hours": 1–200 }` moves `resolvedAt` back. 49 hours closes the window (`appeal.appealableUntil: null`).

## 7 · Push payload

The push `data` and the inbox row's `data` are written from one array for every type, so the names and values already matched. On top of them every push carries `type`, `category` and now **`notificationId`**, the inbox row's `id`, for marking it read. All values are strings.

```json
{ "type": "no_show_dispute_decided", "category": "trip", "notificationId": "01J…", "bookingId": "01J…", "tripId": "01J…", "resolution": "overturned" }
```

`notificationId` is missing in one case: her in-app setting for that category is off, so no inbox row exists. Fall back to routing on `type` and `data`.

## Questions

**Q1. "What outcome would help?"** Remove it for the beta. The business spec has no field or rule for it; staff decide the outcome from what happened (SAFE-10), and the coupon or refund follows from that decision (PAY-10, PAY-34). If the business team adds the question to SAFE-08, an optional `desiredOutcome` is a small change. ⚠️ On voice notes: SAFE-09 says the backend must accept audio files, but audio is still refused today while Q-007 (recording consent) is open. Removing the Voice button for the beta matches what the server accepts now.

**Q2. Suspended accounts.** Yes. `POST /incidents`, `GET /incidents`, evidence and the report appeal don't need an active account, so a suspended account can use them. Use `POST /account/suspension/appeal` to appeal the suspension itself, and `POST /incidents` for a safety problem. So "Contact safety" can go to either, depending on what she wants.

**Q3. `relationship`.** Free text, optional, up to 50 characters. The server has no fixed list, so the Mother / Brother / Friend chips can fill the field and "Other" can let her type.
