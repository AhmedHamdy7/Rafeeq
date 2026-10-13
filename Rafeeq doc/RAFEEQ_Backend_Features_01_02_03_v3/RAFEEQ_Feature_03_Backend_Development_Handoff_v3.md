# RAFEEQ — Feature 03: Home, Discover & Complete Commute Lifecycle — Backend Implementation Handoff v3

**Status:** Start backend now; business policies and payment integrations marked pending. This document covers all of Feature 03, not only Home and Discover.

## Approved v2 + latest user overrides (CRITICAL)
- Gender-neutral app. Woman driver may select Women-only on an individual commute; eligibility enforcement needs policy decision.
- Passenger tabs: Home, Discover, + private demand, Trips, Profile. Driver tabs: Home, Requests, + create, Commutes, Profile. No Messages tab; chat scoped to request/booking/waiting-list; notification bell.
- Passenger private demands are **not bookings**, can be multiple, edit/pause/resume/delete, and drive opt-in matching alerts. Search alone doesn't create a demand. No Discover price filter or Save button on commute detail.
- Driver route fixed across recurring weekdays; each weekday has independent departure, promised arrival, price within route range, capacity and bookings. Stops: first pickup, last drop-off, interleaved roles allowed if routing feasible. Driver can create several non-overlapping commutes, duplicate template, or create return commute without copying bookings.
- Waiting-list membership requires driver approval **except** when explicitly opted-in after switching away from a confirmed booking (latest user decision); unlimited size. Seat opens → automatic offer to all currently available eligible waiting-list members; **first eligible server-committed acceptance wins**, no 5-minute timer or driver manual selection.
- **Latest user clarification on offer lifetime:** open while seat remains free and passenger pickup is **reachable**, including after commute starts; close on seat taken, trip expiry/finish, or pickup becoming unreachable. This supersedes wording 'until trip starts'. No arbitrary time limit. Driver may accept new active-ride requests if feasible; those separate incoming active-ride requests expire in 3 minutes.
- **Latest user decision on switching:** passenger can switch even when replacement is available only for a single date; no guarantee old recurring seat can be reclaimed. A recurring seat released/paused/canceled may be filled by someone else. Old passenger may only regain seat when it becomes free and receives a new offer. Do **not** require guaranteed future replacement capacity as prerequisite for a single-date switch.
- **Latest user decision on old waiting list:** during switch confirmation ask whether passenger wants to join old driver's waiting list. If yes, backend re-adds automatically without old driver's approval. If no, do not re-add. For per-date switches scope membership to that date; recurring scope must reflect selected future weekdays.
- **Latest user decision on penalties:** `VOLUNTARY_SWITCH` follows normal cancellation policy; `DRIVER_DELAY_SWITCH` and driver-fault exit carry **no passenger cancellation fee**. Atomic switch must never create two overlapping confirmed bookings.
- Driver-created recurring booking approval can reserve selected weekdays indefinitely; capacity is per occurrence. Pause/cancel one occurrence or future scope; future seat reservations lost when canceled, and waiting list gives no guaranteed recovery.
- Price changes only affect new bookings; snapshot agreed seat price in each booking. Route/time/pickup changes affecting confirmed passengers require unanimous explicit approval, revisions reset approvals; no response means no change.
- User's latest acceptance of the 'first accept' algorithm does **not** authorize first-device-tap priority. Server transaction order determines winner; same seat never allocated twice.

## Domain model (suggested)
- `commute_templates(id, driver_id, route_id, vehicle_id, status ACTIVE|PAUSED|DELETED, preferences, women_only, created_at)`
- `route_stops(id, route_id, order_index, lat, lon, place_name, kind PICKUP|DROPOFF|BOTH, is_first, is_last)`; custom booking stops separate.
- `weekday_schedules(id, template_id, weekday, departure_local_time, promised_arrival_local_time, seat_price, seat_capacity, timezone)`
- `commute_occurrences(id, template_id, service_date, weekday_schedule_id, departure_at, promised_arrival_at, status SCHEDULED|READY|IN_PROGRESS|COMPLETED|EXPIRED|CANCELED, capacity_snapshot, price_snapshot, vehicle_snapshot, version)`
- `passenger_demands(id, user_id, origin, destination, weekdays, preferred_time, filters, status ACTIVE|PAUSED|DELETED)`
- `search_history(id, user_id, query, created_at)`; clear only history-based personalization.
- `seat_requests(id, occurrence_id, passenger_id, pickup, dropoff, note, recurrence_scope, status PENDING|COUNTEROFFER|ACCEPTED|REJECTED|EXPIRED, expires_at)`
- `bookings(id, occurrence_id, passenger_id, pickup_coordinates, dropoff_coordinates, pickup_at, agreed_price, status CONFIRMED|ONBOARD|COMPLETED|CANCELED|NO_SHOW|ENDED_EARLY|EXITED_DUE_TO_DRIVER_DELAY, recurring_agreement_id, created_at)`; booking agreement snapshots immutable unless explicitly approved.
- `recurring_agreements(id, passenger_id, template_id, weekdays, status, effective_from, effective_until NULL, accepted_terms_snapshot)`; materialize rolling occurrences transactionally.
- `waiting_list_requests(id, passenger_id, occurrence_or_template_scope, pickup, dropoff, status AWAITING_DRIVER|APPROVED|REJECTED|LEFT, availability_today, source STANDARD|RETURN_AFTER_SWITCH)`
- `seat_offers(id, occurrence_id, waiting_list_member_id, seat_release_event_id, status OPEN|ACCEPTED|TAKEN_BY_OTHER|INELIGIBLE|CLOSED, created_at, closed_at)`; one event fan-out, server validates at acceptance.
- `change_proposals(id, template_id, scope, changes_json, status DRAFT|PENDING|APPLIED|REJECTED|WITHDRAWN|EXPIRED, version)`; `change_approvals(proposal_id, affected_booking_id, response, responded_at)`.
- `trip_stops(id, occurrence_id, booking_id, stop_type, order_index, eta_at, arrived_at, gps_evidence)`; `pickup_disputes(...)`; `reliability_events(...)`.
- `chat_threads(id, context_type, context_id, status)` / `messages`; `reviews`; `reports`; `sos_alerts`; `trip_location_samples` with retention and access controls; `audit_events` / `outbox_events`.

