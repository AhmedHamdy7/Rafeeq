# Answers: the Home rating prompt and the inbox text (2026-11-16)

Answers to `backend-rating-prompt-and-inbox-2026-11-16.md`. Everything below reaches the test server with the next deploy. Guide sections: 4.11 (Home, `ratingPrompt`), 4.14 (notifications), 4.17 (testing calls).

| # | Answer |
|---|---|
| 1.1 | ❌ **Not the weekly rule.** After `POST /testing/reset` on both accounts there is no history left, so her next finished ride with Ahmed is a `first_ride`. We ran your exact `duet_09` steps on the backend and got `first_ride`. Something else held it on staging. New call to find out: `GET /testing/rating-prompt` |
| 1.2 | ✅ **No.** A next booking never holds the prompt back, with the same driver or another. Tested |
| 1.3 | ✅ **Yes, reset already clears it.** No `keep: ["ratings"]` needed |
| 1.4 | ✅ **Yes, `age` moves all three:** the prompt time, the dismissal time and the "last rating" |
| 2.1 | ✅ **Fixed.** 38 types had a `body` that leaned on its `title`. All reworded, English and Arabic |
| 2.2 | ✅ **None.** Every type has a `body` in both languages. Staff-written ones (`announcement`, `staff_message`) can't be saved without one |

## 1.1 · Why `duet_09` shows no prompt

