# RAFEEQ — Feature 01: Authentication — Backend Implementation Handoff v3

**Status:** Backend can begin now. **Scope:** phone OTP, session, account bootstrap, basic profile, role enrollment and onboarding recovery.

## Final approved behavior
1. RAFEEQ is gender-neutral. One account can have Passenger and Driver capabilities; mode is a UI context, not another login.
2. First-install optional three-slide onboarding → Phone → OTP → Basic Profile → initial role (Passenger / Driver / Both) → Home. Existing users restore session; no separate Register/Login, password, PIN, biometrics or account switching.
3. New user basic profile: **one free-form name** (min 2 chars, single name allowed), date of birth, gender. Do not request verification documents here.
4. Passenger → Passenger Home. Driver → first Driver walkthrough then Driver Home. Both → Passenger Home; Driver walkthrough only on first Driver activation.
5. **Latest user decision:** if the user exits before completing role selection, resume at **Basic Profile** (prefilled and editable), then Role; do not skip straight to Role. Do not request OTP again while the session is valid.
6. **Latest user decision:** no phone-number change or migration. A new number requires a new account; do not automatically move bookings, documents, ratings or liabilities. Old account data remains governed by retention policy.
7. Driver-only verification is in Feature 02; Passenger requests do not need verification.
8. Admin may restrict **Driver capability only** or **entire account** based on case. Account-level restriction shows Restricted Account + support; driver-only restriction preserves passenger access.
9. Valid refresh session silently restores; expired/revoked refresh → Phone/OTP. Logout revokes appropriate session; other-device behavior should be documented/configurable.
10. Egypt +20 default, normalize local leading 0 (010...) to E.164; OTP screen handles invalid, expired, resend, rate-limit and network states.

## Proposed tables and fields
- `users(id, phone_e164 UNIQUE, full_name, date_of_birth, gender, profile_completed_at, initial_role_selected_at, status, created_at, updated_at)`; use explicit nullable fields and safe uniqueness rules.
- `user_capabilities(user_id, capability PASSENGER|DRIVER, enrolled_at, status)`; Both = two rows; initial role preference separate from current mode.
- `user_preferences(user_id, last_active_mode, onboarding_seen_at, driver_walkthrough_seen_at, language, theme)`; UI may cache mode locally but backend retains cross-device preference if desired.
- `auth_sessions(id, user_id, refresh_token_hash, device_id, expires_at, revoked_at, created_at, last_used_at)`; rotate refresh token and detect reuse.
- `otp_challenges(id, phone_e164, purpose, code_hash/provider_id, expires_at, attempt_count, resend_available_at, consumed_at)`; never log raw OTP.
- `account_restrictions(id, user_id, scope ACCOUNT|DRIVER, status, reason_code, public_message, set_by_admin, created_at, lifted_at)`; Feature 02/admin consumes it.
- `security_audit(id, actor_id, event, target_id, metadata_redacted, created_at)`.

## Proposed APIs
| Method | Path | Behavior |
|---|---|---|
| POST | `/auth/otp/request` | Normalize/validate phone, create/send OTP, rate-limit; generic response to avoid enumeration |
| POST | `/auth/otp/verify` | Verify one-time code atomically; create/recover user and session; return access/refresh and `next_step` |
| POST | `/auth/refresh` | Rotate refresh, revoke reused tokens, return current restrictions |
| POST | `/auth/logout` | Revoke session, idempotent |
| GET | `/auth/me` | Identity, capabilities, profile completeness, restrictions, allowed actions, next_step |
| PUT | `/me/basic-profile` | Update name/DOB/gender during onboarding, preserve entered values |
| POST | `/me/initial-role` | Idempotently enroll Passenger, Driver, or Both; mark role completed |
| GET | `/me/onboarding-state` | Server-derived recovery routing |
| PATCH | `/me/preferences` | Update mode/walkthrough seen without granting capabilities |

## `next_step` decision tree
- No session: PHONE_OTP. Authenticated ACCOUNT restriction: RESTRICTED_ACCOUNT.
- Basic profile incomplete **or initial role missing**: BASIC_PROFILE (prefilled) → ROLE_SELECTION.
- Profile and role complete: HOME with capabilities, mode, and Driver walkthrough flag.
- Driver-only restriction: Home permitted in Passenger mode; Driver UI shows restriction and protected driver actions fail.
- Never use OTP success as driver-verification approval.

