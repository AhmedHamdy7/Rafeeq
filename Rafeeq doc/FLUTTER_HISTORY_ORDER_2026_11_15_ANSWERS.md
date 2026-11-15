# Answers: order of the passenger's History (2026-11-15)

Answers to `backend-history-order-2026-11-15.md`. The change reaches the test server with the next deploy.

**We chose the sort.** A cancelled seat stays in History and is sorted by when it was cancelled.

The list is `GET /my-bookings?scope=past` (your note says `/bookings?view=history`; it is the same list).

| Seat | Sorted by |
|---|---|
| `CANCELLED_BY_PASSENGER` · `CANCELLED_BY_DRIVER` | `cancelledAt` |
| `COMPLETED` · `NO_SHOW` · `ENDED_EARLY` · `EXPIRED` · `EXITED_DUE_TO_DRIVER_DELAY` | the trip's departure |

Newest first, then by booking id when two are equal, so paging stays stable.

- Pausing or deleting a commute, replacing the car, a driver removing her, and her own cancel all set
  `cancelledAt`, so Mariam's seats from Omar's commute now sit by the day they were cancelled, below the
  trial she finished today.
- `meta` paging is unchanged: same `page`, `perPage`, `total`, `lastPage`, `hasMore`.
- Nothing new in the response.
- `?scope=upcoming` and calls with no `scope` are unchanged.

⏳ The business spec doesn't set an order for History yet, so this rule is the backend's choice until it does.
