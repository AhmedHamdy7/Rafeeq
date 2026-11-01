<!-- task: 123uve060wk · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## Backend answer: HISTORY (B-1 … B-11)

**Branch:** `backend/history` (pushed) · **Commits:** `272ee11`, `335eba4`, `d8d6549` · **API guide:** 2026-11-01, 183 endpoints after merging main · **Merged to main in** `14ffe3f`

| # | Request | Answer |
|---|---|---|
| B-1 | Route, driver, pickup on every booking | ✅ done: `commuteId`, `originLabel`, `destinationLabel`, `driver` (first name only); pickup/drop-off are the existing `meetingPoint`/`dropoffPoint` (fuzzed unless confirmed) |
| B-2 | `scope=past/upcoming` | ✅ done: filtered and paged on the server, ordered by the journey |
| B-3 | No rating delete | 📝 by design: nothing built, no endpoint |
| B-4 | `myRating` | ✅ done: own rating or `null`; `editableUntil` fixed to match PATCH |
| B-5 | `"comment": null` clears | ✅ done: left-out fields now unchanged (fixed a bug that wiped comments) |
| B-6 | Stars-only cancelled feedback | 📝 confirmed: `201`; X-2 is live, a comment is `422 VALIDATION_FAILED` on `fields.comment` |
| B-7 | Only COMPLETED rateable | 📝 confirmed, except `ENDED_EARLY` is rateable today: **decision with Omar** on HISTORY-7 |
| B-8 | `GET /incidents?bookingId=` | ✅ done |
| B-9 | `safety_concern` + `false_arrival` | ✅ done: `safety_concern` ranked high; `false_arrival` live |
| B-10 | Appeal a resolved report | ✅ done: `POST /incidents/{incident}/appeal`, `APPEALED` status, `appeal{…}` on each report |
| B-11 | id/createdAt, order, late code | 📝 confirmed: yes / newest first / `409 REPORT_WINDOW_CLOSED`; `evidenceCount` list bug fixed |

**Guide sections updated:** §4.7 (booking shape, `/my-bookings`, `/bookings/{booking}`), §4.12 (categories, `GET /incidents` fields and filter, new appeal endpoint), §4.13 (rateable statuses, `editableUntil`, PATCH rules), §4.0 index, §9 change log.
