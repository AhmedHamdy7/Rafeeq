# RAFEEQ Product Operating Manual

# Chapter 10 — Safety, Emergency & Incident Management

## Goal

Trust is RAFEEQ's biggest feature.

Safety is active before, during and after every commute.

This chapter defines how users report problems, request help and how the platform reacts.

---

# Story

Sara is travelling with Ahmed.

During the trip she notices an unsafe situation.

Instead of searching through menus she taps the visible **Safety** button.

RAFEEQ opens a dedicated safety screen with large actions.

---

# Safety Hub

Available actions:

- Share Live Trip
- Call Emergency Contact
- Contact RAFEEQ Support
- Report Driver
- Report Passenger
- Emergency SOS

The Safety button must always be reachable during an active trip.

---

# Share Live Trip

Sara selects a trusted contact.

RAFEEQ generates a temporary secure tracking link.

The contact can see:

- Driver first name
- Vehicle
- Current location
- Destination
- ETA

The link expires automatically after the trip ends.

---

# Emergency SOS

If SOS is pressed:

1. Confirm accidental tap.
2. Start emergency countdown.
3. Share trip details with emergency workflow.
4. Notify configured emergency contact.
5. Record audit event.

Future versions may integrate local emergency services where available.

---

# Incident Reports

Users may report:

- Dangerous driving
- Harassment
- Wrong vehicle
- Fake identity
- Violence
- Lost item
- Other

Each report includes:

- Category
- Description
- Optional photos
- Optional attachments

---

# Blocking

Passenger can block a driver.

Driver can block a passenger.

Existing completed history remains.

Future matching between blocked users is prevented.

---

# Database

## incidents

- id
- booking_id
- reporter_user_id
- reported_user_id
- category
- description
- status
- created_at
- resolved_at

## emergency_contacts

- id
- user_id
- name
- phone
- relationship

## blocked_users

- id
- blocker_user_id
- blocked_user_id
- created_at

## safety_events

Stores:
- SOS
- Live share
- Incident created
- Admin action

Never delete.

---

# APIs

POST /v1/incidents

POST /v1/sos

POST /v1/live-share

POST /v1/block-user

GET /v1/incidents/{id}

---

# Security

- Encrypt sensitive evidence.
- Time-limited live-share links.
- Audit every admin action.
- Prevent false incident spam.
- Rate-limit reports.
- Preserve evidence integrity.

---

# Flutter

features/
  safety/
    sos/
    incidents/
    live_share/

Cubits

- SafetyCubit
- IncidentCubit
- SOSCubit
- LiveShareCubit

---

# QA

✓ Create incident
✓ Upload evidence
✓ Trigger SOS
✓ Live share expires
✓ Block user
✓ Blocked users never match again

---

# Chapter Result

RAFEEQ now provides a complete safety layer that protects passengers and drivers before, during and after every commute while preserving evidence and preventing future unsafe matches.
