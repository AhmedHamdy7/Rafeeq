# RAFEEQ Product Operating Manual

# Chapter 5 — Search & Matching

## Goal

After drivers publish commutes, passengers search for them. RAFEEQ never dispatches drivers like a ride-hailing app.

## Story

Sara opens RAFEEQ and wants to commute from Nasr City to Smart Village every weekday around 8:00 AM.

She taps **Find a Commute**.

The app asks:
- Origin
- Destination
- One-time or recurring
- Preferred time
- Days
- Seats needed
- Preferences

After pressing **Search**, the backend:

1. Loads published commutes.
2. Removes paused, archived and expired commutes.
3. Compares route similarity.
4. Compares pickup distance.
5. Compares departure time.
6. Checks available seats.
7. Applies preference filters.
8. Sorts by match score.

## Result Card

Shows:

- Driver
- Rating
- Vehicle
- Pickup point
- Departure
- Walking distance
- Price
- Seats
- Book button

## No Match

If nothing is found, RAFEEQ offers:

'Save this commute request?'

This creates a private commute demand.

Drivers never browse passenger demands.

When a future commute matches, RAFEEQ automatically sends a notification.

## Database

### commute_demands

- id
- passenger_user_id
- origin_lat
- origin_lng
- destination_lat
- destination_lng
- commute_type
- preferred_days
- preferred_time
- status
- created_at

Relationship:

users (1) -> commute_demands (many)

### saved_searches

- id
- user_id
- title
- origin
- destination
- filters

### match_notifications

- id
- demand_id
- commute_offer_id
- delivered
- clicked
- created_at

## APIs

GET /v1/search/commutes

POST /v1/commute-demands

GET /v1/saved-searches

POST /v1/saved-searches

GET /v1/matches

## Security

- Passenger demand is private.
- Exact home location hidden.
- Search requests rate limited.
- Driver cannot see unmatched passengers.

## Edge Cases

- Commute becomes full after search.
- Driver archives commute.
- Demand expires.
- Duplicate saved searches.

## Flutter

Cubits

- SearchCubit
- SearchFilterCubit
- DemandCubit
- SavedSearchCubit

## QA

- Search recurring
- Search one-time
- Save demand
- Receive future match notification
- Validate ranking

## Chapter Result

Passengers can discover published commutes or save a private demand for future matching.
