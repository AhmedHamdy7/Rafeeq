<!-- task: 123uve060h3 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: the block fee is real, and guide §4.12 is fixed (X-4, D5)**

**What exists**
- Fees built in W3 (PR #23, `9c12317`). The guide and API description are fixed in `da9654d` (branch `claude/backend-followups-db6d1f`).
- `GET /safety/blocked-users/preview?userId=` returns `{bookingsToCancel, feePiastres, reason}`, using the **same arithmetic** the block then charges:
  - **Blocker is the driver:** per confirmed seat by notice. 16h+: 0 · 10–16h: 10% · under 10h: 25%. `reason: driver_cancel`.
  - **Blocker is a passenger:** the late-cancel fee on her own counter for each seat inside 12h of departure. This is the most it can cost: it is dropped if the seat is refilled. `reason: late_cancel`.
  - `reason` is `null` when `feePiastres` is 0.
- Both §4.12 texts that said "always 0" are corrected (the block paragraph and the preview table). The Scramble/OpenAPI description is fixed too. The booking shape's `cancellationFeePiastres` stays 0: real fees go to the debt ledger and `feeLinePiastres`.
- Test: `DriverFeesTest` ("previews and charges a driver blocking his passenger by notice"). The preview equals the fee charged.

**What the app must do**
- Show `feePiastres` on the block confirm sheet whenever it is above 0, worded by `reason`.

**Open:** the amounts are the partners' (PAY-21, PAY-23), all admin settings in the "Fees" group. No sign-off needed from the backend side.
