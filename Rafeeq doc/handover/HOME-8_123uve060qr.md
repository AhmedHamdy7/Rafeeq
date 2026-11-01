<!-- task: 123uve060qr · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

```json
"demandAlert": { "demandId": "01k9…", "newMatchesCount": 3, "originLabel": "New Cairo",
  "destinationLabel": "Smart Village", "daysMask": 62, "arrivalTimeLocal": "08:00", "arrivalWindowEndLocal": "08:30" }
```
- One alert: the active demand with the most **unopened** matches. `null` → no card. The server sends it in every state; hide it on F3P-01 in the app if the design says so.
- ⚠️ **New endpoint you must call:** `POST /v1/matches/{match}/opened` when she taps a match (a `topMatches[]` row or a `GET /matches` row). No body; returns the match with `clickedAt`; repeat calls are harmless; `404` if it isn't hers. **Without this call the count never goes down**, because nothing recorded "opened" before.


