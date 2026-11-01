<!-- task: 123uve060nd · assign: unchanged (ahmed hamdy) · status: unchanged (current) · comment only -->
## Backend check-in, 2026-11-01

**Done and merged into `main`, with tests passing (2,404 tests on the merged tree):**
- **Beta blockers:** F1-1 (store-review login), F1-4 (`DELETE /account` + `/delete-account` web page), F1-6 (SMS transport ready), F2-1 (driver items in any order), F2-2 (selfie required for approval), F2-4 (registration front/back), plus F2-14 (driver notifications) and F2-15 (registration only).
- **F1-2, F1-3, F1-5, F2-5, F2-8:** gender woman/man, conduct suspension + appeal, OTP lock, transmission enum, verification hidden from passengers.
- **Backend 1.1–1.5, 2, 3, 6:** verified or finished, and the guide brought up to date.
- **HOME-1…10:** `GET /home` covers every passenger Home state.
- **DISCOVER-1…15:** search, commute detail, demands, seat request.
- **HISTORY-1…11:** route/driver/pickup on bookings, `scope`, `myRating`, `safety_concern`, report appeal, and more.
- **API guide:** 186 endpoints live, with a change-log row per group.

**Handed over:** each task has a comment, the status `backend answered - check`, and is assigned to ahmed Ibrahim (app wiring) or Omar Aly (decisions).

**Blocked on others:** the SMS provider choice, the review-account env vars on production, the FCM service account + APNs key, and the Q-055 retention decision (Omar).

**Next:** answer questions on the handovers; whatever the partners decide on the open items.