**Your reading of D3 is right, but it doesn't apply here.** `POST /testing/reset` deletes all of Mariam's bookings (and all bookings on Ahmed's commutes). Her ratings, prompt times and dismissal times live on those bookings, so they go too. With nothing left, the weekly rule has nothing to count from.

We ran your exact steps as a backend test:

1. Ahmed and Mariam ride once.
2. Mariam rates from the prompt.
3. Both reset.
4. They ride again, through drop-off and finish.

Step 4 answers `ratingPrompt.reason: "first_ride"`. So on staging something outside the rule held it back. Likely causes, most likely first:

- **One of the two resets did not run.** For example it answered `409 TRIP_IN_PROGRESS`, or the call never fired. Her old rated booking then survives, and `null` is the weekly rule. Please assert `200` on both resets.
- **Her booking was not finished.** The prompt needs `COMPLETED` or `ENDED_EARLY`. That happens at her drop-off or when the run is completed. If the run was still `IN_PROGRESS` and she was not dropped off yet, there is nothing to ask about.
- **She had dismissed or rated that booking already.**

### New: `GET /testing/rating-prompt`

It shows, for each of the caller's bookings, why Home does or doesn't ask. It changes nothing. Call it as Mariam right where `duet_09` expects the prompt, and put the answer in the failure message.

```json
{ "bookings": [ {
    "bookingId": "01…", "tripId": "01…", "driverName": "Ahmed",
    "departureAt": "2026-11-16T07:00:00+02:00", "status": "COMPLETED",
    "reason": null, "heldBy": "asked_within_week",
    "promptedAt": null,
    "lastWithDriverAt": "2026-11-16T06:00:00+02:00",
    "weeklyFrom": "2026-11-23T06:00:00+02:00"
} ] }
```

- Lists the last 31 days (and up to a day ahead), newest first, at most 20.
- `reason`: what Home would ask with (`first_ride` · `weekly` · `unusual`). `null` when held back.
- `heldBy`: `null` when it can be asked. Otherwise one of:
  - `not_finished`: not `COMPLETED` or `ENDED_EARLY` yet.
  - `rated`: she already rated this booking.
  - `dismissed`: she dismissed this prompt.
  - `rating_closed`: past the rating window.
  - `asked_within_week`: the weekly rule.
- `lastWithDriverAt`: what the weekly rule counts from. That is the latest prompt, dismissal or rated ride with that driver on her **other** bookings. `weeklyFrom` is that plus 7 days.
- Home asks for one booking at a time: the newest one that can be asked.

If `duet_09` still fails with this output, send it to us.

## 1.2 · A next booking never holds it back

Your reading is right. The rule only looks at her finished bookings and her past prompts, dismissals and ratings. Her upcoming bookings don't count. We tested a recurring seat with Ahmed: after day 1 finishes, Home asks `first_ride` for day 1 while day 2 is on her Home card.

Finding 13 (2026-11-09) came before D3 was built. If it comes back, please send the `GET /testing/rating-prompt` output.

## 1.3 · `POST /testing/reset` clears the prompt history

Yes. Her bookings are deleted, and with them every rating, prompt time and dismissal time. Her next finished ride with the same driver is a `first_ride` again. Resetting **either** of the two is enough for the pair:

- Mariam's reset deletes all her bookings.
- Ahmed's reset deletes every booking on his commutes.

## 1.4 · Forcing `weekly` with `age`

`POST /testing/bookings/{booking}/age {days: 8}` moves all three times the weekly rule reads:

- the booking's prompt time and dismissal time are moved back by `days`;
- the "last rating" is **the departure of the ride she rated**, not the moment she rated it, and `age` moves that departure.

Two things to watch:

- The rule takes the **latest** of all her other bookings with that driver. Age **every** booking she has with him in the last 7 days, not only the rated one. That includes a booking where she only saw or dismissed the prompt.
- The new ride must leave **after** the aged one. Otherwise it counts as `first_ride`.

Tested: rate day 1, age it 1 day, finish day 2 → `null` with `heldBy: "asked_within_week"`. Age day 1 by 7 more → `weekly` for day 2.

You can also skip the setup: `POST /testing/bookings/{booking}/rating-prompt {reason: "weekly"}` forces the prompt on any finished, unrated booking.

## 2 · The inbox shows only `body`

Every type's `body` now:

- reads as a full sentence without its `title`;
- names the driver or passenger (`:name`) and the day (`:date`) wherever the title did, in both languages.

A test now checks this for every type.

Two calls now send data they didn't before:

- `seat_offered` and `seats_offered_by_driver` now carry the driver's first name.
- `rating_reminder` now carries the trip's day.

**Rows already in an inbox keep their old text.** The text is set when the notification is sent. Reset the account (or wait for new notifications) to see the new text.

`POST /testing/push` fills `name`, `date` and `days` for you. Send any other placeholder in `params`, or it stays as written, for example `:time`. Those are `time`, `minutes`, `amount`, `fee`, `hours`, `item`, `ordinal`, `case`, `from`, `to`, `place` and `count`.

Staff write their own text for `announcement` and `staff_message`, and the dashboard requires both a title and a body. A staff body may still lean on its title. That is up to staff.

### The 38 reworded types (English)

The Arabic follows the same wording. Placeholders are filled in when the notification is sent.

| Type | New `body` |
|---|---|
| `report_resolved` | The safety team has dealt with your report. Open it to read what they did. |
| `report_closed` | The safety team has closed your report. Open it to read their answer. |
| `account_on_hold` | Your account is on hold while we review it, so new bookings are paused. Reference :case. |
| `account_reinstated` | Your account is active again. You can book as usual. |
| `seat_approved` | :name approved your seat request. Your seat is confirmed. |
| `chat_message` | :name sent you a message. Open the trip to read it. |
| `active_trip_cancelled_aboard` | :name stopped the trip. The part you rode still counts and you owe nothing. Open it to see what else runs from here. |
| `seat_offered` | A seat opened up on :name’s commute on :date. The first to accept gets it. |
| `seats_offered_by_driver` | :name offered you a seat on :days day(s). Accept to confirm — the first to accept each day gets it. |
| `change_proposal_applied` | Everyone affected agreed to the proposed change, so your commute has been updated. Check your next journey. |
| `change_proposal_rejected` | One of your passengers declined your proposed change, so nothing changed. You can revise it and ask again. |
| `rating_due_riders` | Rate the riders on today’s commute. Nobody sees a rating until both sides have rated. |
| `rating_reminder` | Ratings for your trip on :date close soon, so rate it today. |
| `trip_started` | :name has started today’s commute. Open the trip to follow the car. |
| `trip_starts_in_hour` | Your commute leaves at :time, in about an hour. Open the trip for the details. |
| `trip_starts_soon` | Your commute leaves in :minutes minutes, at :time. |
| `driver_heading_to_you` | :name is on the way to you. You are the next pickup, so be ready at your meeting point. |
| `driver_arrived` | :name has arrived at your meeting point. |
| `commute_not_started_reminder` | Your commute was due to leave at :time and has not been started. Tap Start Commute so your passengers can see you are on the way. |
| `commute_still_not_started` | :name is :minutes minutes late: the commute was due to leave at :time and still has not started. |
| `late_boarding_warning` | You boarded after the 5-minute grace on :date. This is warning :ordinal: the 1st and 2nd are warnings; a 3rd may lead to a fee. Tap to review or dispute. |
| `missed_commitment_warning` | Your seat on :date was not used and was too late to be filled. This is warning :ordinal: the 1st is a warning; a 2nd may lead to a fee. Tap to review or dispute. |
| `late_cancel_fee` | You cancelled :date within 12 hours of departure, so EGP :amount is due unless somebody from the waiting list takes your seat. Appeal within :hours hours. |
| `debt_due_tomorrow` | Your commission of EGP :amount for this week is due by Friday 23:59. Pay with InstaPay from the Payments screen. |
| `debt_overdue` | Your commission of EGP :amount is overdue. Pay with InstaPay to publish commutes and accept new riders again. Your booked rides continue. |
| `debt_late_fee_added` | A late fee of EGP :fee was added to your overdue commission, so you now owe EGP :amount. Until it is paid you cannot publish commutes or accept new riders. |
| `debt_suspension_warning` | Pay the EGP :amount you owe by Friday 23:59, or your upcoming trips will be cancelled and driving suspended. |
| `debt_payment_rejected` | We could not find your InstaPay payment. Check the reference and send it again, or contact support. |
| `coupon_issued` | You have a coupon for EGP :amount off what you owe next, for a ride that did not go as it should. |
| `driving_suspended_for_debt` | Your driving is suspended because your commission was not paid by the second Friday, and your upcoming trips were cancelled. Pay EGP :amount to drive again. You can still ride as a passenger. |
| `driver_item_rejected` | Your :item needs a new upload. Open the app to see why, and replace only this item. |
| `driver_documents_rejected` | Your driver documents need a change. Open the app to see what to fix and send them again. |
| `driver_item_expiring` | Your :item expires on :date. Upload the renewed one to keep driving. |
| `driver_licence_expired` | Your driving licence has expired, so your commutes are paused and upcoming trips are cancelled. Send your renewed licence for review to drive again. |
| `driver_suspended` | Your driving is suspended: your commutes are paused and upcoming trips are cancelled, with no fee. You can still ride as a passenger. Contact support. |
| `vehicle_registration_expired` | Your car registration has expired, so your commutes are paused and your bookings were cancelled. Upload the new registration, and you can resume once we approve it. |
| `vehicle_suspended` | Your car was suspended after we reviewed a report about it, so your commutes are paused. Contact support to find out what is needed. |
| `vehicle_reinstated` | Your car is approved again, so you can resume your commutes now. Cancelled bookings do not come back automatically. |
