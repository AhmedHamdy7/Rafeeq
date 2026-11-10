# Driver Home: business questions

These decisions are still open. Questions 1 and 2 are holding up the mobile team now.

## Holding up the mobile team

### 1. Should Start Commute stay active before the driver is allowed to start?

A driver can start a commute only from 90 minutes before departure. The design shows the Start Commute button on any commute today, even earlier than that.

- **Option A: keep it active.** A driver who taps too early is told it's too early on the next screen.
- **Option B: disable it until starting is allowed.** The driver sees why before tapping. This needs wording for the disabled button.

**Recommendation:** Option B.

**Rule affected:** DH-10.

**Answer:** Option B. Recorded in D-0033.

**Wording, if Option B:** You can start at 05:30 / يمكنك البدء الساعة 05:30

### 2. What should a driver with paused commutes see on Driver Home?

Today this driver sees "No commutes yet", which isn't true. A driver's commutes can be paused because the driver is suspended, or because the driver paused them.

- **Option A: one message for both.** It's simpler, but a suspended driver may be led to think they can resume.
- **Option B: a different message for each.** A suspended driver can't resume until reinstated, while a driver who paused can resume at any time.

**Recommendation:** Option B.

**Rules affected:** COM-23, COM-37.

**Answer:** Option B. Recorded in D-0033.

**Wording for a suspended driver:** Your commutes are paused because your driving is suspended. / رحلاتك متوقفة لأن القيادة موقوفة على حسابك.

**Wording for a driver who paused:** Your commutes are paused. Resume one to start driving again. / رحلاتك متوقفة. استأنف إحداها لتعود إلى القيادة.

## Still open from earlier

### 3. What should a driver with an expired car registration read?

The app knows when a car registration has expired, but the wording for it was never decided.

**Answer:**

**Wording:**

### 4. Is the driving licence approved with the application, or on its own?

The rule says the admin approves or rejects each item. Today, approving a driver's application also approves the licence, without a separate step.

- **Option A: approve it with the application.** Keep how it works today and reword the rule to say so.
- **Option B: approve it on its own.** The admin takes a separate step for the licence, as the rule says today. This needs a change to the system.

**Recommendation:** Option A.

**Rule affected:** ADM-01.

**Answer:** Option A. Recorded in D-0033.

### 5. Must every commute day have a promised arrival time?

The rule says the driver sets a latest promised arrival for each weekday. In practice, a driver can also save a day with a fixed departure and no promised arrival. On those days, the "arrive by" line doesn't show.

- **Option A: allow days with no promised arrival.** Keep how it works today and reword the rule to say so.
- **Option B: require one on every day.** Existing days without one would need a time, and this needs a change to the system.

**Recommendation:** none yet. This depends on whether a passenger should always be promised an arrival time.

**Rule affected:** COM-30.

**Answer:**

## Please confirm

These two are already built this way for now. Tell me if either is wrong.

### 6. A suspended driver's car items show as "Suspended"

Before, they showed as "Action required", which told the driver to fix something they couldn't fix. The rules only define Suspended for the driver's verification as a whole, not for a single item.

**Confirmed? Yes / No:**

### 7. A licence isn't shown as "Under review" when no reviewer has the application

After a rejection or a withdrawal, the licence now shows as "In progress" until the driver submits again.

**Confirmed? Yes / No:**
