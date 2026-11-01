<!-- task: 123uve060t1 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-7 Her own status on the commute

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `257cd97`. Guide 2026-11-01 (184 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

On every result, every `otherDays[]` item, `/matches` rows and the detail's `upcomingTrips[]`:

```json
"myStatus": { "kind": "pending_request", "seatRequestId": "01J…", "bookingId": null }
```

`kind`: `none` · `pending_request` · `counteroffered` · `waitlisted` · `booked`. A recurring request covers the weekdays she asked for; a one-day request only its own day.

### Next: @ahmed Ibrahim (Flutter)
Action button: `none` → Request Seat (or Join Waiting List when the day is full or `overlapsBooking` is set); `pending_request` → "Requested · Withdraw" (`DELETE /seat-requests/{seatRequestId}`); `counteroffered` → open B09; `waitlisted` → "On the waiting list"; `booked` → open the booking.

Open: recurring waiting-list applications (G23 / N3) are still a later task.
