# Answers — My vehicle and Driver reliability findings (2026-11-14)

Answers to `backend-findings-vehicle-reliability-2026-11-14.md`. Everything is in the guide too (4.2, 4.4, 4.17 and
the ratings section).

| # | Answer |
|---|---|
| 1 | No new field. `suspensionReason` is a code, and the staff's text stays internal. Show a fixed sentence per code |
| 2 | ✅ Archived commutes no longer block `DELETE /testing/driver/vehicles/{vehicle}` |
| 3 | ✅ Review rows have `report: { id, status } \| null` |
| 4 | ✅ Decided: a grouped act is appealed once. Two new calls, and the single calls follow the group too |

## 1 · `suspensionReason` on a vehicle

**The values.** There are two, and no others:

| Code | When | A sentence the app could show |
|---|---|---|
| `registration_expired` | The registration date passed. Automatic | "Your car registration has expired. Upload the renewed one to drive again." |
| `report` | Staff suspended the car after reviewing a report (wrong car, bad condition, PROF-35). A report on its own never suspends | "Your car was suspended after a review. Contact support to find out more." |

The wording is yours. COM-46 gives the buttons: **Upload registration** for `registration_expired`, **Support**
for `report`.

**The staff's text is not sent, and won't be.** On the dashboard that box is labelled "Reason (kept in the audit
log)", and on the cars review "What the review found, and the report it came from". Staff write it for each other,
and it can point at the report, so it could identify the passenger who reported the car. The account suspension
works the same way: the driver gets a sentence per category, never the note.

**`/driver/verification` for a vehicle suspension:** its `suspensionReason` is about the account (the driver is
suspended). A suspended car alone leaves it `null`. The car items read `suspended` (PROF-36), and the reason is on
the car in `GET /driver/vehicles`.

## 2 · Deleting a test car that archived commutes name

`DELETE /testing/driver/vehicles/{vehicle}` now ignores archived commutes. They lose the car (it becomes `null` on
them) and the car is deleted. Drafts, published and paused commutes still refuse with `VEHICLE_IN_USE`, and
`fields.commuteIds` lists only those.

So the Corolla and the Cerato on `+201020000008` can be deleted now, which frees the third slot.

An archived commute can never be published again, so the missing car on it changes nothing in the app.

Still refused, a paused commute on that car, `409`:

```json
{
  "success": false,
  "error": {
    "code": "VEHICLE_IN_USE",
    "message": "A commute still uses this car, so it cannot be removed.",
    "fields": { "commuteIds": ["01m7den37yase1x52aaqspna7e"] }
  }
}
```

## 3 · The report on a review row

Every row of `GET /ratings/about-me` now has `report`, the caller's own report on that review, or `null`:

```json
{
  "id": "01m7cd4w9enxdvb6p0n32dgdfp",
  "direction": "passenger_to_driver",
  "kind": "completed_ride",
  "stars": 5,
  "comment": "E13 on time and friendly",
  "tags": [],
  "month": "2026-11",
  "wasEdited": false,
  "reportedByMe": true,
  "report": { "id": "01m7cd5k2q8w0f3n6x1b9r4t7y", "status": "PENDING" }
}
```

- `status`: `PENDING` when filed, `DISMISSED` after "kept". After "removed" (`UPHELD`) the review is hidden, so the
  row is gone and you won't read `UPHELD` here. `UNDER_REVIEW` is in the server's list but nothing sets it today.
  Treat it like `PENDING`.
- `reportedByMe` stays. It is always `report != null`.
- A review nobody reported: `"reportedByMe": false, "report": null`.

So the test can now read `report.id` after the app files the report and decide it with
`POST /testing/ratings/reports/{report}/decide`. The second report through the API isn't needed any more.

## 4 · Appealing a grouped act

**Decided: once for the whole group.** The 11 `DRIVER_DISPLACED_PASSENGER` events of one pause are one appeal and one
decision.

