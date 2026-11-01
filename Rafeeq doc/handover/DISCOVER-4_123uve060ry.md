<!-- task: 123uve060ry · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-4 Rating count on person

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (186 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

`person.ratingCount` everywhere `person` appears (search, /matches, the detail, request reviews, member lists):

```json
"driver": { "publicFirstName": "Nour", "rating": 4.9, "ratingCount": 210, "completedTrips": 120 }
```

Counted by the same rules as `rating` (revealed, completed rides, not taken down) and recomputed on reveal. It is `0` exactly when `rating` is `null`. Existing ratings were backfilled by the migration.

### Next: @ahmed Ibrahim (Flutter)
"4.9 ★ (210)". Hide the count when `rating` is null.