## API proposals: passenger
| Method | Path | Notes |
|---|---|---|
| GET | `/passenger/home` | Next confirmed occurrence only; context flags for delayed/arrived/onboard |
| GET | `/discover/commutes` | Geospatial/time filters, no price filter; seat availability server-derived |
| GET | `/commutes/{occurrenceId}` | Public route, driver rating, vehicle type/color, agreed points and eligibility |
| GET/POST/PATCH/DELETE | `/me/demands` | Multiple private demands; pause/resume; server match alerts |
| GET/DELETE | `/me/recent-searches` | Account-synced; Clear All only clears search history |
| POST | `/commutes/{id}/seat-requests` | Structured pickup/drop-off + optional note + scope |
| POST | `/seat-requests/{id}/accept-counteroffer` | Explicit agreement to changed coordinates |
| GET | `/me/trips` | Upcoming, Requests, Waiting lists, Demands, History |
| POST | `/commutes/{id}/waiting-list-requests` | Driver approval needed unless eligible opted-in return after switch |
| POST | `/seat-offers/{id}/accept` | Transactional winner; switch intent/scope if overlapping |
| POST | `/seat-offers/{id}/decline` | Only declines this offer, doesn't remove membership |
| PATCH | `/waiting-list-memberships/{id}/availability` | Available/Not available today |
| POST | `/bookings/{id}/switch` | One date/future weekdays, cancellation reason, old waitlist opt-in |
| POST | `/bookings/{id}/cancel` | Date or all future; driver-fault exemptions |
| POST | `/bookings/{id}/ready` | Toggle; no timer impact |
| POST | `/bookings/{id}/report-incorrect-arrival` | Freeze no-show validation pending review |
| POST | `/bookings/{id}/end-early` | Passenger safety exit, immediate location-access revocation |

## API proposals: driver
| Method | Path | Notes |
|---|---|---|
| GET | `/driver/home` | Next/active occurrence only, reminders and status |
| POST | `/driver/commute-templates` | Create draft route/stops, weekday schedules and capacity |
| POST | `/driver/commute-templates/{id}/publish` | Driver verification, valid vehicle and capacity gate |
| PATCH | `/driver/commute-templates/{id}` | Draft/unbooked changes or price-only for future new bookings |
| POST | `/driver/commute-templates/{id}/pause` | Explicit cancellation warnings, future booking effects |
| POST | `/driver/commute-templates/{id}/resume` | Reopen, automatic eligible waitlist offer fan-out |
| DELETE | `/driver/commute-templates/{id}` | Archive, never erase history/fees |
| POST | `/driver/commutes/{id}/duplicate` | Copy config only |
| POST | `/driver/commutes/{id}/return` | Reverse route prefill only |
| POST | `/driver/commutes/{id}/change-proposals` | Combined change request with affected bookings |
| POST | `/change-proposals/{id}/respond` | Passenger accept/reject/withdraw |
| POST | `/driver/seat-requests/{id}/respond` | Accept/reject/counteroffer, validate promised arrival |
| POST | `/driver/waiting-list-requests/{id}/respond` | Approve/reject membership |
| POST | `/driver/commutes/{id}/start` | Can start early, server timestamps, GPS/tracking consent |
| POST | `/driver/bookings/{id}/arrived` | Validate GPS proximity, automatic chat+push |
| POST | `/driver/bookings/{id}/picked-up` | Driver-only, passenger can dispute |
| POST | `/driver/bookings/{id}/no-show` | Enforce later-of-arrival/pickup + 5min and disputes |
| POST | `/driver/bookings/{id}/dropped-off` | Complete that booking; rating enabled |
| POST | `/driver/commutes/{id}/finish` | Only after all passengers resolved |
| POST | `/driver/commutes/{id}/still-driving` | Extend warning/auto-close checks 15 min |
| PATCH | `/driver/commutes/{id}/receive-requests` | Default true; per occurrence |

## Seat allocation and switching: mandatory transaction design
1. Seat release (cancellation, capacity increase, pause resume, passenger switch) emits `seat_opened(occurrence_id, seat_count, event_id)` once via outbox.
2. Eligibility engine filters waiting-list APPROVED, available today, compatible route/vehicle/women-only policy, pickup still reachable, promised arrival feasible, no blocked relationship; includes members confirmed elsewhere only if switch path is allowed.
3. Fan out OPEN offers to eligible members, without reserving seat for each offer. An offer is a chance to compete, **not a confirmed seat**.
4. `accept` runs in DB transaction: lock occurrence capacity/seat ledger; re-evaluate offer eligibility, pickup reachability, current capacity, booking conflicts and switch intent; select winner based on transaction commit/lock order. Use uniqueness constraints preventing same seat or passenger overlapping confirmations.
5. If switch, atomically release old booking(s) for chosen date/future scope, confirm new, create optional old waiting-list membership only when opted-in, and emit outbox notifications. Apply driver-delay fee exemption; voluntary fee calculation is deferred.
6. Close losing offers with `TAKEN_BY_OTHER` when capacity fully used; members stay on waiting list. If seat remains available, leave eligible offers open. If pickup unreachable or trip finishes, close with `INELIGIBLE/CLOSED`.
7. Retries with same idempotency key return same outcome; concurrent acceptors cannot both win. Handle offer arrival after seat taken with 409 `SEAT_ALREADY_TAKEN` and refresh UI.
8. **No fixed offer expiry**. On active trip, reachability depends on actual route progress and predicted ETA. Trip start alone doesn't invalidate offers.

## Important recurrence behavior
- Recurring confirmed seat rights exist only while agreement/occurrences are active; don't treat waiting-list membership as guaranteed seat.
- If passenger switches **only one date**, old seat for that date is released and may be filled. Old future dates remain unless passenger explicitly changes them. If they opt in, add old waitlist for that date.
- If passenger switches **all future selected weekdays**, release old affected future bookings and their reserved capacity; replacement capacity is not guaranteed on dates not actually confirmed. UI must clearly warn; exact future materialization policy documented in response.
- Pausing/canceling passenger attendance for a week/month releases selected dates; other members may claim them; passenger returns only via available seat/offer, not automatic restoration. **New UX scope for week/month pause is not yet fully specified**: implement date-range cancellation/release primitive, avoid claiming a guaranteed automatic pause restoration.
- Waiting-list membership date-specific or weekday-recurring. A newly freed seat fan-out applies only to matching eligible dates.
- Explicit opt-in to old waiting list after switch; without opt-in, do not add.

