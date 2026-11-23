# Own commute in Discover: `isOwn` on every row (2026-11-23)

Answer to `backend-own-commute-in-search-2026-11-23.md`. We picked the first option: one `isOwn` field.

## What changed

Every row of these two endpoints now has `isOwn`:

- `GET /v1/search/commutes`
- `GET /v1/search/commutes/browse`

| Field | Type | Meaning |
|---|---|---|
| `isOwn` | `bool` | `true` when the commute's driver is the signed-in user. Never `null`, always present. |

```json
{
  "commuteId": "01JD…",
  "isOwn": true,
  "driver": { "publicFirstName": "Amr", "rating": 4.8 },
  "vehicle": { "make": "Honda", "model": "Civic", "colour": { } }
}
```

All other fields are unchanged. The field is additive.

## Own commutes are in both lists

- `search/commutes` already returned the driver's own commutes. Nothing was dropped.
- `search/commutes/browse` used to hide them. It no longer does, so a driver can see how their commute looks to passengers. Look for `isOwn: true` there too.

## What the app does

- Show the "Your commute" banner on a card when `isOwn == true`.
- Disable or hide Request Seat on that card. `GET /commutes/{commute}/public` still answers `requestEligibility: {canRequest: false, reason: "own_commute"}`, so the detail screen needs no change.
- `myStatus` and `overlapsBooking` still describe the signed-in user as a passenger. On an own row they are `none` and `null`.

## Not done

`requestEligibility` per row (the other option) is not added. `women_only`, `already_requested` and `account_restricted` are still known only on the detail screen. Ask if you want it.

## Reaches staging

With the push to `main` of 2026-11-23.
