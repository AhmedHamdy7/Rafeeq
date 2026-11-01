<!-- task: 123uve060qt · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

```json
"topMatches": [ { "…": "…", "demandTitle": "New Cairo → Smart Village", "seatsAvailable": 2,
                  "driver": { "publicFirstName": "Omar", "trustLevel": 2, "completedTrips": 0 } } ],
"savedSearches": [ { "id": "01k9…", "title": "Rehab → Smart Village B12", "lastSearchedAt": "2026-10-13T17:20:00+00:00" } ]
```
- `demandTitle`: a demand has no name of its own, so it's built from its origin → destination labels; `null` if a label is missing → show "Matches your demand".
- `seatsAvailable`: on the commute's soonest open day; `null` if it has none.
- `driver.completedTrips`: `0` → "New driver". First name only, as agreed.
- `lastSearchedAt`: when she last ran that exact search (matched against her recent-search history); `null` if she hasn't run it since saving.
- These are on `/home` only; `GET /matches` is unchanged (the DISCOVER work owns that).


