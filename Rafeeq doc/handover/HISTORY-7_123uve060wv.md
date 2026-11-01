<!-- task: 123uve060wv · assign: Omar Aly (94558637) · status: backend answered - check -->
## 📝 Backend answer: HISTORY-7 (B-7) is confirmed, with one difference that needs a decision

**Branch:** `backend/history` · **Commit:** `335eba4` (tests pin today's behaviour) · **Merged to main in** `14ffe3f`

What the server accepts today (`tests/Feature/Rating/HistoryRatingRulesTest.php`):

| Status | Rateable? |
|---|---|
| `COMPLETED` | ✅ both sides |
| `CANCELLED_BY_DRIVER` | ✅ passenger only, stars-only feedback |
| **`ENDED_EARLY`** | ⚠️ **✅ today, as a `completed_ride`** |
| `NO_SHOW`, `EXPIRED`, `CANCELLED_BY_PASSENGER`, `EXITED_DUE_TO_DRIVER_DELAY`, `PENDING`, `CONFIRMED` | ❌ `422 TRIP_NOT_RATEABLE` (`error.fields.bookingStatus` names the status) |
| Driver on a seat he cancelled | ❌ `422 TRIP_NOT_RATEABLE` |

**The difference:** `ENDED_EARLY` (she got out before her stop) is rateable on purpose. She was in the car for part of the journey, and a safety exit is exactly the ride somebody needs to be able to describe. The 2026-11-01 request says COMPLETED only.

**@Omar, please decide:**
- **(a) Keep `ENDED_EARLY` rateable** (backend recommendation). The app adds Rate on `ENDED_EARLY` bookings; nothing changes on the server.
- **(b) Close it.** One-line backend change, and `ENDED_EARLY` becomes `422 TRIP_NOT_RATEABLE`. She can still report the trip (`POST /incidents`) either way.

Everything else matches the app. Once decided → ahmed Ibrahim for the app side.