## Pickup, delays and trip lifecycle
- Route only confirmed stops, in feasible order; custom coordinates require structured driver/passenger acceptance. ETA provider recommendation is advisory; published promised arrival immutable without approved proposal.
- Driver may start early but pickup no-show timer begins at `max(verified_driver_arrival_at, agreed_pickup_at) + 5min`. GPS arrival radius configurable; false-arrival dispute pauses no-show; driver can leave after grace without remaining stationary. Automatic chat+push counts as contact.
- Late passenger boards while driver waits: `PICKED_UP`, **not** no-show; 1st/2nd validated lateness warnings, 3rd fee eligible. **Deliver late warning after drop-off**, never during trip. Separate late-cancel/no-show violation counter (first warning, second fee), with disputes.
- Driver not started: +1m reminder; +5m passenger warning; +10m violation/escalation; +20m auto-EXPIRED only if never started. If started at +12m, remains IN_PROGRESS; existing violation remains.
- Passenger can exit without fee if driver hasn't started by departure or missed agreed pickup; allow alternative request while old booking still confirmed, then atomic driver-fault switch.
- Driver can finish after all passenger statuses resolved; +15m past promised arrival show still-driving/finish, +5m auto-finish only if no onboard/awaiting passengers. Still Driving extends next check by 15m.
- Driver can cancel occurrence, all future or individual booking; within 24h of relevant departure/pickup with confirmed affected passenger(s) fee eligible; exact amounts later. Passenger pre-pickup cancel anytime; after pickup end early. Driver early end requires reason/safe stop/support.

## Ratings, chat, safety and privacy
- Per-booking 1–5 rating both directions, optional comment, anonymous toggle; can edit/delete anytime; active ratings recalc role-specific aggregates. No rating = New Driver/Passenger. Low rating prompts admin review, not automatic ban.
- Chat starts with seat request or waiting-list relationship, scoped to context; becomes read-only after relevant ride finishes; history available for dispute. Phone opens dialer; driver call icon next passenger only after start; passenger call after accepted booking.
- Report form/status/appeal once; block only after shared trip and only after upcoming shared confirmed bookings canceled; block prevents future matching both directions.
- SOS both roles during active ride: confirmation, silent support alert with location/timestamp, no automatic emergency call or contact notification, user can close with I'm Safe Now; audit. Up to 3 trusted contacts; time-limited revokeable link.
- Optional local encrypted audio recording subject to OS and legal consent; 24h retention from recording stop unless reported; no user playback/download; uploads only if available and report submitted; never promise guaranteed evidence. Backend receives uploaded evidence with access log and retention rules, not continuous audio.
- Driver trip summary and passenger history expose report, chat history and post-trip block. No centralized Messages tab.

## State errors and real-time events
- Errors: `DRIVER_NOT_VERIFIED`, `ACCOUNT_RESTRICTED`, `NO_SEATS_AVAILABLE`, `OFFER_ALREADY_TAKEN`, `PICKUP_UNREACHABLE`, `ARRIVAL_PROMISE_VIOLATION`, `OVERLAPPING_BOOKING`, `CHANGE_APPROVAL_REQUIRED`, `NO_SHOW_TOO_EARLY`, `ARRIVAL_DISPUTED`, `TRIP_EXPIRED`, `INVALID_TRANSITION`, `WOMEN_ONLY_INELIGIBLE` (policy pending).
- Publish idempotent outbox events for request/counteroffer/approved, waitlist approval, seat opened/offer accepted/offer closed, booking switched, pickup arrival, late warning after trip, driver delay/expiry, proposal revision, cancellation, SOS/report. Websocket/push consumers must deduplicate event IDs.
- Location access only while relevant active booking/ride, revoke on early exit/finish; avoid leaking other passengers' addresses or phone. Audit changes.

## Required concurrency/integration tests
1. 50 eligible waiting-list members accept 1 seat concurrently: **exactly one** confirmed; other offers close; retries stable.
2. Same passenger tries to confirm overlapping commutes concurrently: at most one confirmed, except controlled atomic switch that replaces old.
3. Driver pause/resume, seat release and recurring occurrence materialization run concurrently: no overbooking/duplicate offers.
4. Trip starts while offer is open: offer remains if pickup reachable; closes once route makes pickup unreachable.
5. Passenger switches only today, old future recurring seats untouched; opts out of old waiting list.
6. Passenger switches all future weekdays and old seat taken: no automatic reclaim, only waiting-list offer if opted in.
7. Driver delay switch has no passenger fee; voluntary switch passes cancellation policy engine.
8. GPS arrival early: grace waits until agreed pickup; arrival dispute blocks no-show; late boarded passenger gets post-trip warning.
9. Driver starts at +12m, not expired +20m; never-started expires +20m.
10. Change proposal partial acceptance never applies; revision resets all approvals.
11. Price edits preserve existing agreed booking prices.
12. Role suspension / document expiry blocks Publish/Start, but passenger actions remain accessible unless account suspended.

## Pending policy/integrations (DO NOT BLOCK CORE SCHEMA/APIs)
- Pricing corridor range algorithm, payment fees/commission/refunds, cancellation fee amount/caps, payout provider. Expose quote/fee-policy interfaces with config and `NOT_CONFIGURED` where necessary; do not charge silently.
- Waiting-list request timeout; exact overlap buffer; women-only eligibility; driver verification grace; organization eligibility; matching radius; detour threshold; map provider and GPS radius; support staffing; legal recording consent.
- Week/month passenger pause UX, recurring switch for dates with mixed availability, review/on-time metrics, trip sharing public webpage, complete notification settings and admin UI. Implement durable states/hooks, not speculative behavior.

