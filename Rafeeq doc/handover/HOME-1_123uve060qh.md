<!-- task: 123uve060qh · assign: Omar Aly (94558637) · status: backend answered - check -->

**Backend answered** — merged to main in `6195a01` (branch `claude/backend-home-fbbc8c`).

**Built:** `nextJourney` now picks, first that exists:
1. the journey **happening now** (run started, or departure passed and the driver hasn't started — today only);
2. a journey that **ended on her today** — `cancelled_by_driver`, `expired`, `exited`, or `completed` and not yet rated — until the end of that day, Cairo time;
3. the **soonest upcoming** journey (unchanged);
4. a **completed ride still waiting for her rating**, inside the rating window — **only when nothing is upcoming**.

A ride she cancelled herself is still not shown.

**Decision for Omar:** the request said "completed → until rated or the rating window closes". Taken literally, a ride from 5 days ago would hide tomorrow's pickup for a week, so I narrowed it: completed rides stay on the card all of their own day, and after that only when nothing is upcoming (the rating is still in `GET /ratings/pending`). Please confirm, or tell me to make it literal.
"Until she has seen it" is approximated by time; nothing records that she looked at the card.

**App (Filter):** nothing to change for this part; read `nextJourney.phase` (HOME-2).