**New calls** (guide 4.2):

```
POST /account/reliability/groups/{groupId}/appeal      { "reason": "..." }
POST /account/reliability/groups/{groupId}/evidence    multipart: kind, file
```

- The appeal is filed on every event in the group that can still be appealed. The file is added to every event that
  still takes evidence.
- One staff decision applies to all of them, on the dashboard and through
  `POST /testing/reliability/appeals/{appeal}/decide` with the `appeal.id` of any event in the group:
  - approved: every event `counts: false`, every fee credited back, the score recomputed.
  - declined: every event declined, `appealsLeft: 1` and `NEW_EVIDENCE_REQUIRED` on all of them. One group upload
    clears that on all, and a second group appeal opens round 2 on all.
- One `appeal_approved` or `appeal_declined` push, not eleven. Its `data.reliabilityEventId` is one event of the
  group: find the group by that event's `groupId`.
- `groupId` in either case. Another driver's group answers `404`.
- The single calls follow the group too: `events/{event}/appeal` or `events/{event}/evidence` on an event that has a
  `groupId` applies to the whole group. Use the group calls for the grouped row anyway, because their answer
  returns the whole group.

**For the row:** `canAppeal`, `appealsLeft`, `appealBy`, `appealRefusal` and `appeal.status` are the same on every
event of a group, so read them off the first one.

The group appeal answers `200` with the group's events, each as a row of `events[]`. Two of the eleven shown:

```json
{
  "success": true,
  "data": {
    "groupId": "01M7DENT5C5AKNS2J6H9BA26VK",
    "events": [
      {
        "id": "01M7DENT5D0Q1V6K8B2X4M7N9P",
        "kind": "DRIVER_DISPLACED_PASSENGER",
        "groupId": "01M7DENT5C5AKNS2J6H9BA26VK",
        "counts": true,
        "canAppeal": false,
        "appealsLeft": 1,
        "appealRefusal": "ALREADY_APPEALED",
        "evidence": [{ "id": "01m7dfa0c8x2w4e6r8t0y2u4i6", "kind": "photo", "purgeAfter": "2027-02-12", "createdAt": "2026-11-14T08:10:00+00:00" }],
        "appeal": {
          "id": "01m7dfa1h3k5m7p9r1t3v5x7z9",
          "round": 1,
          "status": "pending",
          "appealedAt": "2026-11-14T08:11:00+00:00",
          "decidedAt": null,
          "declineReasonCode": null
        }
      },
      {
        "id": "01M7DENT5E2S3W8M0D4Z6P9R1T",
        "kind": "DRIVER_DISPLACED_PASSENGER",
        "groupId": "01M7DENT5C5AKNS2J6H9BA26VK",
        "counts": true,
        "canAppeal": false,
        "appealsLeft": 1,
        "appealRefusal": "ALREADY_APPEALED",
        "evidence": [{ "id": "01m7dfa0c9a3b5c7d9f1g3h5j7", "kind": "photo", "purgeAfter": "2027-02-12", "createdAt": "2026-11-14T08:10:00+00:00" }],
        "appeal": {
          "id": "01m7dfa1h4n6q8s0u2w4y6a8c0",
          "round": 1,
          "status": "pending",
          "appealedAt": "2026-11-14T08:11:00+00:00",
          "decidedAt": null,
          "declineReasonCode": null
        }
      }
    ]
  }
}
```

Each row also has every other field of `events[]` (`weight`, `occurredAt`, `person`, `fee`…), left out above.
Each event keeps its own `appeal.id`; any of them decides the group.

Appealing the group again while it waits, `422`:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "fields": { "appeal": ["ALREADY_APPEALED"] }
  }
}
```

## Notes

- **The timeout at 06:46 UTC:** noted. If it happens again, send the call and the time.
- **Resets:** noted. We'll tell you before the cast accounts are reset.
- **`+201088888888`:** remove it with `DELETE /testing/account` while signed in as it.
