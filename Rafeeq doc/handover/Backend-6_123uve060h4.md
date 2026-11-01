<!-- task: 123uve060h4 · assign: ahmed Ibrahim (66109718) · status: backend answered - check -->

**Backend answered: missing notifications (commission, coupon, waitlist, late) (A6)**

All of these now exist and are listed in **guide §4.14** ("Every `type`, by who receives it", a new table of every type with recipient, screen and `data`). Commit `da9654d`, branch `claude/backend-followups-db6d1f`.

| Asked for | Actual `type` | Built in |
|---|---|---|
| commission_due_tomorrow | `debt_due_tomorrow`: driver, Thursday from the reminder hour (18:00 Cairo, admin setting) | W2 PR #22 |
| commission_overdue | `debt_overdue`: once a day after the Friday while anything is late (plus `debt_late_fee_added` the day the +10% lands) | W2 |
| commission_payment_confirmed / _rejected | `debt_payment_confirmed` / `debt_payment_rejected` | W2 |
| suspension_warning | `debt_suspension_warning`: the Thursday before suspension | W2 |
| coupon_issued | `coupon_issued` | W2 |
| waitlist_application_received | **`waitlist_application_received`: new in `da9654d`**, to the driver when a request lands on a full day (`data.seatRequestId`, `data.kind`) | this commit |
| driver late +5 / +10 | `commute_not_started_warning` (+5) and `commute_still_not_started` (+10), to each passenger | W6 PR #19 |

- The money notices carry **no `data` ids**: the amount is already in the title and body. Open the debt screen (§4.16).
- New tests: `DebtCycleTest` covers due tomorrow (once a day, not before the hour), overdue, the suspension warning, payment confirmed/rejected and the coupon. `NotificationsTest` covers the waitlist application. The +5/+10 pushes are pinned in `DelayLadderTest`.

**What the app must do**
- Route each type as in the §4.14 table.
- Show an unknown type as a plain message.

**Open:** nothing.

**Merged to main in `f9cc8cc`.**
