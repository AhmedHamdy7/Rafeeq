<!-- task: 123uve060rt · assign: Omar Aly (94558637) · status: backend answered - check -->
## Backend DISCOVER: summary

All 15 subtasks are done in one change, **merged to `main` in `257cd97`** (branch `claude/backend-discover-d822cb`, commit `70bd6b8`). Everything is additive. 2 new endpoints (guide now at 186): `GET /commutes/{commute}/public` and `DELETE /me/recent-searches/{search}`. Each subtask has its own handover to ahmed Ibrahim (Flutter).

| # | Item | |
|---|---|---|
| 1 | Passenger commute detail (trimmed route, suggested points, upcoming days) | ✅ |
| 2 | Promised arrival + drop-off point on results | ✅ |
| 3 | Weekdays + local time on other days | ✅ |
| 4 | `ratingCount` on person | ✅ |
| 5 | `vehicle.tier` / `hasAc` / `rules` on every result | 📝 already there; documented + tested |
| 6 | Overlap with her booking | ✅ |
| 7 | Her own status per day | ✅ |
| 8 | Recent searches: labels + delete one | ✅ |
| 9 | Demands: labels + preferences (alerts now honour them) | ✅ |
| 10 | Match count per demand | ✅ (next 7 days) |
| 11 | Full match-result shape on `/matches` | ✅ |
| 12 | Reverse lookup on `/places` | 📝 already took lat/lng; now nearest first + `distanceMetres` |
| 13 | Seat-request shape + commute summary | ✅ |
| 14 | Estimated pickup time (preview + booking) | ✅ |
| 15 | One name for the seat price | 📝 code already said `seatPricePiastres`; guide fixed, old name kept as an alias |

### Decisions for @Omar Aly
1. **"This week"** on the demand count means the next 7 days, not Sat → Fri. OK?
2. **Area names** on the trimmed route come from the catalogue place's district (or the nearest place within 3 km). Where the catalogue is thin, Start/End labels can be `null`.
3. Outside this group: recurring waiting-list applications (G23 / N3) and women-only visible to all (B-6) are still open; the 21:00 deadline (B-7) was removed by the follow-ups work.
