# RAFEEQ — what the v3 handoffs change

> **Purpose:** one place that says, honestly, what the three v3 handoff documents change about a
> backend that is already built and tested — what is additive, what replaces working code, and what
> **contradicts a decision already implemented**.
>
> **Read this before planning any of it.** The handoffs' own delivery contract says: *"If conflicts
> remain, log them; do not silently implement outdated policies."* This is that log.
>
> **Sources** (authoritative, in the handoffs' own order of precedence):
> `RAFEEQ_Backend_Features_01_02_03_v3/` §v3 sections > the v2 appendices > everything else.
>
> **Current state being compared against:** 126 endpoints · 1,620 tests green · Phases 0–7, 9, 11
> complete; 10, 12, 13 all but a decision each.

---

## 0. The honest summary

This is **not a round of adjustments.** It is close to a second version of the product. It touches
phases 2, 3, 5, 6, 7, 9, 10, 11 and 12 — several of them at the model level, not the endpoint level.

Three things are genuinely large:

1. **The commute model is re-shaped.** Today one offer carries one schedule, one price and one
   capacity. v3 wants a **template** with a **per-weekday** departure time, promised arrival, price
   and capacity, materialised into **occurrences**. That is a migration of the busiest tables in the
   system plus every query that reads them.
2. **Two whole subsystems that do not exist:** seat **offers** (fan-out to a waiting list, first
   server-commit wins, no timer) and **booking switch** (atomic, per-date or future-scope, with an
   opt-in back onto the old waiting list).
3. **A third:** `change_proposals` — a driver changing route, time or pickup needs **unanimous
   explicit approval** from affected passengers, and any revision resets all approvals.

And six things **contradict** what is already built and tested. Those are in §2 and each needs your
decision before I touch the code, because implementing either side silently would be worse than
asking.

---

## 1. Confirmed removals — no decision needed

| | |
|---|---|
| **The PIN, biometrics, account switching** | F01 §10 is explicit: remove from the mandatory MVP journey. Deletes `devices.has_local_pin`, `OtpPurpose::PinReset`, `PATCH /account/devices/current/security`, screens 2/3/4, and the guide's whole "local PIN, concretely" section. Session restore becomes silent: valid refresh → in; expired → phone. |

---

## 1.5 Decisions taken — 2026-10-13

Four of the six conflicts in §2 are now settled. Recorded here because each one changes code that
exists and passes tests, and in a year the reason will matter more than the choice.

| # | Question | Decision | Consequence accepted |
|---|---|---|---|
| **2.1** | Ratings: double-blind, or edit/delete anytime? | **Implement v3 literally — edit and delete at any time.** Double-blind is removed. | Stated plainly because it was raised and overruled, which is the user's call: a person can read a poor rating about themselves and revise their own in response. Ratings become partly reciprocal rather than independent. The visible-at machinery, the edit lock and the reveal command all come out. |
| **2.2** | Passenger verification gate | **Removed.** A passenger books with a verified phone number and nothing more. | The driver's approval of each request is now the only check before somebody rides. The "verified" badge stops meaning "ID-checked" for passengers, so the mobile guide must not imply it does. |
| **2.3** | Gender and women-only | **Gender stays required, and the filter stays a HARD exclusion** (pitfall #15). | Keeps the promise real: a women-only commute removes everyone else rather than ranking them lower. "Gender-neutral" therefore means *the app does not assume a gender*, not *gender is not collected*. |
| **2.4–2.6** | Public profile ids · trusted-contact count · one name vs two | **Still open.** Carried into the slice that touches each. | — |

Sequencing: **the order in §5**, so the mobile team is never blocked on a half-finished migration.

### 1.6 Decisions taken inside slice 1 (the PIN removal)

Three judgement calls that nobody asked about, recorded so they can be reversed cheaply rather
than discovered later.

| Question | Decision | Why, and what reverses it |
|---|---|---|
| One breaking `next_step` change, or both old and new values for a release? | **One breaking change.** | A transition window means the server emits a value it wants nobody to use, and the guard that keeps the guide honest cannot tell a deprecated value from a live one. The window's only real benefit is calendar time for the app — and the app has to change anyway, because `CREATE_PIN` leads to a screen v3 deleted. Reverse by returning the old value behind a request-header opt-in. |
| Which endpoint returns `ROLE_SELECTION`? | **Only `PUT /me/basic-profile`.** A cold start resumes at `BASIC_PROFILE`. | F01 §5 and the acceptance criteria both say to resume at Basic Profile "even if profile fields are already populated". So the launch router never sends `ROLE_SELECTION` — the profile screen does, once, on submit. This is the opposite of what a reader expects from "role missing → ROLE_SELECTION" in the decision tree, which is why it is written down. |
| Delete `biometric_enabled` too? | **Kept, as an inert device preference.** | v3 says biometrics must not be **mandatory**, which is not the same as "remove them". With no unlock screen left, the value drives no server decision, so dropping the column would be a schema change with no behavioural payoff — and re-adding a column is more expensive than ignoring one. |

🔴 **The mobile team must be told before their build goes red.** The auth endpoints are live with
them. `COMPLETE_PROFILE` and `ACCOUNT_SUSPENDED` stop being returned the moment this deploys, and a
client that switches on `next_step` without a default branch will land on no screen at all.

---

## 2. 🔴 Direct conflicts with implemented, tested decisions

### 2.1 Ratings — "edit/delete anytime" vs double-blind

- **Built (Phase 10):** double-blind. Neither party sees the other's rating until both have rated or
  7 days pass. An **edit is refused the moment a rating becomes visible**, and the average does not
  move while a rating is hidden — all three tested, and the reason is written down: without them,
  "rate 5 → wait for the reveal → read hers → change mine to 1" is two ordinary API calls.
- **v3 (F03):** *"can edit/delete anytime; active ratings recalc role-specific aggregates"* and
  *"anonymous toggle"*. No mention of double-blind.
- **These cannot both be true.** Edit-anytime removes the protection entirely.
- **Note:** the anonymous toggle is *already* how our reviews work — we never return the reviewer.

### 2.2 Passenger verification — currently a hard gate

- **Built:** `verified:government_id` gates a seat request. The reasoning in the code: *"getting into
  a stranger's car is a higher bar than browsing."*
- **v3 (F02):** *"**No passenger verification**. Passenger can book without ID verification."*
- This is a real change to the platform's safety posture, not a convenience. On a service whose
  proposition is safe shared travel, it means a driver can be asked for a seat by an account that
  has proved only a phone number.

### 2.3 Gender — "gender-neutral app" vs the women-only hard filter

- **Built:** `users.gender` is required and drives a **hard exclusion** (pitfall #15): a women-only
  commute removes everyone else rather than ranking them lower, and gender is read from the account,
  never the request.
- **v3 (F01):** *"RAFEEQ is gender-neutral."* **(F03):** *"Woman driver may select Women-only on an
  individual commute; **eligibility enforcement needs policy decision**."*
- So women-only survives per-commute, which means gender is still needed — but "gender-neutral" plus
  an explicitly **undecided** enforcement policy leaves the most security-sensitive filter in the
  product in an undefined state. I will not change it on an inference.

### 2.4 Public profile by user id vs "no user identifiers in any payload"

- **Built:** no endpoint returns a user id. `PersonSummary` carries a public first name and nothing
  resolvable. Reviews are keyed on the *commute*, specifically to avoid handing ids out.
- **v3 (F02):** `GET /users/{id}/public-profile`.
- Adopting it means publishing user ids, which reverses a deliberate decision. There is a middle
  path (an opaque per-viewer handle), which costs more and keeps the property.

### 2.5 Trusted contacts: 3 or 5

- **Built:** max **5** emergency contacts, with the reasoning that the list is "who receives
  somebody's location".
- **v3 (F02):** *"trusted contacts (max 3)"*. A one-line change either way; I just need the number.

### 2.6 One free-form name vs `full_name` + `public_first_name`

- **Built:** a full name stored privately, and a separate `public_first_name` that is the **only**
  name ever shown to another member.
- **v3 (F01):** *"one free-form name (min 2 chars, single name allowed)"*.
- Compatible **if** the single name remains what others see. If the free-form name is shown publicly
  as entered, somebody who types their full legal name has it shown to strangers — which is what
  `public_first_name` exists to prevent.

---

## 3. Additive — no conflict, real work

### 3.1 Feature 01

| Item | Against today |
|---|---|
| `user_capabilities` (PASSENGER / DRIVER, Both = two rows) | Replaces `users.registered_role`; mode becomes UI context |
| `user_preferences` (last_active_mode, onboarding_seen_at, driver_walkthrough_seen_at, language, theme) | New table |
| `account_restrictions` with **scope ACCOUNT \| DRIVER** | New. Driver-only restriction **keeps passenger access** — today `account_status` is all-or-nothing. Also finally gives screen 35 its case reference |
| `next_step` becomes `PHONE_OTP` · `RESTRICTED_ACCOUNT` · `BASIC_PROFILE` · `ROLE_SELECTION` · `HOME` | Replaces the four current values; the guide and its guard test change with it |
| Resume at **Basic Profile** (prefilled) when role selection was never finished | New rule |
| No phone-number change or migration — a new number is a new account | Confirms current behaviour; worth stating in the guide |
| Three-slide first-install onboarding | Client-side; backend records `onboarding_seen_at` |

### 3.2 Feature 02

| Item | Against today |
|---|---|
| Driver verification as **5 items in any order** + explicit admin approval | Restructures the verification model into `verification_items` |
| **Admin sets/edits document expiry**, validated on every protected action | New |
| **Vehicle replacement = a fresh verification request**, history preserved | New flow |
| Organization membership needs **evidence** + review; selection alone proves nothing | Changes today's organisation verification |
| Approved **selfie becomes the avatar**; passenger-only avatar uses initials | New |
| Per-role stats separated (passenger trips/rating/on-time vs driver) | Partly built — `user_stats` already splits rating and trips by role |
| `on_time` formula explicitly **not decided** (tolerance, exclusions, passenger punctuality) | Today's `on_time_rate` is computed. Needs reconciling with "expose metric version and counts" |

### 3.3 Feature 03 — the biggest

| Item | Against today |
|---|---|
| `commute_templates` + `weekday_schedules` + `commute_occurrences` | **Re-models** `commute_offers` / `commute_schedules` / `scheduled_trips`. Per-weekday price, capacity, departure AND promised arrival is new |
| **Promised arrival time**, immutable without an approved proposal | New field and new rule |
| `seat_offers` fan-out · first server-commit wins · **no timer** · stays open while pickup is reachable, even after the trip starts | **New subsystem** |
| `POST /bookings/{id}/switch` — atomic, per-date or future scope, optional re-add to the old waiting list, driver-fault fee exemption | **New subsystem** |
| `change_proposals` + unanimous approval, revision resets approvals | **New subsystem** |
| No-show timer at `max(verified_arrival, agreed_pickup) + 5 min`; GPS radius configurable; false-arrival dispute pauses it | Changes today's rule |
| Driver-delay ladder: +1m reminder · +5m passenger warning · +10m violation · +20m auto-EXPIRED only if never started | New |
| Still-driving / auto-finish (+15m prompt, +5m auto-finish when nobody is aboard) | New |
| `end-early` — passenger safety exit, **immediate location-access revocation** | New |
| Late-passenger warnings delivered **after drop-off, never during the trip** | New, and a good rule |
| Interleaved pickups and drop-offs if routing is feasible | Today's model assumes pickups then destination |
| Chat scoped to request / booking / waiting-list, read-only after the ride; **no Messages tab** | Changes today's per-booking conversation |
| Optional local encrypted audio recording, 24h retention, no playback, upload only on report | New |
| **No price filter in Discover**, no Save on commute detail | Removes today's budget filter |
| Driver `receive-requests` toggle per occurrence | New |
| Separate 3-minute expiry for **active-ride** requests | New |
| `trip_location_samples` retention and access control | Built (90-day purge) |

### 3.4 New error codes v3 names

`DRIVER_NOT_VERIFIED` · `ACCOUNT_RESTRICTED` · `NO_SEATS_AVAILABLE` · `OFFER_ALREADY_TAKEN` ·
`PICKUP_UNREACHABLE` · `ARRIVAL_PROMISE_VIOLATION` · `OVERLAPPING_BOOKING` ·
`CHANGE_APPROVAL_REQUIRED` · `NO_SHOW_TOO_EARLY` · `ARRIVAL_DISPUTED` · `TRIP_EXPIRED` ·
`INVALID_TRANSITION` · `WOMEN_ONLY_INELIGIBLE`

Several map onto codes that already exist under different names (`SEAT_UNAVAILABLE`,
`TRIP_INVALID_TRANSITION`, `ACCOUNT_SUSPENDED`). I will map rather than duplicate, and list the
mapping in the guide.

---

## 4. Explicitly pending in v3 — must stay configurable, not guessed

The handoffs say plainly: *"Do not invent pending business rules."* So these become
`platform_settings` keys with documented defaults and a `NOT_CONFIGURED` path where money is
involved:

- pricing corridor range · commission · cancellation fee amounts and caps · payout provider
- waiting-list request timeout · overlap buffer · **women-only eligibility** · driver verification
  grace · organization evidence types · matching radius · detour threshold · map provider · GPS
  arrival radius
- review SLA · on-time formula · public review moderation · suspension appeal
- week/month passenger pause UX · legal consent for recording

---

## 5. Suggested sequencing

Ordered so that nothing is built twice and the mobile team is never blocked on a half-migration.

| # | Slice | Why here |
|---|---|---|
| **0** | ✅ Answer §2 | Six conflicts. Each one changes code that exists and is tested. Four answered in §1.5; 2.4–2.6 carried |
| **1a** | ✅ Remove the PIN · new `next_step` | Feature 01 is self-contained, and every screen depends on `next_step` being right |
| **1b** | `user_capabilities` · `user_preferences` · `account_restrictions` · `POST /me/initial-role` · `GET /me/onboarding-state` · `PATCH /me/preferences` · then add `ROLE_SELECTION` | Split from 1a because the PIN removal is a **breaking** change the mobile team needs now, and holding it behind three new tables would delay it for no reason. `ROLE_SELECTION` is added **last**, when the server can actually return it |
| **2** | Reorganise `RAFEEQ_MOBILE_API_GUIDE.md` feature-by-feature | Cheap, and the mobile team reads it while 3–6 are built |
| **3** | Feature 02: verification items · expiry · vehicle change · organisation evidence · avatar | Independent of the commute re-model |
| **4** | The commute re-model: templates · weekday schedules · occurrences · promised arrival | The foundation 5 and 6 sit on. The riskiest migration in the list |
| **5** | Seat offers · the waiting list fan-out · the acceptance transaction | Needs occurrences to exist |
| **6** | Booking switch · change proposals | Needs 4 and 5 |
| **7** | Trip lifecycle deltas: no-show timer · delay ladder · still-driving · end-early · late warnings | Needs 4 |
| **8** | Chat scoping · audio recording · reconcile ratings with whatever §2.1 decides | Last, least coupled |

---

## 6. What I will not do

- Change the women-only filter on an inference while its enforcement policy is marked undecided.
- Remove double-blind ratings, or keep them, without being told which.
- Delete the passenger verification gate without confirmation — it is a safety decision with a
  written rationale, not a leftover.
- Publish user ids to satisfy an endpoint shape when a decision exists against it.
- Invent a fee, a tolerance, a radius or an SLA that the handoffs mark as pending.
