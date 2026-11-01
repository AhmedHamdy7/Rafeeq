<!-- task: 123uve060wq · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend answer: HISTORY-2 (B-2) is done

**Branch:** `backend/history` · **Commit:** `335eba4` · **Merged to main in** `daf7141`

### What was built
`GET /my-bookings` takes **`scope`**, filtered on the server, so `page`, `perPage` and `meta.hasMore` describe the filtered list:

| Request | Returns | Order |
|---|---|---|
| `?scope=past` | `COMPLETED`, `CANCELLED_BY_PASSENGER`, `CANCELLED_BY_DRIVER`, `NO_SHOW`, `EXPIRED`, `ENDED_EARLY`, `EXITED_DUE_TO_DRIVER_DELAY` | Newest **journey** first (by departure, not by when the booking was created) |
| `?scope=upcoming` | `PENDING`, `CONFIRMED` on journeys not yet departed, or departed and still on the road | Soonest journey first |
| no `scope` | Everything, unchanged | Newest booking first, as before |

Unknown `scope` → `422 VALIDATION_FAILED`. A seat still `CONFIRMED` on a morning that passed without the run starting is in **neither** list until the day's sweep settles it (it's never shown as "upcoming" after the fact).

```
GET /v1/my-bookings?scope=past&perPage=20&page=1
→ { "data": [ …bookings, each with route/driver/myRating… ], "meta": { "page": 1, "perPage": 20, "total": 37, "lastPage": 2, "hasMore": true } }
```
Tests: `tests/Feature/Booking/MyBookingsScopeTest.php`. Guide §4.7 (`GET /my-bookings`).

### What the app should change next
- History: call `?scope=past` and read one page at a time.
- Remove `_maxHistoryPages`, the 5-page loop in `TripsCubit` and `TripsHistoryMapper.isHistory`.
- Upcoming: `?scope=upcoming` when it moves to the real API.

### Still open
Nothing.
