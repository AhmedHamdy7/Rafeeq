# Verification and Driver reliability: backend answers

**Date:** 2026-11-14 · **Answers:** the mobile app's `backend-gaps.md` of 2026-11-14 ·
**Details:** `RAFEEQ_MOBILE_API_GUIDE.md` (sections 4.3, 4.4, reliability, 4.13).

Status: ✅ built · 📄 already worked, now documented · 🎨 design must change (product rule) · ⏳ interim.

## Verification

| # | Status | Answer |
|---|---|---|
| V-1 | 📄 | `government_id`: `national_id_front`, `national_id_back`. `selfie`: `selfie`. `driving_licence`: `licence_front`, `licence_back`. Other pairs: `422 DOCUMENT_KIND_NOT_ACCEPTED`. |
| V-2 | 📄 | One selfie image is the whole check (PROF-14). No liveness call. Drop "blink". |
| V-3 | ✅ ⏳ | `POST /account/consents` with `type: "biometric"`. No `version` is sent. `GET /account/consents` answers `biometric: { version, acceptedAt }`. Interim name: the rules name the checkbox but no consent type. |
| V-4 | 🎨 | Both licence sides and `nationalId`, `licenceNumber`, `licenceExpiry` are required (PROF-13, PROF-30). The design needs a second tile and three fields. |
| V-5 | 📄 | `expiresAt` is optional. Do not send it; the reviewer sets the registration expiry (PROF-30). |
| V-6 | 🎨 | `make` and `model` are both required. Split the text, or give the design two fields. |
| V-7 | 🎨 | No field. Seat belts are not a toggle (PROF-25); the reviewer checks them and sets `approvedSeats`. Keep the switch local or remove it. |
| V-8 | ✅ | Send `hasAc` on `POST` / `PATCH /driver/vehicles`. It comes back as `declaredAc`; `hasAc` in the response stays the reviewer's value. |
| V-9 | 🎨 | `seats` is required (2–8, total with the driver). `fuelType` and `transmission` are optional. The design needs a seats field. |
| V-10 | 📄 | Show `approvedSeats` (PROF-28). While it is `null` the car is not reviewed; the rules do not say what to show, so hide the seat count until it is set. |
| V-11 | ✅ | A deactivated car keeps `status: APPROVED` and can be activated with no review. New `approvalValidUntil` on the vehicle (earliest registration expiry, often `null` until the reviewer sets one). When it lapses the car becomes `SUSPENDED`, `suspensionReason: registration_expired`. |
| V-12 | ✅ | `GET /driver/vehicles/replace-preview?vehicle={id}` answers `affectedBookings`, `affectedCommutes`, `affectedSeats`, `feePiastres`, `reason`, `perTrip[]`. Use it before `activate`, for the new car and for switching back. |
| V-13 | 🎨 | The server's flow is the product's (PROF-21, COM-25): the old car keeps working until the new one is approved, and bookings are cancelled on `activate`. Nothing is paused during review; there is no `vehicle_under_review`. The design must show "new car under review, current car still active" and move the warning to activate. |
| V-14 | ✅ | `submittedAt` on the vehicle. |
| V-15 | 🎨 | No backend change. `status` can also be `SUSPENDED`. |

Also fixed: an approved driver could not `PATCH` a pending replacement car or upload its documents
(`409 VEHICLE_LOCKED`). Both now work on a car that is not active and is `PENDING` or `REJECTED`.

## Driver reliability

| # | Status | Answer |
|---|---|---|
| R-1 | 📄 | Every event carries `id`. |
| R-2 | ✅ | One appeal covers both. Approving the event appeal reverses the event and credits the fee; overturning a fee appeal reverses the event. Each event has `fee: { debtEntryId, amountPiastres, reversed }` or `null`. The member never sees one approved without the other. |
| R-3 | ✅ | Each event has `appeal: null | { id, round, status: pending|approved|declined, appealedAt, decidedAt, declineReasonCode }`, plus `canAppeal` and `appealRefusal`. Codes: `not_enough_evidence`, `evidence_does_not_match`, `within_member_control`, `other`. `reversedReason` is now always `null`; the reviewer's note was leaking through it. |
| R-4 | ✅ | `cause` (`cancelled_booking`, `replacement`, `cancelled_day`, `pause`, `commute_deleted`, `vehicle_replaced`, `active_trip_cancelled`) and `person: { userId, publicFirstName }`. Both are `null` on events recorded before today. |
| R-5 | ✅ | `groupId`, shared by the events of one pause, vehicle replacement, cancelled day or stopped trip. `null` on older events. |
| R-6 | ✅ | `kind` on each item, `?kind=` filter, and `meta.byKind.{completed_ride,canceled_booking}.{count,average}`. |
| R-7 | 🎨 | Month only, for both kinds. Show "Oct 2026 · anonymous". |
| R-8 | 🎨 | Stars only (SAFE-23, D-0038). No comment and no "Report comment" on canceled-booking feedback; reporting one answers `422 NOT_REPORTABLE`. |
| R-9 | ✅ | `reason` is optional on `POST /ratings/{rating}/report`. `reportedByMe` on each item of `GET /ratings/about-me`. |
| R-10 | ✅ | D-0021 applies: first appeal within 48 hours of the event, one second appeal within 48 hours of the decision with new evidence, then final. On the event: `appealsLeft`, `needsNewEvidence`, `appealBy`. These sit on the event, not inside `appeal`, because `appeal` is `null` before the first filing. |
| R-11 | ✅ | `cancelReason` (`breakdown`, `accident`, `emergency`, `other`) on events from `cancel-active`. `null` on older events. |
| R-12 | ✅ | `POST /account/reliability/events/{event}/evidence` (multipart: `kind` = `photo` · `screenshot` · `document`, `file`). jpeg, png or webp, **6 MB** (not 8), 5 per event. The list comes back in `events[].evidence`; there is no separate `GET`. Images only: audio is not accepted yet, here or on incident evidence. |
| R-13 | 🎨 | No backend change. |

## Changed behaviour to watch for

- The first reliability appeal now has a 48-hour window (`APPEAL_WINDOW_CLOSED`); it had none.
- Appeal `reason` accepts up to 1000 characters (was 255).
- `events[].reversedReason` is always `null`.
- Reporting canceled-booking feedback is refused.