## Auth/security engineering
- Phone format, OTP TTL/resend limits/attempt caps from config; IP/device/phone throttling; brute-force prevention; server-side validation; SMS provider failure retry and idempotency.
- Access token short-lived, refresh rotated and securely stored; revoke on logout and restriction as appropriate. Server validates capability/restriction per protected request, not just client flags.
- Do not expose whether phone already registered in OTP request. OTP verification is atomic and single-use. Audit sign-in, failed attempts, restrictions and logout without logging secrets.
- Privacy/legal consent captured separately when required; no forced biometrics.

## Test scenarios / acceptance criteria
- New phone: OTP → editable profile → role → correct Home; Both starts Passenger.
- Returning valid session skips OTP; invalid refresh requests OTP; profile/role incomplete resumes **Basic Profile**, even if profile fields are already populated.
- Local 010... and +2010... resolve to same account; malformed numbers rejected.
- OTP expired/replayed/too many attempts/resend blocked/parallel verify handled deterministically.
- New phone number is **new user**; no silent account merge.
- Driver restriction doesn't block passenger usage; account restriction blocks both with support route.
- Repeat role submit does not create duplicate capability rows; switching mode doesn't request OTP.

## Dependencies / not blockers
SMS vendor and exact limits need configuration. Admin restriction UI will come later, but `scope`, `status`, and audit must be ready. Account deletion is a separate future flow.

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

# RAFEEQ — Feature 01: Authentication
## Claude Design Handoff — v2 (updated after design review)

**Goal:** Update the existing RAFEEQ authentication/onboarding designs to match the agreed MVP logic.

Preserve the existing RAFEEQ visual language where possible. Change the UX structure, screens and navigation that conflict with this document.

> **What changed in v2:** Verification is **driver-only** (passengers have no verification). `Both` starts in Passenger mode. A single free-form name field. The Home verification reminder appears in **Driver mode only** and disappears completely once the driver is verified. No women-only claims anywhere in onboarding: RAFEEQ is for everyone. English only for now (Arabic comes later). All changes are marked **[v2]**.

# 1. New Authentication Model

There is no separate:

```text
Login
Register
```

There is one flow:

```text
Phone Number
     ↓
OTP
     ↓
Backend identifies new/existing account
```

The user never chooses whether they already have an account.

# 2. UX Principle

For a normal returning user:

```text
Open RAFEEQ → Home
```

Do not repeatedly show Phone/OTP.

The app restores the saved session in the background.

OTP appears only when authentication must be established or re-established.

# 3. Welcome / Onboarding

First-install marketing onboarding may remain. **[v2]** It is **three slides** with progress dots, `Skip`, and `Next`.

The final CTA should be neutral:

```text
[ Get Started ]
```

Do NOT show:

```text
[ Login ]
[ Register ]
```

The next screen is always Phone Number.

**[v2] Copy rule:** RAFEEQ is for everyone who wants to share a commute. Do not describe the app as women-only or women-first. Women-only is a **trip-level option** that a woman driver may set on her own commute (see Feature 03).

# 4. Phone Number Screen

Suggested structure:

```text
Welcome to RAFEEQ

Enter your phone number to continue.

+20 | Phone number

[ Continue ]

We'll send you a verification code.
```

Requirements:

- Egypt can be the default country code.
- **[v2]** Accept a local number typed with a leading `0` (e.g. `010…`); strip it and format as `+20 10 xxxx xxxx`.
- validation-error state;
- loading state;
- network-error state;
- no Login/Register wording.

# 5. OTP Screen

```text
Verify your number

Enter the code we sent to
+20 10 **** 5678

[ _ ] [ _ ] [ _ ] [ _ ] [ _ ] [ _ ]

Resend code in 00:38

Change phone number
```

Design states:

- default;
- entering code;
- verifying;
- wrong code;
- expired code;
- resend available;
- resend rate-limited;
- network failure;
- **[v2]** success (short check animation before routing).

The same OTP screen is used for new and existing users.

# 6. Routing After OTP

