# Driver Requests: business questions

These decisions are still open. Everything else from the mobile team's list is answered by the existing rules. Questions 1 and 2 affect what the driver sees on a request.

## Holding up the mobile team

### 1. When can't a driver accept a request that would break a promised arrival?

The rule says a driver must reject or counteroffer a request that breaks a promise to a confirmed passenger, and it gives no time limit. Today the app blocks Accept only in the last 16 hours before departure. Earlier than that, the driver can accept, and the promised arrival can be missed.

- **Option A: block only in the last 16 hours.** It works this way today, and the rule would be reworded. Before those 16 hours, times can still adjust, but a passenger may arrive later than promised.
- **Option B: block on every day.** It matches the rule and the approved screens, and no promise is ever broken by accepting. Drivers would refuse more requests, a recurring request could be blocked by one tight day, and it needs a change to the system.

**Recommendation:** Option B.

**Rule affected:** BOOK-15.

**Answer:**

### 2. What should the driver read when Accept is blocked?

The design says "Would miss Salma's agreed pickup (07:05)". The system doesn't store an agreed pickup time for each passenger. It stores one promised arrival for the whole trip, and that's what it checks.

- **Option A: talk about the arrival.** For example, "Would arrive at 08:14, after your promised 08:10 · cannot accept as is". This can ship now, and it names nobody.
- **Option B: name the passenger and their pickup, as designed.** It's clearer for the driver, but each booking would need an agreed pickup time, and the system would have to check those too.

**Recommendation:** Option A for the beta.

**Rule affected:** BOOK-15.

**Answer:**

**Wording, if Option A:**

### 3. What should the driver read when a recurring request can't be seated on every day?

A driver accepts a passenger for Sun, Tue and Thu, but some of those days are already full. The passenger is confirmed on the other days. The design shows only "{name} confirmed", so the driver wouldn't know about the missing days.

**Recommendation:** add a line to the confirmation, such as "Rana is confirmed for 8 days. 2 days were full."

**Rule affected:** none yet; this would be a new rule.

**Answer:**

**Wording:**

## Please confirm

These are already built this way for now. Tell me if any is wrong.

### 4. An answered request stays on the Requests list only until the next refresh

After that, the list shows only requests still waiting for an answer.

**Confirmed? Yes / No:**

### 5. "Laila accepted first" stays on the waiting list until that day's commute departs

**Confirmed? Yes / No:**

### 6. The driver never learns who declined a seat offer

The driver sees who was offered a seat, who got it and whose offer closed, as the approved design shows. A member who declined still reads "Offer sent" until someone accepts, then "Offer closed", like everyone else who missed the seat. The rule that a driver is never told who declined is only in the mobile guide, so it should be added to the business rules.

**Confirmed? Yes / No:**

### 7. The walk shown on a counteroffer option is a straight-line distance

"About 450 m" is measured in a straight line from the point the passenger asked for, not along a walking route, so the real walk can be longer.

**Confirmed? Yes / No:**

## For the design team

These follow existing rules, and the screens need updating:

- Request and waiting list cards show the first name and one initial, not "Rana Samir" or "RS" (SAFE-18).
- "In another commute" doesn't name the other driver or the day (SAFE-18).
- "New Passenger" is hidden in the beta (BOOK-42).
- The counteroffer screen drops "Your first stop" (already listed as G1).
