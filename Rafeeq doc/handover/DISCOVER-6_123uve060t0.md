<!-- task: 123uve060t0 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-6 Overlap with her own booking

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

On every result **and every `otherDays[]` item** (per date), and on the detail's `upcomingTrips[]`:

```json
"overlapsBooking": { "bookingId": "01J…", "driverFirstName": "Nour", "departureLocal": "2026-11-03 07:00:00", "arrivalLocal": "2026-11-03 08:10:00" }
```

`null` when there is no overlap. It uses the same rule the server enforces (BOOK-17): her live booking on another commute whose departure → arrival window overlaps.

### Next: @ahmed Ibrahim (Flutter)
F3P-23: when set, the only action is **Request to Join Waiting List**, with "This overlaps your booking with Nour, 07:00 → 08:10".
