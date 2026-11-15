# Answers: four leftovers around Safety and suspension (2026-11-15)

Answers to `backend-safety-leftovers-2026-11-15.md`. Everything below reaches the test server with the next deploy. Guide sections: 4.2 (suspension appeal, what stays open for a ban), 4.11 (home), 4.12 (trusted contacts), 4.17 (testing calls), 4.18 (content).

| # | Answer |
|---|---|
| 1 | ✅ Built: `noShow` on the journey in `GET /home` |
| 2 | ✅ Built: `meta: { limit, canAdd }` on `GET /safety/emergency-contacts` |
| 3 | ✅ Built: three testing calls. `driver/suspend` documented as working for any account |
| B1 | ✅ Fixed: `422 VALIDATION_FAILED` with `error.fields.phone` |
| B2 | ✅ Fixed: her own number is refused with `422 EMERGENCY_CONTACT_SELF` |
| Q1 | Reports work for a ban. "Contact safety" can open the report form or show `GET /content/support_contact` |
| Q2 | Show it in full |
| Q3 | Hiding the switch is fine. It is in the spec but not built yet |

## 1 · `noShow` on the home journey

`GET /home` → `data.nextJourney.noShow`, the same object and rules as on `GET /bookings/{booking}`:

```json
"noShow": {
  "confirmedAt": "2026-11-15T05:12:00+00:00",
  "disputableUntil": "2026-11-16T05:12:00+00:00",
  "dispute": null
}
```

- `null` when the driver didn't mark her a no-show.
- Set from the moment the driver marks her, while the booking is still `CONFIRMED`.
- `disputableUntil` is `null` once the 24-hour window passed **or** she already disputed. So "show Dispute no-show while `disputableUntil` is set" is the whole rule.
- After she disputes, `dispute` is filled: `{ status: "OPEN", reason, disputedAt, resolution: null, resolvedAt: null, note: null }`. Show "You disputed this no-show. We're reviewing it."

No extra call needed on Live trip.

## 2 · The trusted-contact limit

```json
{ "success": true, "data": [ … ], "meta": { "limit": 3, "canAdd": true } }
```

- `limit`: the cap (3 today, a setting staff can change).
- `canAdd`: whether another contact fits right now. Hide "Add trusted contact" when it is `false`.

On "5 of 3": somebody who kept 4 or 5 from before has `canAdd: false`. Show `{count} of {limit}` as you planned. If "5 of 3" reads badly, show just the count when `count > limit`. We don't send a second number.

## 3 · Testing calls for a suspension

All three are staging-only, act on the caller, and answer the same shape:

```json
{
  "success": true,
  "data": {
    "accountStatus": "SUSPENDED",
    "nextStep": "HOME",
    "suspension": { "caseNumber": "RF-025266", "permanent": false, "appeal": { "canAppeal": true, "appealBy": "…", "needsNewEvidence": true, "appeals": [ … ] }, … }
  }
}
```

`suspension` is `user.suspension` exactly as `GET /auth/me` returns it, or `null` once the hold is lifted. `nextStep` is what `GET /auth/me` would route to (`RESTRICTED_ACCOUNT` for a ban).

### `POST /testing/account/suspension/appeal/decide`

- **Caller:** the suspended account with a pending appeal.
- **Body:** `{ "decision": "upheld" | "overturned", "note"?: "…" }` (`note` up to 255).
- Runs the same action as the dashboard: `overturned` lifts the hold and sends `account_reinstated`. `upheld` keeps it and opens the second 48-hour window (`canAppeal: true`, `needsNewEvidence: true`).
- `409 SUSPENSION_APPEAL_NOT_OPEN` when no appeal is pending. `409 MEMBER_NOT_SUSPENDED` when she isn't suspended. `422` for another `decision`.

### `POST /testing/account/suspension/age`

- **Caller:** the suspended account.
- **Body:** `{ "hours": 1–200 }`.
- Moves `suspendedAt`, `reviewDueAt`, and each appeal's `createdAt` and `resolvedAt` back by that many hours. 49 hours closes whichever window is open: `canAppeal: false`, `appealBy: null`, and the next appeal answers `APPEAL_WINDOW_CLOSED`.
- `409 MEMBER_NOT_SUSPENDED` when she isn't suspended.

