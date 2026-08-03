# RAFEEQ Product Operating Manual

# Chapter 8 — Trip Lifecycle & Live Trip

## Goal

This chapter begins after a booking has been confirmed and (if required) paid.

It defines everything that happens from the moment the driver starts preparing for the commute until the trip is completed.

---

# Story

It is Sunday at 7:00 AM.

Ahmed opens RAFEEQ before leaving home.

His Driver Dashboard shows today's scheduled trips.

One trip has:

- 3 booked passengers
- 1 available seat
- Departure: 7:30 AM

Ahmed taps **Start Today's Commute**.

RAFEEQ first validates:

- Driver account is still approved.
- Driver licence has not expired.
- Vehicle is still approved.
- Trip is not cancelled.
- GPS permission is available.

If validation succeeds, the trip enters **Preparing**.

---

# Trip States

Scheduled
↓
Preparing
↓
Driver En Route
↓
Passenger Check-in
↓
In Progress
↓
Completed

Alternative states:

Cancelled

Driver No-show

Passenger No-show

Emergency Interrupted

---

# Passenger Story

Sara receives:

"Ahmed has started today's commute."

She can see:

- Driver live location
- ETA to pickup
- Vehicle details
- Emergency button
- Driver contact options (masked if policy requires)

When Ahmed reaches the pickup point:

RAFEEQ asks Sara to confirm boarding.

Possible methods:

- QR code
- Trip PIN
- Driver confirmation
- GPS proximity

After confirmation:

Attendance becomes Present.

---

# Attendance

Possible values

- Present
- Late
- Passenger No-show
- Driver No-show
- Cancelled

Attendance affects ratings and future trust scoring.

---

# Live Location

During the commute:

Driver sends location updates every few seconds (configurable).

Passengers only receive information relevant to their trip.

Location history is retained according to the product's privacy policy.

---

# Database

## trip_sessions

- id
- scheduled_trip_id
- started_at
- completed_at
- current_status
- distance_travelled
- duration_seconds

Relationship

scheduled_trips (1) -> trip_sessions (1)

---

## attendance

- id
- booking_id
- status
- checked_in_at
- checked_out_at

---

## trip_locations

- id
- trip_session_id
- latitude
- longitude
- timestamp

Retention policy should archive or delete old location data according to legal requirements.

---

# APIs

POST /v1/trips/{id}/start

POST /v1/trips/{id}/check-in

POST /v1/trips/{id}/complete

POST /v1/trips/{id}/location

GET /v1/trips/{id}

---

# Completion

When Ahmed reaches the destination:

Driver taps **Complete Trip**.

RAFEEQ:

- Marks attendance final.
- Completes trip session.
- Updates booking status to completed.
- Releases driver earnings from pending to available wallet.
- Creates rating requests for both sides.

---

# Safety

Emergency button available during trip.

Possible actions:

- Share live trip
- Call emergency contact
- Report incident
- Contact support

Every action is logged.

---

# Flutter

features/
  trip/
    live_trip/
    attendance/
    tracking/

Cubits

- LiveTripCubit
- DriverTripCubit
- PassengerTripCubit
- AttendanceCubit

---

# QA

- Driver starts trip
- Passenger check-in
- GPS updates
- Passenger no-show
- Driver no-show
- Trip completion
- Earnings released
- Rating request generated

---

# Chapter Result

After this chapter RAFEEQ can execute a complete real-world commute, track attendance, share live progress, settle earnings, and transition naturally into ratings, safety, and support.
