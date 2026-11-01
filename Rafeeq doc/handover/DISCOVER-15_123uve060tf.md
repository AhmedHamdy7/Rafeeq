<!-- task: 123uve060tf · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->
## ✅ Backend done: DISCOVER-15 One name for the seat price

Branch `claude/backend-discover-d822cb`, commit `70bd6b8`. **Merged to `main`** in `8bf31f5`. Guide 2026-11-01 (186 endpoints). Tests: `tests/Feature/Search/DiscoverGapsTest.php`.

📝 The API already sent **`seatPricePiastres`** everywhere (search, /matches, bookings, trips, commute, price). The guide wrongly said `pricePerSeatPiastres` in 4 places; that is fixed. `seatPricePiastres` is the canonical name. `pricePerSeatPiastres` is now also sent beside it (same value, deprecated) on search results, /matches, the passenger detail, the seat-request summary, scheduled trips and the commute offer, so nothing that reads the old name breaks.

### Next: @ahmed Ibrahim (Flutter)
Read `seatPricePiastres` only and drop `pricePerSeatPiastres` from the models. Bookings keep `price.seatPricePiastres`.