## Delivery contract for backend engineer and AI coding agent
- These files specify **product behavior and proposed API contracts**, not proof that any endpoint/database already exists. Inspect the existing code, migrations and `RAFEEQ_MOBILE_API_GUIDE.md` first; reuse compatible conventions, do not duplicate APIs.
- Server owns authorization, eligibility, prices, capacity, times, transitions, and conflict resolution. Mobile is never authoritative. All mutations require authenticated identity, authorization, validation, idempotency where applicable, and structured error codes.
- UTC timestamps in persistence, Egypt-local calendar/day semantics in scheduling; store IANA timezone and expose ISO-8601 timestamps. Define explicit occurrence IDs for recurring rides.
- Enforce relational constraints, audit sensitive changes, avoid exposing documents/phones/location to unrelated users, use transactional outbox for notifications, and tests for concurrency/retries.
- Fields/endpoints below are **proposals**; adapt to established repository conventions while preserving behavior.
- **Do not invent pending business rules.** Implement configurable policy keys and documented TODOs for pricing, fee amounts, payment processor, regulatory/legal and admin operations.
- Source of truth: latest user decisions in conversation > Feature 01/02/03 Claude Design v2 > prior Product/Backend documents. The v2 file is included below as a source appendix. If conflicts remain, log them; do not silently implement outdated policies.

---

# Appendix: Exact Claude Design v2 reference (historical, superseded by v3 overrides above where conflicting)

# RAFEEQ — Feature 03: Claude Design Handoff — v2 (updated after design review)

**Audience:** Claude Design / UX team. **Scope:** all Feature 03 passenger and driver screens, states, navigation, microcopy and interaction flows. **Status:** Product behavior approved; **[v2]** visual design produced and reviewed in the RAFEEQ Design canvas. **Companion:** `RAFEEQ_Feature_03_Product_Flutter_Backend_Handoff.md`, Decisions & Scenarios document, and **[v2]** `RAFEEQ_Open_Items_and_Business_Cases.md`.

> Design the UI from the confirmed behaviors below. Do not invent automatic booking, offer extension, fee amounts, or unapproved trip modifications. Prefer readable **one-scenario-per-screen/section** flows over dense multi-phone infographics. Include annotations/arrows only where they clarify a state transition. Use Egypt context, EGP prices and realistic local route examples.

> **What changed in v2:** No Messages tab (chat lives inside each booking/request/waiting list; notifications behind a bell). **On Hold is removed.** A confirmed passenger who wants an overlapping commute can only **request to join its waiting list**, then switch. **The 5-minute offer rule is removed everywhere:** when a seat opens it is offered automatically to the whole available waiting list and the **first to accept** gets it. All waiting-list joins are driver-approved. No price filter, no Save button on commute detail. Passengers can hold **multiple demands** (edit / pause / resume / delete). Car type shown on offers and alternatives. Late-boarding warning arrives **after** the trip as a notification. Women-only is set only by a woman driver. Driver edit form, create-wizard stops and driver rating comments refined. Driver Report/Block/SOS made reachable. Payments and the full Notifications centre remain out of scope. All changes are marked **[v2]**.

## 1. Navigation and global design rules

**Passenger tabs (existing design may dictate labels):** Home, Discover, Trips, Messages/Notifications, Profile. Passenger `+` creates a **private commute demand**, not a driver trip. **Driver context:** Home, My Commutes/Trips, Requests, Messages, Profile; driver `+` creates published commute. Use previously agreed role switching and verification states from Feature 02. Don't silently change the approved app navigation; fit these flows into the existing design system.

**[v2] Final tabs.** Passenger: **Home · Discover · + (demand) · Trips · Profile**. Driver: **Home · Requests (badge) · + (create) · Commutes · Profile**. **No Messages tab.** Chat is opened from the related booking, request or waiting-list card (Trips / Requests / live trip) and is scoped to that item. Notifications open from a **bell with unread count** in the Home header. Driver `Commutes` has three segments: **Schedule · My commutes · History** (trip history lives here). A compact `Driver` / `Passenger` switch chip sits in the Home header when the account has both capabilities (directional switch animation from Feature 02 v2).

**Global:** Clear status chips (Confirmed, Pending, ~~On Hold~~ **[v2] removed**, Waiting List, ~~Offer Expires~~ **[v2]** Seat Offer, Paused, In Progress, Completed, Expired); strong time/date/price hierarchy; large tap targets, RTL/Arabic readiness, accessible contrast and readable mobile typography. Notifications and real-time updates must be visible without requiring manual refresh. Never display a waiting-list seat as confirmed. Clearly distinguish **scheduled departure**, **passenger's agreed pickup**, and **promised arrival**.

**[v2] Women-only:** only a **woman driver** can mark a commute `Women-only`. Every user sees the badge on cards/details; the Discover filter `Women-only` narrows results to those commutes. RAFEEQ itself is for everyone.

**[v2] Language:** English only for now; keep layouts RTL-ready.

**Required design deliverables:** named screen list, screen-by-screen annotated layouts, navigation map, interaction states, empty/loading/error/offline states, notification copy, confirmation dialogs, and clickable prototypes for critical scenarios. Show one or two phone frames per page/section rather than tiny grids.

**[v2] Delivered as:** one full-screen artboard per state (`F3P-*` passenger, `F3D-*` driver, `F3U-*` unverified driver), plus **widget-state boards** (`F3W-*`) that show every state of one widget side by side next to the page that contains it, instead of overloading one screen with many states.

## 2. Passenger Home

**Primary card:** `Your Next Commute` shows only the next **confirmed occurrence** with date/time, pickup, drop-off, driver, status and `View Trip`. Pending requests and demands live in Trips. No confirmed booking -> welcoming empty state with `Find a Commute` and `Create Commute Demand`.

**[v2]** The card also shows the driver's **car type and colour** (e.g. `Kia Sportage · Silver`).

**Suggestions:** initially generic, then active saved demands first, previous searches/trips second. Clearly mark recommendations as suggestions, never reservations. Group alerts for newly matching published commutes; don't imply search alone creates alerts.

**States:** no trip; confirmed; driver delayed; commute started; driver canceled; pickup approaching; in progress; finished. Driver-delay card must expose `Leave Without Fee` and `Find Alternative` when eligible.

**[v2] Full state list:** No commute yet · Confirmed · Pickup in 15 min · Driver started · Driver arrived · On board · Driver delayed · Canceled by driver · Commute expired · Exited without fee · Finished (rate).

## 3. Discover, search and private demand

**Discover search:** origin, destination, travel date, preferred pickup time; advanced filter sheet for ~~price~~, seats, women-only, comfort, smoking, organization, recurrence and time flexibility. On search-field focus show account-synced Recent Searches + `Clear All`; Clear All removes history, not demands.

**[v2]** **No price filter** (prices sit inside a system range per route, see Open Items). States: results, recent searches, filter sheet, loading, no results, offline, error.

