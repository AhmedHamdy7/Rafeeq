# RAFEEQ Product Operating Manual

# Chapter 11 — Notifications, Messaging & Communication

## Goal

Communication keeps every commute running smoothly.

RAFEEQ delivers the right information to the right user at the right time without overwhelming them.

---

# Story

Sara books Ahmed's morning commute.

Immediately she receives:

"Your booking has been confirmed."

Ahmed receives:

"Sara has booked one seat."

As the trip approaches, RAFEEQ automatically sends reminders.

---

# Notification Types

## Push Notifications

- Booking confirmed
- Booking cancelled
- Driver started trip
- Driver arrived
- Payment received
- Refund processed
- Rating reminder
- Safety updates

## In-App Notifications

Stored inside RAFEEQ so users can review them later.

---

# Trip Chat

Passengers and drivers may chat only for active bookings.

Allowed uses:

- Pickup clarification
- Arrival updates
- Minor delays

Not intended for long-term messaging.

---

# Messaging Rules

- Chat opens after booking confirmation.
- Chat closes after the trip plus a configurable grace period.
- Messages are encrypted in transit.
- Abuse can be reported.

---

# Notification Preferences

Users can control:

- Marketing notifications
- Trip reminders
- Booking updates
- Payment updates
- Safety alerts (cannot be fully disabled)

---

# Database

## notifications

- id
- user_id
- type
- title
- body
- is_read
- created_at

## conversations

- id
- booking_id
- created_at
- closed_at

## messages

- id
- conversation_id
- sender_user_id
- message
- created_at
- read_at

---

# APIs

POST /v1/messages

GET /v1/conversations/{id}

GET /v1/notifications

PATCH /v1/notifications/read

PATCH /v1/preferences/notifications

---

# Security

- Users only access conversations for their own bookings.
- Rate-limit messages.
- Filter abusive content.
- Audit deleted messages.

---

# Flutter

features/
  notifications/
  chat/

Cubits

- NotificationCubit
- ConversationCubit
- ChatCubit
- NotificationSettingsCubit

---

# QA

- Push delivered
- Notification stored
- Read status updated
- Chat opens after booking
- Chat closes after trip
- Blocked users cannot message
- Notification preferences respected

---

# Chapter Result

RAFEEQ now provides reliable communication through push notifications, in-app notifications and temporary trip-based messaging while protecting user privacy and preventing abuse.
