<!-- task: 123uve060qq · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

```json
"phase": "completed", "completedAt": "2026-10-18T05:07:00+00:00", "canRate": true
```
- `completedAt`: her check-out (drop-off), or the run's end if no check-out was recorded.
- `canRate`: `false` once she has rated or the rating window has closed (the same rules as `GET /ratings/pending`). Rate → `POST /bookings/{booking}/rating`.
- Once she rates, the card moves on (next journey, or `null`).