**Result card:** route, weekday/date/time, price per seat, seats remaining, driver rating/count or `New Driver`, basic preferences. Open commute detail with map, fixed route/stops, driver info, promised arrival, pickup/drop-off selection, request seat, or join standby if full.

**[v2] Commute detail:** **no Save button**. Shows car type. States: seats left (incl. women-only), custom pickup, full (`Request to Join Waiting List`), new driver, and **overlaps your booking** (§7).

**Save Search:** explicit optional action creating private recurring demand; do not auto-save demand. `+` in passenger context opens demand builder: origin, destination, weekdays, time, preferences -> review -> save. Manage demands under Trips (edit, pause, delete). Demand cards should say `Private • Not a booking`.

**[v2] Multiple demands:** a passenger can create **more than one** demand. Trips › Demands lists them all; each has `Edit`, `Pause` / `Resume`, `Delete` (confirmation dialog). Paused demands are clearly labelled and stop matching.

**Example annotated flow:** Discover -> filter 08:00 New Cairo to Smart Village -> commute detail -> request seat OR Save Search -> private demand appears under Trips.

## 4. Driver Home and create commute

**Driver Home:** prominently show **one** next upcoming or currently active commute, with route, scheduled departure, confirmed passenger count, seats and contextual action (`View Details`, `Start Commute`, `Continue Trip`). Other commutes behind `View All Commutes`. When unstarted occurrence expires at departure +20 min, it moves out of hero slot; show next upcoming, plus an expired-trip notice/history link. Don't leave an expired trip pinned as primary.

**[v2] Driver Home states:** Next commute · Starting soon · In progress · Not started (07:01) · 10 min late · Expired · **Seat opened** (`A seat opened · offered to your waiting list`) · **Passenger switched away** (`Salma switched to another commute · seat offered to your waiting list`). Unverified drivers see the Feature 02 v2 reminder; `Publish` / `Start Commute` open the verification gate.

**Create wizard:** route and advertised stops -> weekdays -> for each weekday departure, promised arrival, seat price and available seats -> preferences/vehicle -> review/publish. One fixed advertised route shared across weekdays, but each weekday has **independent** time, price and capacity. Capacity limited by verified vehicle. Route previews must show pickup/drop-off points and estimated feasibility.

**[v2] Stops in step 1.** Each stop has a role badge: **First pickup**, **Pickup**, **Drop-off**, **Last stop**. Controls per stop: move up/down, toggle `Pickup` / `Drop-off`, `Make first pickup`, `Make last stop`, remove. `Add a stop` opens a small form (place name + type). Validation: the **first stop must be a pickup** and the **last stop must be a drop-off**; otherwise show `Stop order needs fixing` and disable `Next`. Seat price uses a **slider inside the route's allowed range** (e.g. EGP 60–95). Women-only toggle only for woman drivers.

**Create shortcuts:** `Add Return Commute` prefills reverse route but driver reviews independently; `Duplicate Commute` copies settings only. Explain neither shortcut copies passengers/bookings/waiting list. Driver can create multiple non-overlapping commutes same day.

## 5. My Commutes: edit, pause, resume, duplicate, delete

**Commute details menu:** Edit, Pause/Resume, Duplicate, Delete. Support scope picker for `Only this occurrence` vs `All future occurrences from [date]`. Allow different weekday time/price/capacity, not different shared advertised routes inside one recurring template.

**[v2] Edit form:** departure time is a **typed time input**; **pickup points are an editable list** (add, remove, `Make first`); seat price is a **slider within the route range**; the primary button always reads **`Save changes`**. Price-only change saves directly (new bookings only). Time or pickup changes on an occurrence with confirmed passengers become a **change proposal** (§6).

**Pause dialog:** `Pause this recurring commute?` / `All upcoming confirmed bookings will be canceled. Late cancellation fees may apply. Your previous recurring passengers will remain on your waiting list.` Actions `Keep Active`, `Pause & Cancel Bookings`. Paused card clearly labeled. ~~Resume does **not** send a broadcast and does **not** auto-book; driver sees standby and chooses whom to offer.~~

**[v2] Resume:** resuming publishes the commute again and **free seats are offered automatically to the available waiting list** (first to accept gets each seat). Nobody is auto-booked; members must accept.

**Delete dialog:** archived historical records, affected bookings canceled, potential fees, irreversible removal from active list. Use explicit destructive confirmation.

**Price change:** new bookings only; confirmation states existing confirmed passengers keep original price. Offers show current full terms.

## 6. Edit with confirmed passengers: approval UX

**Change proposal editor:** old vs proposed fields, combined in **one request**, affected passenger count, scope/effective dates. Confirmed passenger receives `Review Proposed Changes` with clearly highlighted differences and `Accept All Changes` / `Reject` / `Chat with Driver`. No partial accept. If no reply, nothing changes.

**Driver pending view:** per-passenger `Accepted`, `Awaiting Response`, `Rejected`, `Withdrawn`; actions `Edit Proposal`, `Delete Proposal`. Editing resets all approvals; show warning before revision. Passenger can withdraw acceptance until applied. Proposal expires at affected occurrence start. For recurring future scope, show per-date effects. Driver can alternatively cancel affected bookings with penalties, then edit and send fresh offers.

**Scenario:** 08:00 -> 07:30, Gate 2 -> Gate 4; Mariam accepts, Salma pending -> not applied. Driver revises to 07:45 -> both approvals reset. Show this as **three readable annotated mobile screens**, not a dense poster.

**[v2] Screens:** driver Draft · Sent · 1 of 2 accepted · Revised (approvals reset) · Rejected · Applied; passenger Respond · You accepted · Revised · Applied · You rejected.

## 7. Seat requests, pickup negotiation, confirmed bookings

**Seat request flow:** select advertised pickup/drop-off OR propose custom map coordinates, optional pickup note, desired date/recurrence -> review request. Driver receives request with passenger rating/count or `New Passenger`, pickup/drop-off, impact on promised arrival and actions Accept/Reject/Counteroffer/Chat. If driver counters coordinates, passenger must explicitly accept; a chat message alone isn't acceptance.

**Booking status UI:** Pending, Counteroffer, ~~On Hold (overlapping pending request)~~, Confirmed, Canceled, Driver Delayed, Exited Due to Driver Delay, Completed. A confirmed card shows **agreed** pickup, drop-off, price and time, not mutable advertised defaults. Passenger can edit pickup note after confirmation without reapproval; changing coordinates requires structured approval.