### `POST /testing/account/ban`

- **Caller:** any test account that is active.
- **Body:** `{ "reason"?: "…" }` (up to 255).
- A permanent ban, as staff make one on the Members page: `nextStep: RESTRICTED_ACCOUNT`, `suspension.permanent: true`, `canAppeal: false`, and `POST /account/suspension/appeal` answers `PERMANENT_BAN`. ⚠️ Like a real ban, it cancels every upcoming seat and pauses her commutes.
- `409 MEMBER_NOT_ACTIVE` when the account is already on hold.
- **Undo:** `POST /testing/driver/reinstate` with `{ "scope": "account" }` lifts it, or `DELETE /testing/account` deletes the account so the number can be reused.

### Every state from your table

| State | How |
|---|---|
| Appeal turned down, second window open | `driver/suspend` (account) → `POST /account/suspension/appeal` → `appeal/decide` `upheld` |
| Appeal accepted, `account_reinstated` | … → `appeal/decide` `overturned` |
| Both appeals used (`ALREADY_APPEALED`) | `upheld` → second appeal with `evidence` → `upheld` again → appeal a third time |
| Window closed (`APPEAL_WINDOW_CLOSED`) | `driver/suspend` (account) → `suspension/age` `{hours: 49}`. After an `upheld` decision, the same call closes the second window |
| Permanent ban | `POST /testing/account/ban` |

### `POST /testing/driver/suspend` for passengers

Kept the path, documented in guide 4.17: `scope: "account"` works for **any** account, passenger-only included, despite the `driver/` in the path. `driver/reinstate` with `scope: "account"` also lifts a permanent ban.

## Bugs

**B1. Bad phone number.** Fixed. `POST` and `PATCH /safety/emergency-contacts` with a number that isn't an Egyptian mobile (`12345`) now answer:

```json
{ "success": false, "error": { "code": "VALIDATION_FAILED", "fields": { "phone": ["Please enter a valid Egyptian mobile number."] } } }
```

**B2. Her own number.** Not intended, now refused on add and on edit, in any format:

```json
{ "success": false, "error": { "code": "EMERGENCY_CONTACT_SELF", "message": "That is your own number. Add somebody else you trust.", "fields": { "phone": ["That is your own number. Add somebody else you trust."] } } }
```

Status `422`. Arabic text too. The check runs before the limit and duplicate checks.

## Questions

**Q1. "Contact safety" for a permanent ban.**

1. **Yes, `POST /incidents` works for a ban.** The guide's sentence was too broad: report routes never sat behind `account.active`, so a banned account can still file, read and appeal reports and add evidence. Guide 4.2 now lists what stays open for a ban: `GET /auth/me`, sign-out, devices, notifications, reports, trusted contacts and content.
2. **Where to send her:** the business spec (AUTH-19) says the Restricted screen shows **a reference number and the support contact**. The support contact already exists: `GET /content/support_contact`, which staff write on the dashboard's Content page (both languages, readable while banned). So the screen should show `user.suspension.caseNumber` and the text from that page. "Contact safety" can still open the report form for a safety problem. It answers `404` until staff have written the page, so hide that block on `404`.

**Q2. Full phone number.** Show it in full, as the guide says. SAFE-18 hides phone numbers from **other users**. This is her own list, and she needs to check it for a typo. No change on our side.

**Q3. Auto-share.** Hiding the switch for the beta and sending `autoShareTrips: false` is fine. Note: SAFE-14 in the business spec does include the auto-share toggle, so this is a "not built yet", not a "dropped". It needs an SMS/WhatsApp provider to deliver the link to a contact who has no app. We'll tell you when it works.

## What changed for the app

- `GET /home` → `nextJourney.noShow` (new key).
- `GET /safety/emergency-contacts` → `meta.limit`, `meta.canAdd` (new keys).
- `POST` / `PATCH /safety/emergency-contacts` → new refusals `422 VALIDATION_FAILED` (`fields.phone`) and `422 EMERGENCY_CONTACT_SELF` (`fields.phone`).
- New staging-only calls: `POST /testing/account/suspension/appeal/decide`, `POST /testing/account/suspension/age`, `POST /testing/account/ban`.

Nothing existing changed shape.