```text
                  OTP VERIFIED
                       │
              ┌────────┴────────┐
              │                 │
             NEW             EXISTING
              │                 │
              ▼                 ▼
        Basic Profile      Restore account
              │                 │
              ▼                 ▼
        Initial Role       Home / required
              │             account state
              ▼
             Home
```

Do not add a Registration Successful screen unless there is a real UX need.

**[v2]** A returning user whose session expired, or who logged out, sees a short `Welcome back` restored state after OTP, then Home.

# 7. Basic Profile

Shown to a new user or an authenticated user whose profile was never completed.

Fields:

- **[v2] Full name:** a single free-form field. Accept one name (`Sara`, minimum 2 characters) or a full name (`Sara Ahmed`).
- Date of birth
- Gender

**[v2]** Keep DOB and gender, but make **no** women-only promise around them. They are account facts, not a product mode.

Do NOT place these here:

- Government ID upload
- Selfie verification
- Driver licence
- Vehicle setup

CTA:

```text
[ Continue ]
```

States: default, filled, validation errors, submitting, API error, **[v2]** resume (an incomplete profile reopened after relaunch).

# 8. Initial Role Screen

Title:

```text
How will you use RAFEEQ?
```

Use three clear selectable cards:

### Passenger
```text
Join existing commutes.
```

### Driver
```text
Share your regular commute.
```

### Both
```text
Join and share commutes.
```

CTA:

```text
[ Continue ]
```

Important:

This is an initial usage choice, NOT verification.

Do not imply that selecting Driver means the person is an approved driver.

**[v2] Routing after Role:**

| Choice | Lands on |
|---|---|
| Passenger | Passenger Home |
| Driver | First-time Driver walkthrough (Feature 02), then Driver Home |
| Both | **Passenger Home** (Passenger mode first). The Driver walkthrough appears the first time they switch to Driver. |

# 9. One Account, Multiple Capabilities

The design must not imply separate Driver and Passenger accounts.

Conceptually:

```text
ONE RAFEEQ ACCOUNT
├── Passenger capability
└── Driver capability
```

A Passenger can later choose **Become a Driver** from Profile and complete driver-specific verification.

A Driver can use Passenger functionality on the same account. **[v2]** Passengers need **no** verification. **Become a Passenger** is instant.

# 10. Remove Mandatory PIN from MVP

Existing designs may contain:

- Create PIN
- Enter PIN
- Forgot PIN
- Reset PIN
- Enable biometrics
- biometric login

Remove these from the mandatory MVP authentication journey.

Do not show a RAFEEQ PIN every time the application opens.

Do not make Face ID/fingerprint mandatory.

Old frames are kept on the canvas under:

```text
Future / Not MVP
```

(PIN log in, Forgot/reset PIN, Switch account.)

# 11. Returning User

Normal returning-user design:

```text
Splash / Launch
       ↓
      Home
```

Session restoration happens silently.

If restoration takes noticeable time, show RAFEEQ's branded loading/splash state—not an authentication form.

# 12. Session Can No Longer Be Restored

```text
Launch
  ↓
Session invalid
  ↓
Phone Number
  ↓
OTP
  ↓
Welcome back → Home
```

Do not show technical copy such as:

```text
Refresh token expired
```

Use human copy:

```text
Please verify your phone number to continue.
```

# 13. Incomplete Profile Recovery

A user can close RAFEEQ after OTP before finishing onboarding.

On return:

```text
Launch
→ restore authenticated account
→ profile incomplete
→ Basic Profile (resume, fields kept)
→ Initial Role
→ Home
```

Do not restart registration or ask for OTP again when the session is still valid.

# 14. Suspended Account

```text
Launch
→ restore session
→ account suspended
→ Restricted Account screen
```

Suspension is not logout.

Do not send an authenticated suspended user to Phone/OTP simply because the account is restricted.

The Restricted screen offers `Contact support`.

# 15. Verification Is After Onboarding — Driver Only [v2]

Do NOT make this mandatory:

```text
Phone → OTP → Profile → Verification → Home
```

Use:

```text
Phone → OTP → Profile → Role → Home
```

**[v2] Passengers have no verification.** Passenger Home has **no** verification card.

