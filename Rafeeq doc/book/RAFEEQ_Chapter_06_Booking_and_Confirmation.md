# RAFEEQ Product Operating Manual

# Chapter 6 — Booking & Passenger Confirmation

## Goal
Allow passengers to reserve seats on published scheduled trips safely.

## Story
Sara finds Ahmed's recurring commute.
She reviews the trip details and taps **Book Seat**.
RAFEEQ validates:
- Passenger account active
- Driver approved
- Trip published
- Seats available
- Passenger has not already booked
- Booking deadline not passed

If valid, the booking is created, seats decrease and both users receive notifications.

## Booking Status
pending -> confirmed -> completed

Alternative states:
- cancelled_by_passenger
- cancelled_by_driver
- expired

## Database

### bookings
- id
- scheduled_trip_id
- passenger_user_id
- driver_profile_id
- seats_reserved
- booking_status
- price_snapshot
- created_at
- updated_at

Relations:
scheduled_trips (1) -> bookings (many)
users (1) -> bookings (many)

### booking_events
- id
- booking_id
- event_type
- actor
- created_at

Stores every status transition.

## APIs
POST /v1/bookings
GET /v1/bookings/{id}
PATCH /v1/bookings/{id}/cancel
GET /v1/my-bookings
GET /v1/driver/bookings

## Cancellation
Passenger:
- Seat released
- Driver notified

Driver:
- All passengers notified
- Bookings cancelled_by_driver
- Suggest similar commutes

## Security
- Booking belongs to one scheduled trip.
- Passengers cannot access others' bookings.
- Drivers see only their own bookings.
- Price snapshot never changes.

## Flutter
Cubits:
- BookingCubit
- BookingDetailsCubit
- MyBookingsCubit
- CancelBookingCubit

## QA
- Booking success
- Duplicate booking blocked
- Full trip blocked
- Passenger cancellation
- Driver cancellation
- Notifications
- Seat updates

## Result
Passengers can reserve seats safely and bookings become the foundation for payments, attendance and ratings.