**[v2] On Hold is removed. New overlap rule.**

~~**Overlapping requests:** when one confirmed, other overlapping pending requests show `On Hold`; if confirmed canceled, eligible ones return to Pending.~~

- A passenger with a **confirmed** booking who opens an **overlapping** commute sees `Overlaps your booking with Nour` and only one action: **`Request to Join Waiting List`** (never a second seat request).
- The **driver must accept** the waiting-list request (same as any waiting-list join).
- Once on that waiting list:
  - **Seat free now →** `Switch Commute` is available instantly.
  - **Commute full →** wait; when a seat opens the passenger gets the seat offer with **`Accept & Switch`**.
- **Switch dialog** asks the scope: **`Only this date`** or **`Every <weekday> from now on`** (warns that the recurring seat with the old driver is released for every future date).
- After switching, the passenger is **automatically added to the old driver's waiting list** (no driver approval needed, always). The released seat in the old commute is offered to that commute's waiting list.
- Leaving the old booking follows the **normal cancellation fee rules** (same as cancelling).
- When driver delayed, passenger can seek replacement without immediately losing original; explicit `Switch to This Commute` confirmation on alternative acceptance, backend atomic switch.

**[v2] Done states:** Request sent · Waiting-list request sent · Waiting-list request (overlap) · Seat offer accepted · Switched commute · Switched only this date · Switched every Sunday.

## 8. Waiting-list membership and seat offers [v2 — 5-minute offers removed]

**Fully booked commute:** ~~`Join Waiting List`~~ **[v2]** `Request to Join Waiting List` and relevant details. **[v2] Every waiting-list join is a request the driver accepts or rejects.** Exception: a passenger who switched away from this driver is re-added automatically. Unlimited membership; don't show queue position or promise FIFO. Driver can filter/select any standby passenger, see rating, pickup/drop-off, weekday and join time.

**[v2] Member status badges for the driver:** `Free` (no booking at that time) or `In another commute` (already confirmed elsewhere, would switch if they accept).

~~**Seat opens:** notify driver only. Driver selects standby member -> `Send Seat Offer`. … `05:00 countdown` … `Offer Expired — You're Still on the Waiting List`. No `Extend` button.~~

**[v2] Seat opens:** the offer is sent **automatically to every available member** of the waiting list at once. No driver selection, no countdown. The offer detail shows **full current route, date, departure, pickup/drop-off, promised arrival, price, driver and car type**, with `Accept Seat` / `Decline` (or `Accept & Switch` for members in another commute). The **first to accept gets the seat**; everyone else's offer closes immediately with `Seat already taken · offer closed. You're still on the waiting list.` The driver sees `Seat opened · offered to all` then `First to accept got it`.

**Multiple offers:** show comparison list; accepting an overlapping offer requires switch confirmation; never silently book or discard other choices. Active-ride standby offer may end early if pickup unreachable. Driver may voluntarily wait, with warning only when other agreed pickups/arrival would be affected.

**Availability:** `Available Today` / `Not Available Today` on standby management; reversible repeatedly. Turning unavailable **[v2]** excludes the member from today's automatic offers, preserves future recurring membership; returning available doesn't restore closed offers. No penalty. ~~When driver resumes a paused commute, **no mass notification**; only newly selected offer recipients notified.~~ **[v2]** When a driver resumes a paused commute, free seats are offered automatically to available members.

**[v2] Passenger waiting-list states:** Awaiting approval · Joined · Seat free (switch now) · Full (wait for an offer) · Seat opened (accept & switch) · Not available today · Seat offer · Overlap warning · Taken by another member · Offers list.

**Separate active-trip requests:** driver sees all incoming requests in one dismissible bottom sheet, can reopen via badge. 3-minute expiry, toggle `Receive Requests During Ride` ON by default. ~~Don't confuse 3-minute request with 5-minute standby offer.~~ **[v2]** The 3-minute active-ride request is the **only** timed item left. No automatic voice announcements.

## 9. Start commute and navigation

**Driver pre-start:** next commute, planned departure, recommended departure time (traffic/boarding/safety), confirmed stops, arrival promise and `Start Commute`. Early start allowed; passenger pickup agreements unchanged. Traffic may cause ETA warnings; published times never silently edited.

**Live navigation:** ordered confirmed pickup/drop-off stops, passenger name/short note, ETA and next action. Pending stops never included. When booking changes, update route and show meaningful ETA impacts. `Receive Requests During Ride` toggle accessible without interrupting navigation.

**[v2]** A red **SOS** button is always visible in driver live navigation (Safety §13).

**Passenger live view:** map of driver location after commute starts, pickup ETA, agreed pickup pin, contact/chat, `I'm Ready` / undo. Driver call icon appears **only for next passenger to pick up and after trip starts**; passenger call icon from booking acceptance. Call opens device dialer.

## 10. Arrival, 5-minute grace, pickup, no-show, lateness

**Driver arrives:** `I've Arrived` -> GPS verification -> backend automatic push AND chat to passenger: `Your driver has arrived at your pickup point. Please meet your driver.` Driver does **not** need to manually call/message. Show waiting countdown based on later of actual verified arrival and scheduled pickup.

**At grace end:** driver sees `Mark No-Show` and `Keep Waiting`/continue navigation (no extension action). Driver can leave or voluntarily wait. Passenger can `Report Incorrect Arrival`, opening dispute and pausing no-show validation. Driver need not remain stationary throughout grace.

**If passenger boards late while driver waits:** driver taps `Picked Up`, booking becomes onboard, **not no-show**. Track lateness incident separately: 1st warning, 2nd warning, 3rd fee eligible (amount not shown until Payments rules). Avoid confusing with the distinct no-show/late-cancel counter (first warning, second fee). Provide clear explanation when warnings displayed, with dispute link.

**[v2] Timing of the late warning:** do **not** show any lateness message during the trip. After drop-off the passenger receives a **push-style notification** `Late pickup recorded (1st warning)` / `You boarded after the 5-minute grace on Sun 18 Oct. 1st and 2nd are warnings; a 3rd may lead to a fee. Tap to review or dispute.` It also appears in Notifications.

