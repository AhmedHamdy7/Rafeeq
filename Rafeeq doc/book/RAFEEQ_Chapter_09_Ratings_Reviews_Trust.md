# RAFEEQ Product Operating Manual

# Chapter 9 — Ratings, Reviews & Trust System

## Goal

A completed trip is not the end of the journey.

RAFEEQ must continuously build trust by allowing passengers and drivers to rate each other fairly while protecting the platform from abuse.

---

# Story

Ahmed completes today's commute.

Sara safely arrives at her destination.

A few minutes later both receive a notification:

> "How was your commute today?"

Neither user can see the other's rating until:

- Both users submit a review, or
- The review window expires.

This prevents revenge ratings.

---

# Passenger Rates Driver

Sara rates:

★★★★★

Questions:

- Was the driver on time?
- Was the vehicle clean?
- Did you feel safe?
- Would you travel again?

Optional comment.

---

# Driver Rates Passenger

Ahmed rates:

★★★★★

Questions:

- Passenger arrived on time?
- Respectful?
- Followed trip rules?
- Would accept again?

---

# Trust Rules

Ratings only after completed trips.

One review per booking.

Edited reviews allowed only within a short window.

Deleted reviews are hidden but retained for audit.

---

# Trust Score

Driver score considers:

- Average rating
- Number of completed trips
- Cancellation rate
- No-show rate
- Safety reports
- Verified profile

Passenger score considers:

- Average rating
- Attendance
- Cancellation history
- No-show history

Trust score is internal.

Only simplified reputation is shown publicly.

---

# Database

## ratings

- id
- booking_id
- reviewer_user_id
- reviewed_user_id
- stars
- comment
- created_at
- edited_at

Relationship

bookings (1) -> ratings (2 maximum)

---

## trust_scores

- id
- user_id
- score
- updated_at

Calculated periodically.

Never edited manually.

---

## review_reports

Allows users to report abusive reviews.

Fields

- rating_id
- reporter_id
- reason
- status

---

# APIs

POST /v1/ratings

GET /v1/users/{id}/ratings

POST /v1/reviews/report

GET /v1/trust-score

---

# Security

- No anonymous reviews.
- No review before trip completion.
- Detect repeated fake ratings.
- Detect rating rings.
- Moderation queue for abusive language.
- Reviews linked permanently to booking.

---

# Flutter

features/
  ratings/
  reviews/
  trust/

Cubits

- RatingCubit
- ReviewHistoryCubit
- TrustScoreCubit

---

# QA

- Passenger review
- Driver review
- Duplicate review blocked
- Hidden until both submit
- Abuse report
- Trust score recalculation
- Deleted review moderation

---

# Chapter Result

RAFEEQ now builds long-term trust by recording verified reviews from real completed commutes, calculating reputation fairly, and protecting both drivers and passengers from fraudulent or retaliatory ratings.