In **Driver mode only**, directly below the AppBar, show a dynamic **driver verification reminder** while work remains:

```text
┌─────────────────────────────────┐
│ 🛡 Complete driver verification │
│                                 │
│ Verify your ID, selfie, licence │
│ and vehicle to start publishing │
│ commutes.                       │
│                                 │
│ Level 0 of 5         Not started│
│ ▬▬▬▬▬                           │
│ Start verification →            │
└─────────────────────────────────┘
```

Card states (copy changes dynamically):

| State | Card |
|---|---|
| Not started | `Complete driver verification` · `Start verification` |
| In progress | `Level 3 of 5` · `Continue verification` |
| Under review | `Our team is reviewing your documents` · `View status` |
| Action required | Names the rejected document · `Replace document` |
| Verified | **Card hidden completely** |
| Licence expiring | Expiry date · `Renew now` (driving still allowed) |
| Renewal required | `Publishing and driving are paused` · `Upload renewed licence` |
| Suspended | High-visibility `Driver access suspended` · `See details` |

# 16. Contextual Verification [v2]

Home reminds. Protected **driver** actions enforce.

Passenger:

```text
Browse commute
→ Request Seat
→ request is sent directly (no verification step)
```

Driver:

```text
Driver Home
→ Create / Publish commute
→ driver approval missing
→ "Finish driver verification to publish" (draft is saved)
→ Driver verification checklist
```

An unverified driver may explore Driver Home, Requests and Commutes and may draft a commute. Only **Publish** and **Start Commute** are blocked.

# 17. Switch Account

Do NOT add Switch Account to MVP.

It is future scope.

Current UX should optimize for one active RAFEEQ account on the device.

# 18. Logout

Profile → Account includes:

```text
Log out
```

Confirmation:

```text
Log out of RAFEEQ?

You'll need to verify your phone number
when you sign in again.

[ Cancel ]   [ Log out ]
```

After logout:

```text
Phone Number
```

No PIN screen.

# 19. MVP Auth Screen Inventory

Required (all implemented in the canvas, rows `Feature 01 · …`):

```text
01. First-install onboarding/welcome (3 slides)
02. Phone Number (empty / valid / invalid / loading / network)
03. OTP Verification (all 9 states)
04. Basic Profile (default / filled / errors / submitting / API error / resume)
05. Initial Role — Passenger / Driver / Both (+ none, submitting)
06. Session restoration/loading + restored ("Welcome back")
07. Restricted/Suspended Account
08. Passenger Home (no verification card)
09. Driver Home with Driver Verification Reminder (Feature 02 rows)
10. Logout confirmation
```

Not required for MVP Auth:

```text
X Login/Register chooser
X Password
X Create PIN
X Enter PIN
X Forgot PIN
X Reset PIN
X Mandatory biometric setup
X Account switcher
X Mandatory verification before Home
X Passenger verification            [v2]
```

# 20. Main UX Flow Diagram

```text
                           ┌─────────────────┐
                           │   Open RAFEEQ   │
                           └────────┬────────┘
                                    │
                                    ▼
                         Saved session available?
                            /                \
                          NO                  YES
                          │                    │
                          ▼                    ▼
                 ┌────────────────┐     Restore session
                 │  Phone Number  │            │
                 └───────┬────────┘            ▼
                         │               Current account state
                         ▼                    │
                   Request OTP        ┌───────┼────────┐
                         │            │       │        │
                         ▼            ▼       ▼        ▼
                 ┌────────────────┐ Suspended Profile  Ready
                 │ OTP Verification│          Missing   │
                 └───────┬────────┘    │       │        │
                         │             ▼       ▼        ▼
                         ▼         Restricted Profile   Home
                    Successful      Screen
                         │
                    New account?
                     /       \
                   YES        NO
                   │           │
                   ▼           ▼
              Basic Profile   Welcome back → Home
                   │
                   ▼
          How will you use RAFEEQ?
          Passenger / Driver / Both
           │          │          │
           ▼          ▼          ▼
     Passenger   Driver walk-  Passenger
       Home      through →       Home
                Driver Home
                     │
                     ▼
          Driver verification reminder
          (until verified, then hidden)
```

# 21. Role vs Verification Diagram [v2]