**Storyboard:** 07:58 arrived -> 08:00 agreed pickup -> 08:05 grace ends -> 08:08 passenger boards -> `Picked Up`, ~~lateness warning~~ **[v2]** lateness recorded (warning delivered after the trip), no no-show. Another branch: after 08:05 passenger absent -> `Mark No-Show`, with dispute path.

## 11. Delay, expiry, cancellation and alternative rides

**Not started timeline:** 08:01 driver reminder, 08:05 passenger warning, 08:10 escalation/violation, 08:20 `Expired` if never started. If started at 08:12, continue live commute, do not expire at 08:20. Driver Home shows next upcoming once expired.

**Passenger protection:** if scheduled departure passed and not started, OR agreed pickup passed and driver hasn't arrived, show `Leave Without Fee` and `Find Another Commute` even if trip already started. Status label `Exited Due to Driver Delay`; no passenger penalty. Replacement selection must explicitly confirm switch.

**[v2] Find another commute:** alternatives list shows **car type** and **only commutes that still have free seats**. States: Options · Request pending · Accepted (switch confirmation).

**Driver cancellation:** single occurrence, all future, or individual confirmed passenger before pickup. Confirmation warns about affected passenger count and **potential fee if within 24h**; if no confirmed affected, no fee. Fee amount deferred. On cancel, notify affected passengers, show suggested alternatives. For pause/delete, warn of future cancellations.

**Passenger cancellation:** before pickup allow cancel anytime; late-cancel/no-show policy handled separately. After pickup show `End My Ride Early` with confirmation and optional Report Problem; driver `End Passenger Ride Early` requires reason and safe-stop guidance. Safety issue -> support/SOS access.

## 12. Drop-off, finish, ratings and history

**Per-passenger drop-off:** driver taps `Dropped Off` per passenger. That passenger sees `Ride Completed`, rate driver 1–5, optional comment, `Anonymous Rating` checkbox, Report Problem. Driver can rate passenger then or later. Other passengers' trip continues.

**Finish commute:** driver finishes after all passenger states resolved; trip summary shows route, duration, passengers, earnings placeholder (final payments later). Stop live sharing; chat becomes read-only. At promised arrival +15 min, prompt `I'm Still Driving` or `Finish Commute`; auto-close after +5 min only if no onboard/awaiting passengers; if `I'm Still Driving`, extend next check 15 min.

**[v2] Driver trip summary:** per passenger, choosing stars reveals a **rating word** (e.g. `Great`) and a **comment box**. Each passenger row also links to **Chat history**, **Report a problem** and **Block**. States: Rate passengers · Rated with comment · With a no-show.

**Review:** edit/delete rating/comment anytime, separate driver/passenger rating averages, `New Driver`/`New Passenger` when none. Anonymous hides rater identity from recipient, not admins. Low-rating admin review not auto-ban.

**History:** confirmed/completed/canceled/expired records, reviews, reports, support, and post-trip `Block User`. Don't allow block during active ride. If upcoming shared confirmed bookings exist, explain they must be canceled first (normal cancellation rules may apply).

**[v2]** Passenger history lives in **Trips › History** (Completed, Canceled, Expired, Exited). Driver history lives in **Commutes › History**.

## 13. Safety, SOS, trusted contacts, recording and reporting

**SOS entry:** visible to both roles during active ride. Tap opens confirmation `Do You Feel Unsafe?` with `Yes, I Need Help` / `No, I'm Safe`. Yes silently alerts support immediately with trip/location; then show emergency dialer, contact-sharing, support. Do not end ride, sound alarm or notify other rider. Show failure/retry state. `I'm Safe Now` closes alert but preserves record.

**Trusted contacts:** up to 3 contacts, share temporary tracking link via SMS/WhatsApp by user choice; link expires at ride end or revoke. Support cannot notify contacts without user opt-in.

**Recording:** show consent/microphone status unobtrusively. Local encrypted recording where platform/legal requirements allow; permission optional; no playback/download UI. Delete 24h after recording stops unless reported/preserved; clearly say evidence may be unavailable due to device/permission constraints. Reports remain possible afterward.

**Report flow:** category, explanation, optional attachment -> Submitted -> Under Review -> Resolved, explanation, one appeal. Blocking from history/after-trip summary only; no notification to blocked party.

**[v2] Reachability:** passenger SOS from the live trip; driver SOS from the red button in live navigation; driver Report/Block from the trip summary; passenger Report/Block from the completed trip and history; Discreet mode from the Safety Centre. Screens: SOS confirm · Alert active · Send failed · Share trip (contacts / shared) · Report form / filled / under review / resolved / appealed · Block user · Block blocked by upcoming booking.

## 14. Notifications and suggested copy

| Event | Recipient | Suggested copy |
|---|---|---|
| Driver GPS arrival | Passenger | `Your driver has arrived at your pickup point.` |
| ~~Seat available~~ | ~~Driver only~~ | ~~`A seat is available. Choose someone from your waiting list.`~~ |
| **[v2]** Seat opened | Driver | `A seat opened. It was offered to your waiting list.` |
| **[v2]** Seat offer | All available waiting-list members | `A seat opened with Yasmin. First to accept gets it.` |
| **[v2]** Seat taken | Other offer recipients | `Someone else accepted the seat first. You're still on the waiting list.` |
| ~~Offer expired~~ | — | **[v2] removed** (no timed offers) |
| **[v2]** Waiting-list request | Driver | `Laila asked to join your waiting list.` |
| **[v2]** Waiting-list accepted | Passenger | `You're on Yasmin's waiting list.` |
| **[v2]** Passenger switched away | Old driver | `Salma switched to another commute. Her seat was offered to your waiting list.` |
| Commute resumed | ~~Nobody automatically~~ **[v2]** Available waiting-list members (if seats are free) | `A seat is available on a commute you're waiting for.` |
| **[v2]** Pickup approaching | Passenger | `Your pickup is in 15 minutes. Get ready.` |
| Driver 5m not started | Confirmed passengers | `Your driver hasn't started the commute yet. You can leave without a fee.` |
| Driver expired +20m | Confirmed passengers | `This commute expired. Explore available alternatives.` |
| Change proposal revised | Affected passengers | `Your driver updated the proposed changes. Please review again.` |
| Driver cancels | Affected passengers | `Your commute was canceled. Find another matching ride.` |
| Passenger late incident | Passenger (**[v2]** after drop-off) | `Late pickup recorded (1st warning). Tap to review or dispute.` |
| SOS alert failed | SOS user | `We couldn't confirm delivery to support. Retry or call emergency services.` |
| **[v2]** Report resolved | Reporter | `Your report was reviewed. See the outcome.` |

