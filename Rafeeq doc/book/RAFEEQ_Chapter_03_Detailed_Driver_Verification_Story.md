# RAFEEQ Product Operating Manual
# Chapter 3 — Driver Verification & Vehicle Management
**Implementation Story**

## 1. Goal

At the end of this chapter the user can:

- Apply to become a driver.
- Submit identity documents.
- Submit one or more vehicles.
- Wait for review.
- Receive approval or rejection.
- Manage vehicles.
- Reach the Driver Dashboard.

This chapter depends entirely on Chapter 2. Every driver **must already be an authenticated user**.

---

# 2. Story

Ahmed has been using RAFEEQ as a passenger for several weeks.

While browsing the menu he notices a button:

> Become a Driver

When pressed, RAFEEQ first checks:

- User account is active.
- Phone is verified.
- Minimum profile is complete.
- User is old enough.
- User is not already an approved driver.
- User has no pending application.

If any condition fails, explain exactly why and offer the required next step.

If all checks pass, Ahmed enters the Driver Journey.

---

# 3. Driver Journey

```text
Passenger
   |
Become Driver
   |
Requirements
   |
Identity Documents
   |
Driving Licence
   |
Vehicle Information
   |
Vehicle Documents
   |
Review
   |
Pending Approval
   |
Approved
   |
Driver Dashboard
```

---

# 4. Screen 1 — Become a Driver

Purpose:
Explain benefits and requirements.

Headline

> Start sharing your commute.

Body

Explain that drivers share trips they already make.

Do NOT promise earnings similar to ride-hailing.

Primary Button

Become a Driver

Secondary

Maybe Later

Requirements section

✓ Verified phone

✓ Valid driving licence

✓ Vehicle registration

✓ National ID

✓ Clear profile photo

Estimated review

24–48 hours

---

# 5. Screen 2 — Identity Verification

Collect:

- National ID front
- National ID back
- Selfie

Rules

Images must be:

- Clear
- Original
- Colour
- Not expired
- Not cropped

Show examples of acceptable and rejected photos.

If OCR fails:

Allow retake before upload.

Never reject automatically without explanation.

---

# 6. Screen 3 — Driving Licence

Collect

Licence front

Licence back

Licence number

Expiry date

Checks

- Expiry > today
- Number not duplicated
- OCR matches entered number
- Name matches Chapter 2 profile

---

# 7. Screen 4 — Vehicle Information

Fields

Manufacturer

Model

Year

Colour

Plate Number

Seats

Transmission

Fuel Type

Vehicle Photo

Rules

Plate number unique.

Seats between 2 and 8.

Vehicle year configurable.

---

# 8. Screen 5 — Vehicle Documents

Upload

Registration

Insurance (future)

Inspection certificate (future)

Registration expiry

Reject blurry or incomplete images.

---

# 9. Review Screen

Summarise everything.

Allow editing before submission.

After submission:

Disable editing until review unless application is withdrawn.

---

# 10. Admin Review Story

Reviewer opens application.

Checks:

Identity

↓

Licence

↓

Vehicle

↓

Fraud indicators

↓

Approve or Reject

If rejected:

Reason is mandatory.

User receives notification with guidance.

---

# 11. Driver States

Draft

↓

Documents Uploaded

↓

Pending Review

↓

Approved

↓

Suspended

↓

Rejected

↓

Expired Documents

Transitions must be logged.

---

# 12. Database Design

## users

Existing table from Chapter 2.

No new columns required except optional:

driver_capability_enabled BOOLEAN

---

## driver_profiles

| Column | Purpose |
|---------|---------|
| id | PK |
| user_id | FK users.id |
| status | pending/approved/rejected/suspended |
| national_id | encrypted |
| licence_number | encrypted |
| licence_expiry | validation |
| verified_at | approval timestamp |
| reviewer_id | admin |
| rejection_reason | nullable |
| created_at | audit |
| updated_at | audit |

Relationship

users (1) ---- (0..1) driver_profiles

---

## vehicles

| Column | Purpose |
|---------|---------|
| id | PK |
| driver_profile_id | FK |
| make | Manufacturer |
| model | Model |
| year | Year |
| colour | Colour |
| plate_number | Unique |
| seats | Capacity |
| active | Only one true |
| verification_status | pending/approved |
| created_at | Audit |

Relationship

driver_profiles (1)

↓

vehicles (many)

---

## vehicle_documents

Stores

Registration

Insurance

Inspection

Image URL

Expiry

Verification Status

---

## verification_logs

Every admin action.

Who approved.

When.

Old value.

New value.

Reason.

Never delete.

---

# 13. Database Effects

Created

driver_profiles

vehicles

vehicle_documents

verification_logs

Uses

users

devices

sessions

Future Chapters

Commutes

Bookings

Payments

Ratings

Safety

Notifications

---

# 14. Security

Encrypt licence and ID numbers.

Never expose raw document URLs publicly.

Virus scan uploads.

Reject executable files.

Rate-limit applications.

Detect duplicate national IDs.

Detect duplicate licence numbers.

One pending application only.

Audit every review decision.

---

# 15. Flutter Structure

features/
 driver/
   presentation/
   cubit/
   data/
   domain/
   widgets/

Cubits

DriverApplicationCubit

VehicleCubit

DriverStatusCubit

Repositories

DriverRepository

VehicleRepository

---

# 16. QA Stories

### Story 1

Ahmed submits everything correctly.

Expected

Approved.

Driver dashboard appears.

---

### Story 2

Licence expired.

Expected

Submission blocked.

---

### Story 3

Vehicle registration blurry.

Expected

Admin rejects with reason.

User can resubmit only changed document.

---

### Story 4

Duplicate licence.

Expected

Fraud review.

No automatic approval.

---

### Story 5

User adds second vehicle.

Expected

Vehicle stored.

Only one active.

Commutes continue using selected active vehicle.

---

# 17. Definition of Done

✓ Driver application complete

✓ OCR integrated

✓ Documents uploaded

✓ Admin review

✓ Notifications

✓ Database migrated

✓ APIs tested

✓ Vehicle management complete

✓ Ready for Chapter 4

---

# Chapter Summary

After this chapter the user is no longer just a passenger.

The account remains the same account created in Chapter 2.

A verified driver profile is attached to the same user.

Vehicles become available for creating Commutes in Chapter 4.