```text
                    ONE RAFEEQ USER
                           │
               ┌───────────┴───────────┐
               │                       │
               ▼                       ▼
        Passenger Capability      Driver Capability
               │                       │
        No verification          Driver verification
        required                 (ID, selfie, licence,
               │                  registration, vehicle
               │                  + admin approval)
               ▼                       ▼
        Request seats,           Publish & start
        join waiting lists       commutes
```

Role selection says what the user wants to do.

Verification says what the driver is currently allowed to do.

Do not visually merge the concepts.

# 22. Copy Direction

Prefer:

```text
Get Started
Continue
Verify your number
How will you use RAFEEQ?
Complete driver verification
Become a Driver
Become a Passenger
Welcome back
Log out
```

Avoid:

```text
Register
Create Driver Account
Create Passenger Account
Authentication token expired
Complete all verification before entering
Enter your RAFEEQ PIN
Verify your account to request a seat   [v2]
Women-only app / for women             [v2]
```

# 23. States Not to Forget

### Phone
- empty
- valid
- invalid
- loading
- network error

### OTP
- countdown
- entering
- verifying
- wrong code
- expired
- resend available
- rate limited
- network error
- success

### Profile
- default
- filled
- validation errors
- submitting
- API error
- resume (incomplete profile)

### Role
- none selected
- Passenger selected
- Driver selected
- Both selected
- submitting

### Startup
- restoring session
- restored (Welcome back)
- invalid session
- suspended
- incomplete profile

### Driver verification card (Driver mode only)
- not started
- in progress
- under review
- action required
- licence expiring
- renewal required
- suspended
- verified (card hidden)

# 24. Required Changes to Existing Designs

1. Remove Login vs Register split.
2. Make Phone Number the single auth entry.
3. Use one OTP screen for new and existing users.
4. Route new users to Basic Profile.
5. Add/refine "How will you use RAFEEQ?".
6. Remove mandatory PIN from MVP.
7. Remove mandatory biometric setup.
8. Remove mandatory verification from onboarding.
9. Route completed onboarding directly to Home.
10. **[v2]** Show the verification reminder below the AppBar in **Driver Home only**.
11. Preserve Restricted Account state.
12. Keep Logout in Profile → Account.
13. Do not add Switch Account yet.
14. Never require a second account to become Driver/Passenger later.
15. **[v2]** Remove passenger verification completely.
16. **[v2]** Remove women-only claims from onboarding copy.

# 25. Definition of Done

### Story A — New Passenger
```text
Install → Welcome → Phone → OTP → Profile → Passenger → Passenger Home
```
No verification card. Can request seats immediately.

### Story B — New Driver
```text
Install → Welcome → Phone → OTP → Profile → Driver → Driver walkthrough → Driver Home
```
Driver is not automatically approved. Driver Home shows the verification reminder.

### Story B2 — New "Both" [v2]
```text
Install → … → Role: Both → Passenger Home
→ (later) switch to Driver → Driver walkthrough (once) → Driver Home
```

### Story C — Returning User
```text
Open → restore session → Home
```

No SMS. No RAFEEQ PIN.

### Story D — Invalid/Expired Session
```text
Open → session cannot be restored → Phone → OTP → Welcome back → Home
```

### Story E — Incomplete Signup
```text
Open → restore session → Profile (resume) → Role → Home
```

### Story F — Suspended User
```text
Open → restore session → Restricted Account
```

### Story G — Passenger Later Becomes Driver
```text
Profile → Become a Driver → Driver walkthrough → Driver Home → Driver verification → admin approval
```

Same account.

### Story H — Driver Verification Incomplete [v2]
```text
Driver Home → verification reminder card
```

The driver can explore and draft, while Publish and Start Commute enforce verification.

# 26. Prototype Notes [v2]

- **Language:** English only for now. Keep layouts RTL-ready; Arabic will be added later.
- **Prototype:** every screen above is reachable in the clickable prototype and in the standalone HTML export.

# Final Design Principle

> RAFEEQ remembers the user, gets them into the product quickly, and asks for additional trust/verification only when it is actually needed — and only drivers need it.

Do not add authentication/security screens simply because they are technically possible. Every screen must solve a real product or user problem.