**[v2]** A basic Notifications list exists (bell). The full Notifications centre and settings are deferred (Open Items).

## 15. Screen inventory for Claude Design

1. Passenger Home (empty, confirmed, **[v2]** pickup in 15 min, started, arrived, on board, delayed, canceled, expired, exited, finished)
2. Discover search + recent searches + advanced filters (**[v2]** no price) + results + loading/empty/offline/error
3. Commute details + pickup/drop-off selector/map + request seat (**[v2]** no Save; women-only; full; new driver; overlaps your booking)
4. Private demand create/edit/list/paused (**[v2]** multiple demands, resume, delete)
5. Passenger Trips (**[v2]** Upcoming, Requests, Waiting lists, Demands, History; ~~On Hold~~)
6. Driver Home next commute (scheduled, active, expired transition, **[v2]** seat opened, passenger switched away)
7. Driver create commute wizard + **[v2]** stop roles/add/reorder/validation + per-weekday schedule/price slider/seats
8. Driver commute detail/actions + pause/resume/delete dialogs + **[v2]** edit form (typed time, pickup list, Save changes)
9. Combined change proposal editor + passenger approval comparison
10. Driver request inbox (**[v2]** seat + waiting-list requests) + counteroffer + active-ride request sheet
11. Waiting list manager (**[v2]** Free / In another commute badges, offered to all, first to accept) ~~+ seat-offer composer + 5-minute offer detail~~
12. Passenger availability toggle + ~~offer-expired state~~ **[v2]** seat taken / offer closed state
13. Driver live navigation + stops + I've Arrived + waiting timer (**[v2]** + SOS button)
14. Passenger live ride + I'm Ready + driver delayed/exit alternatives (**[v2]** car type, only commutes with seats)
15. No-show/dispute + late-boarding warnings (**[v2]** post-trip notification)
16. Drop-off/ride completion + trip summary + two-way reviews (**[v2]** comment after stars, Report/Block/Chat history)
17. SOS confirmation + active alert + trusted-contact sharing
18. Report/appeal + after-trip blocking + history
19. **[v2]** Switch flow: overlap detail, request to join waiting list, switch dialog (Only this date / Every weekday), Accept & Switch, done states, Trips after switch
20. **[v2]** Chat per booking / request / waiting list + read-only after trip
21. **[v2]** Unverified driver: Home, Requests, Commutes, Create review, Publish gate
22. **[v2]** Widget-state boards (passenger): Home card, Discover, Detail, Confirmations, Demands, Trips tabs, Booking/request, Proposals, Seat offers/waiting list, Live trip, Delay/alternatives, Trip complete/history/notifications, Chat, Safety, Dialogs

## 16. Required visual scenario pages (large, readable)

Produce **separate** scenario pages with 1–2 large mobile mockups and arrows/callouts:

- **Scenario A:** Passenger searches -> requests -> confirms -> Home next commute.
- **Scenario B:** Driver creates recurring Sunday/Tuesday/Thursday commute with one fixed route but different weekday times, prices and capacities.
- **Scenario C [v2]:** Driver pauses -> cancellation warning -> prior recurring passengers stay on the waiting list -> resume -> free seats offered automatically to available members -> first to accept gets each seat.
- **Scenario D:** Driver proposes time + pickup change -> one passenger accepts, another silent -> revision resets approvals -> apply only on unanimous approval.
- **Scenario E [v2]:** Full commute -> a seat opens -> offer sent to all available waiting-list members at once -> first accepts and is confirmed -> others see `Seat already taken`, membership remains.
- **Scenario F:** 07:58 arrived / 08:00 pickup / 08:05 grace / 08:08 boards late -> not no-show, lateness counter (**[v2]** warning notification after drop-off).
- **Scenario G:** Driver never starts -> reminders 08:01/08:05/08:10 -> expires 08:20 -> alternative commute; contrast started 08:12.
- **Scenario H:** Driver misses pickup -> passenger leaves without fee -> replacement confirmation atomically switches.
- **Scenario I:** Driver drops off one passenger -> that booking completes and ratings available -> other passengers continue.
- **Scenario J:** SOS confirmation -> silent support alert -> optional contacts, no automatic contact notification.
- **Scenario K [v2]:** Confirmed with Nour (Sun) -> opens overlapping Yasmin commute -> `Request to Join Waiting List` -> Yasmin accepts -> seat free → `Switch Commute` / full → offer → `Accept & Switch` -> scope `Only this date` vs `Every Sunday` -> auto-added to Nour's waiting list -> Nour sees `passenger switched away`, seat offered to Nour's list.

Each page: **user goal**, **before state**, **action**, **after state**, **visible copy**, **backend-driven conditions**, **edge case**. Keep body text readable at normal PDF zoom. Do not shrink 8 phones onto one landscape page.

## 17. Design boundaries / deferred

- Do not decide commission, refund, fee amount, cancellation cap or pricing formula in design; use neutral `A fee may apply` where approved.
- Do not invent extra consent or forced recording requirements; implementation must validate legal and OS limitations.
- Don't introduce `Extend Offer`, `Extend Pickup Grace`, automatic standby acceptance, ~~automatic resume notification,~~ automatic rebooking or unapproved change application. **[v2]** Also don't reintroduce `On Hold`, timed (5-minute) offers, a price filter, a Save button on commute detail, or a Messages tab.
- Use existing RAFEEQ visual language and previously approved Feature 01/02 navigation. If source screenshots are provided, adapt them rather than silently replacing established design.
- **[v2] Out of scope for this round:** Payments & Pricing, full Notifications centre/settings, Arabic/RTL screens, driver widget-state boards. See `RAFEEQ_Open_Items_and_Business_Cases.md`.
- **[v2] Prototype Demo control:** a floating `Demo` button (prototype-only, also shown in the standalone HTML export) triggers backend events such as seat opened, driver delayed, offline, location denied, payment failed, pickup in 15 min, SOS failed and report resolved. It is **not** part of the product UI.

**Deliverable requested from Claude Design:** responsive, accessible Flutter-ready screen specifications and a navigable prototype, including the ~~10~~ **[v2] 11** annotated scenario storyboards above, preserving the exact product behavior.
