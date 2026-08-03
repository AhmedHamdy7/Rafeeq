# RAFEEQ Product Operating Manual
# Chapter 4 — Commute Management (Driver Creates a Commute)

## Goal

This chapter starts after Chapter 3. Ahmed is an approved driver with one active verified vehicle.

Everything in this chapter exists to answer one question:

> "How does a verified driver publish a commute that passengers can safely join?"

---

# Story

Ahmed opens RAFEEQ.

The passenger home is still available, but a new **Driver** tab now appears.

He taps **Create Commute**.

Before showing a form, RAFEEQ explains:

> A commute is a trip you already plan to make. RAFEEQ helps passengers join your journey. It is not intended for accepting random ride requests like ride‑hailing apps.

Ahmed presses **Continue**.

---

# Step 1 — Select Commute Type

Options:

- Recurring Commute (Recommended)
- One-Time Commute

Explain both with examples.

Recurring:

Sunday–Thursday

Home → Smart Village

07:30 AM

Ends 31 December

One-Time:

Airport

Interview

Conference

Hospital visit

Business rule:

Recurring creates future scheduled trips automatically.

One-Time creates exactly one scheduled trip.

Database:

## commute_offers

| Column | Purpose |
|---|---|
| id | PK |
| driver_profile_id | FK |
| commute_type | recurring / one_time |
| status | draft / published / paused / archived |
| created_at | Audit |

Relationship

driver_profiles (1) -> commute_offers (many)

---

# Step 2 — Choose Vehicle

Ahmed owns:

Toyota Corolla

Hyundai Elantra

RAFEEQ shows only approved vehicles.

If only one approved vehicle exists, preselect it.

Changing the active vehicle later does not modify historical completed trips.

Database:

commute_offers.vehicle_id -> vehicles.id

Rule:

Vehicle must be approved and active.

---

# Step 3 — Route

Ahmed chooses:

Origin

Destination

Optional pickup points

Optional drop-off points

Rules

Origin cannot equal destination.

Maximum pickup points configurable.

Each pickup must be on the route within tolerance.

Support map drag and search.

Future optimisation:

Polyline snapping and traffic estimation.

Database

## commute_locations

| Column | Purpose |
|---|---|
| id | PK |
| commute_offer_id | FK |
| type | origin/pickup/dropoff/destination |
| latitude | GPS |
| longitude | GPS |
| address | Human readable |
| sequence | Order |

Relationship

commute_offers (1) -> commute_locations (many)

---

# Step 4 — Schedule

If recurring:

Days

Departure time

Start date

End date

If one-time:

Date

Time

Rules

End date mandatory.

No infinite commutes.

Start date cannot be in the past.

Publishing creates scheduled trips.

Database

## commute_schedules

| Column | Purpose |
|---|---|
| id | PK |
| commute_offer_id | FK |
| recurrence_rule | Weekly pattern |
| departure_time | Daily time |
| start_date | Begins |
| end_date | Ends |

## scheduled_trips

Generated automatically.

Each occurrence references commute_offer_id.

Business Rule

Passengers always book scheduled trips, never recurrence rules directly.

---

# Step 5 — Seats

Ahmed selects:

Available seats = 3

Rules

Cannot exceed vehicle capacity.

Minimum one seat.

After bookings:

Remaining seats update automatically.

If seats decrease below booked seats:

Publishing changes blocked until conflict resolved.

---

# Step 6 — Price

Ahmed chooses:

Price per passenger.

Explain recommended pricing.

Future:

Dynamic pricing disabled.

Business Rules

No surge pricing.

Price changes affect only future scheduled trips unless explicitly migrated.

Database

price_amount

currency

---

# Step 7 — Preferences

Examples

Women only

No smoking

Small luggage only

Quiet commute

Music allowed

Pets allowed

These are preferences, not legal requirements.

Database

## commute_preferences

offer_id

key

value

---

# Review

RAFEEQ summarises:

Vehicle

Map

Time

Days

Seats

Price

Preferences

Ahmed presses Publish.

Validation runs.

If successful:

Status becomes Published.

Scheduled trips generated.

Notifications may be sent to matching passenger demand.

---

# State Machine

Draft

↓

Published

↓

Paused

↓

Published

↓

Archived

Archived commutes cannot be booked.

Paused commutes keep history.

---

# Database Summary

Created

commute_offers

commute_locations

commute_schedules

scheduled_trips

commute_preferences

Modified

vehicles (last_used_at optional)

Future dependencies

Bookings

Search

Notifications

Payments

Ratings

Safety

---

# APIs

POST /v1/commutes

PATCH /v1/commutes/{id}

POST /v1/commutes/{id}/publish

POST /v1/commutes/{id}/pause

DELETE /v1/commutes/{id}

GET /v1/commutes/{id}

---

# Edge Cases

Vehicle suspended after publishing.

→ Pause commute automatically.

Licence expires.

→ Prevent future scheduled trips.

Driver changes departure time.

→ Notify booked passengers.

Driver changes vehicle.

→ Allowed only if replacement vehicle approved.

Recurring end date reached.

→ Archive automatically.

---

# Flutter Structure

features/
  commute/
    create_commute/
    edit_commute/
    driver_commutes/

Cubits

CreateCommuteCubit

RoutePickerCubit

ScheduleCubit

SeatCubit

PublishCubit

---

# QA

✓ Create recurring commute

✓ Create one-time commute

✓ Invalid route

✓ Vehicle unavailable

✓ Seats exceed capacity

✓ Publish success

✓ Pause commute

✓ Archive commute

✓ Automatic scheduled trip generation

---

# Chapter Result

By the end of this chapter RAFEEQ owns its core asset:

A published commute.

Everything after this chapter—search, booking, matching, payments and notifications—operates on the published scheduled trips created here.
