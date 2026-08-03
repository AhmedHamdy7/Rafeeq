# RAFEEQ Product Operating Manual  
## Chapter 2 — Onboarding, Authentication, PIN Access, Sessions, and Basic Identity

**Document type:** Implementation story and acceptance guide  
**Primary reader:** Product owner, Flutter developer, backend developer, UI/UX designer, QA engineer  
**Depends on:** Chapter 1 — Product Fundamentals  
**Creates the foundation for:** Chapter 3 — Driver Verification and Vehicle Management  
**Version:** 1.0  
**Status:** Ready for design and implementation review  

---

# 1. What this chapter builds

At the end of this chapter, RAFEEQ must allow a new person to:

1. Open the application for the first time.
2. Understand what RAFEEQ does and what makes it different from ride-hailing.
3. Accept the Terms of Service and Privacy Policy.
4. Register or sign in using a verified mobile number.
5. Create a local six-digit RAFEEQ PIN for fast future access.
6. Optionally enable biometric access.
7. Remain signed in securely on the trusted device.
8. Recover access when the PIN is forgotten.
9. Complete a minimal personal profile.
10. Reach the passenger home experience.
11. Later activate driver capability without creating a second account.

This chapter does **not** verify the user as a driver. It only creates a trusted person-level account. Driver verification starts in Chapter 3.

---

# 2. Core product decision

RAFEEQ has one account per person.

A person is not asked to choose permanently between “Passenger” and “Driver” during registration.

After registration:

- Passenger capability is available by default.
- Driver capability is locked.
- The user may request driver activation later.
- Chapter 3 creates a `driver_profile` connected to the same `user`.
- The phone number, name, sessions, settings, and account history remain shared.

This prevents:

- Duplicate passenger and driver accounts.
- Confusing account switching.
- Two different ratings identities.
- Repeated phone verification.
- Broken booking and payment history.
- Fraud using separate passenger and driver accounts.

---

# 3. The complete user story

## 3.1 First launch story

Mariam downloads RAFEEQ and opens it for the first time.

The app displays the splash screen while it checks:

- Is this the first app launch?
- Is onboarding already completed?
- Is there a valid refresh session?
- Is this device trusted?
- Did the user create a RAFEEQ PIN?
- Is biometric access enabled?
- Is the user account active, suspended, or pending deletion?
- Is a mandatory app update required?
- Is the backend reachable?

Because Mariam is new, no session exists. RAFEEQ opens onboarding.

Mariam moves through three onboarding pages. She learns that RAFEEQ is not an instant taxi service. Drivers share commutes they already make, and passengers can join suitable recurring or one-time routes.

After onboarding, she presses **Start with RAFEEQ**.

She sees the authentication entry page. She enters her Egyptian mobile number. RAFEEQ validates the format before enabling the button.

She presses **Continue**.

The backend creates a short-lived OTP challenge and sends a six-digit SMS code. The OTP page opens. Android may suggest or automatically fill the code. Mariam may also paste it manually.

After successful verification:

- If the phone number is new, RAFEEQ creates a user.
- If the phone number already exists, RAFEEQ signs in to the existing user.
- RAFEEQ registers the current device.
- RAFEEQ creates a session.
- RAFEEQ asks Mariam to create a six-digit PIN.
- RAFEEQ asks whether she wants fingerprint or face access.
- RAFEEQ asks for her minimum profile information.

Mariam reaches the passenger home screen.

The next time Mariam opens the app, she is not asked for her phone number or SMS code. She is still signed in. RAFEEQ displays the PIN screen only when the app requires a local unlock.

If Mariam forgets the PIN, she presses **Forgot PIN?** and verifies the same phone number again using OTP. After successful verification, she creates a new PIN.

---

# 4. Launch and routing logic

The splash screen is not a decorative delay. It is the application’s launch router.

## 4.1 Required launch checks

Perform checks in this order:

```text
Application starts
        |
        v
Check minimum supported app version
        |
        +---- Update required? ---- Yes ---> Mandatory Update Screen
        |
        No
        |
        v
Read secure local session metadata
        |
        +---- No session? -----------------> Onboarding or Auth Entry
        |
        v
Refresh token if required
        |
        +---- Refresh invalid? ------------> Auth Entry
        |
        v
Fetch lightweight account status
        |
        +---- Suspended? ------------------> Suspended Account Screen
        |
        +---- Pending deletion? -----------> Deletion Recovery Screen
        |
        +---- Active? ---------------------> Continue
        |
        v
Does the app require local unlock?
        |
        +---- No PIN configured? ----------> PIN Setup
        |
        +---- Biometric enabled? ----------> Biometric prompt
        |
        +---- Otherwise -------------------> PIN Unlock
        |
        v
Open correct home destination
```

## 4.2 Do not perform heavy work on splash

Do not load:

- Full profile data.
- Commute search results.
- Notifications list.
- Driver documents.
- Vehicle data.
- Payment history.

Only load enough information to route the user safely. Remaining data loads after the destination screen opens.

## 4.3 Splash timeout

If startup network work does not complete within a reasonable time:

- Do not keep the user on an infinite spinner.
- If a locally valid session exists, allow limited offline entry where safe.
- Show a non-blocking connectivity message.
- Retry account-status synchronisation when connectivity returns.
- Never allow sensitive server actions until the session is confirmed.

---

# 5. Onboarding experience

## 5.1 Onboarding goals

The onboarding must answer four questions:

1. What is RAFEEQ?
2. How is it different from Uber, DiDi, or inDrive?
3. Why should the user trust it?
4. What should the user do next?

It should not explain every feature. The user should finish onboarding in less than one minute.

---

## 5.2 Onboarding Page 1 — Share the commute

### Visual direction

Show:

- A driver already travelling to work.
- A passenger joining the same direction.
- A calm, planned commuting environment.
- No taxi meter.
- No “request a car now” visual language.

### Headline

> **Your daily commute can help someone else.**

### Body copy

> RAFEEQ connects people travelling in the same direction. Drivers share commutes they already make, and passengers request a suitable seat.

### Primary action

> **Continue**

### Secondary action

> **Skip**

### Product message

The first page establishes that RAFEEQ is a planned commute-sharing product, not an on-demand taxi service.

---

## 5.3 Onboarding Page 2 — Planned, not random

### Visual direction

Show:

- A weekly calendar.
- A route from home to workplace or university.
- Selected days such as Sunday to Thursday.
- A departure time.

### Headline

> **Plan once. Travel together.**

### Body copy

> Create or join one-time and recurring commutes with clear pickup points, schedules, seat availability, and trip expectations.

### Primary action

> **Continue**

### Secondary action

> **Skip**

### Product message

Users must understand that recurring routes are a first-class feature.

---

## 5.4 Onboarding Page 3 — Trust is part of the journey

### Visual direction

Show:

- Verified account.
- Verified driver and vehicle.
- Trip PIN.
- Safety and reporting symbols.
- Avoid fear-based imagery.

### Headline

> **Built around trust and safer commuting.**

### Body copy

> Phone verification, driver and vehicle review, trip confirmation, ratings, reporting, and privacy controls help people travel with more confidence.

### Primary action

> **Start with RAFEEQ**

### Supporting text

> By continuing, you agree to RAFEEQ’s Terms of Service and acknowledge the Privacy Policy.

The words **Terms of Service** and **Privacy Policy** must be tappable.

---

## 5.5 Onboarding behaviour

- Show onboarding only on first launch unless the user opens it again from Help.
- Store `onboarding_completed = true` locally.
- Do not require authentication to view Terms or Privacy.
- Support RTL Arabic and LTR English.
- Preserve page position after temporary app backgrounding.
- The final button must navigate to Auth Entry.
- “Skip” should go to Auth Entry, not directly to phone registration.
- Analytics should distinguish completed onboarding from skipped onboarding.

---

# 6. Authentication entry screen

This screen answers: “How do you want to access RAFEEQ?”

For a first-time or signed-out user, the recommended design is simple.

## 6.1 Screen title

> **Welcome to RAFEEQ**

## 6.2 Supporting text

> Sign in or create your account using your mobile number.

## 6.3 Primary option

> **Continue with mobile number**

## 6.4 Optional future options

Do not launch many authentication methods unless they solve a real problem.

Possible future methods:

- Continue with Apple.
- Continue with Google.
- Email recovery.

However, phone verification should remain required because the phone number is important for:

- Account recovery.
- Safety communication.
- Trip coordination.
- Fraud reduction.
- Driver verification.
- Emergency and support workflows.

Social sign-in must never create an account that has no verified phone number.

## 6.5 Existing trusted user behaviour

When a valid session already exists, do not show this screen. Show PIN or biometric unlock instead.

The user is “signed in” at the server level and “locally locked” at the device level. These are different states.

---

# 7. Phone number screen

## 7.1 Purpose

Collect and verify the account’s primary mobile number.

## 7.2 Screen copy

### Title

> **Enter your mobile number**

### Body

> We’ll send you a verification code to confirm that this number belongs to you.

### Country selector

Default:

> Egypt (+20)

### Input placeholder

> 10X XXX XXXX

### Primary button

> **Send verification code**

### Supporting security text

> RAFEEQ will never ask you to share your verification code with another person.

### Terms text

> By continuing, you agree to RAFEEQ’s Terms of Service and Privacy Policy.

---

## 7.3 Egyptian phone normalisation

Accept user-friendly variants such as:

- `01012345678`
- `+201012345678`
- `201012345678`

Store the canonical format:

```text
+201012345678
```

Do not store multiple formatting versions as separate identities.

## 7.4 Validation rules

At minimum:

- Required.
- Numeric after removing spaces and separators.
- Valid country calling code.
- Valid national length.
- Valid supported mobile prefix.
- Not a premium-rate or unsupported number.
- Not obviously malformed.
- Normalise before duplicate lookup.
- Do not reveal whether a number already has an account before OTP verification.

## 7.5 Privacy rule

Before OTP success, use neutral language.

Bad message:

> This phone number already belongs to Ahmed.

Good message:

> If an account exists for this number, you’ll be signed in after verification.

This prevents account enumeration.

---

# 8. OTP request flow

When the user presses **Send verification code**:

1. Client validates format.
2. Client sends canonical phone number and challenge metadata.
3. Backend checks rate limits.
4. Backend creates an OTP challenge.
5. Backend hashes the code.
6. Backend stores challenge expiry and attempt counters.
7. SMS provider sends the code.
8. API returns a challenge identifier, not the OTP.
9. App opens OTP verification.

## 8.1 Suggested OTP message

> Your RAFEEQ verification code is 482931. It expires in 2 minutes. Do not share this code with anyone.

Do not include a clickable link unless implementing secure app-bound verification.

## 8.2 Recommended controls

- OTP lifetime: short, such as two to five minutes.
- Resend cooldown: for example 30–60 seconds.
- Maximum verification attempts per challenge.
- Maximum resend requests per number and device.
- IP, device, and phone-number rate limiting.
- Old OTP becomes invalid after a new OTP is issued.
- OTP must be single-use.
- OTP must be bound to the intended phone and challenge.

Exact production values should be configurable remotely rather than hard-coded in Flutter.

---

# 9. OTP verification screen

## 9.1 Screen copy

### Title

> **Enter the verification code**

### Body

> We sent a six-digit code to **+20 10 *** **5678**.

### Code input

Six numeric digits.

### Primary button

> **Verify and continue**

### Resend disabled text

> Resend code in 00:42

### Resend enabled text

> **Resend code**

### Edit action

> **Change mobile number**

### Warning

> Never share this code with a driver, passenger, or support agent.

---

## 9.2 Interaction rules

- Open numeric keyboard.
- Allow complete-code paste.
- Support platform OTP autofill.
- Move focus automatically.
- Backspace moves to the previous empty field.
- Verification may begin automatically after six digits, but the UI must clearly indicate loading.
- Disable duplicate verify requests while one is running.
- Keep the phone number masked.
- Do not log the OTP.
- Clear OTP from memory after success or challenge expiry.

## 9.3 Error messages

### Incorrect code

> **That code is not correct. Check it and try again.**

### Expired code

> **This code has expired. Request a new code to continue.**

### Too many attempts

> **Too many incorrect attempts. Please wait before trying again.**

### Network failure

> **We couldn’t verify the code. Check your connection and try again.**

### Service failure

> **Verification is temporarily unavailable. Please try again shortly.**

### New code requested

> **A new code was sent. The previous code will no longer work.**

---

# 10. Registration versus sign-in decision

The user must not choose “Register” or “Login” before entering the number.

The system decides after phone verification.

```text
Verified phone number
        |
        v
Does canonical phone exist?
        |
        +---- No ----> Create new user ----> PIN Setup ----> Profile Setup
        |
        +---- Yes ---> Check account status
                            |
                            +---- Active ------> Create session
                            |
                            +---- Suspended ---> Suspended screen
                            |
                            +---- Deleted -----> Recovery/support policy
                            |
                            +---- Pending deletion -> Restore account option
```

## Why this approach is better

It removes unnecessary decisions:

- The user does not need to remember whether an account exists.
- The app does not expose account existence.
- Login and registration share the same secure phone-verification flow.
- Phone-number changes and account recovery become easier to manage consistently.

Official support information from major mobility platforms shows phone/SMS verification as a common access and recovery pattern. RAFEEQ can follow this familiar pattern while adding its own local PIN convenience layer.

---

# 11. Creating the RAFEEQ PIN

## 11.1 What the PIN is

The RAFEEQ PIN is a **local unlock factor** for a trusted device.

It is not a replacement for:

- Server authentication.
- Refresh tokens.
- OTP account recovery.
- Device registration.
- Biometric security.
- High-risk action verification.

## 11.2 Why use a PIN

A local PIN gives:

- Fast app reopening.
- Protection when someone temporarily holds an unlocked phone.
- Consistent fallback when biometrics fail.
- Familiar finance-style access without sending SMS every time.

## 11.3 PIN setup screen

### Title

> **Create your RAFEEQ PIN**

### Body

> Use this six-digit PIN to unlock RAFEEQ quickly on this device.

### Input

Six digits.

### Primary action

> **Continue**

### Security guidance

> Avoid repeated or easy-to-guess numbers such as 000000 or 123456.

## 11.4 PIN confirmation screen

### Title

> **Confirm your PIN**

### Body

> Enter the same six-digit PIN again.

### Success

> **Your RAFEEQ PIN is ready.**

### Mismatch

> **The PINs do not match. Try again.**

---

# 12. PIN security design

## 12.1 Never store the plain PIN

Do not store:

- Plain PIN in SharedPreferences.
- Plain PIN in SQLite.
- Plain PIN in logs.
- Plain PIN in analytics.
- Reversible encrypted PIN where the app can easily recover it.

Recommended approach:

- Use OS secure storage.
- Derive a key using a strong password-based derivation function with a unique random salt.
- Store only a verifier or use the PIN to unlock an encrypted local session secret.
- Use hardware-backed key storage where available.
- Prefer Android Keystore and iOS Keychain/Secure Enclave capabilities.
- Wipe sensitive memory as soon as practical.

## 12.2 Attempt limits

Example policy:

- First four failures: show remaining attempts.
- Fifth failure: short local lock.
- Repeated lockouts: require OTP re-verification.
- Risk signals may immediately require OTP.

Avoid permanent lockout based only on local PIN mistakes.

## 12.3 Easy PIN prevention

Block at minimum:

- `000000`
- `111111`
- `123456`
- `654321`
- Phone-number suffix.
- Known date patterns where detected.

Do not make rules so strict that users cannot remember the PIN.

## 12.4 PIN scope

PIN applies per trusted device.

If a user creates a PIN on Phone A:

- Phone B must have its own secure local setup.
- Do not synchronise the plain PIN.
- The user may choose the same PIN, but RAFEEQ must not transfer it.

---

# 13. Biometric unlock

After PIN creation, offer biometric access.

## 13.1 Screen copy

### Title

> **Unlock RAFEEQ faster**

### Body

> Use your fingerprint or face recognition to unlock RAFEEQ on this device.

### Primary action

> **Enable biometric unlock**

### Secondary action

> **Not now**

### Supporting text

> Your biometric data stays protected by your device. RAFEEQ does not receive or store your fingerprint or face data.

## 13.2 Behaviour

- Biometric is optional.
- PIN remains the fallback.
- Use platform authentication APIs.
- Do not implement custom face recognition.
- After biometric enrolment changes on the device, require PIN or OTP based on platform risk.
- Do not treat biometric success as proof for every high-risk server action.
- Re-authenticate high-risk actions separately where needed.

---

# 14. Always signed in versus always unlocked

The product must distinguish these concepts.

## Signed in

The device has a valid or renewable server session.

## Unlocked

The user has passed local PIN or biometric protection for the current app session.

A user may be:

| Server session | Local state | Result |
|---|---|---|
| Valid | Unlocked | Open app |
| Valid | Locked | Ask for PIN/biometric |
| Expired but renewable | Locked | Refresh silently, then unlock |
| Invalid/revoked | Any | Require phone OTP |
| Account suspended | Any | Show suspension screen |

## 14.1 When to lock locally

Possible policy:

- Lock after configurable background duration.
- Lock after app process restart.
- Lock immediately when user manually taps “Lock RAFEEQ.”
- Lock after security-sensitive changes.
- Do not lock during an active safety-critical trip screen unless UX and safety requirements are carefully designed.

## 14.2 Recommended default

For MVP:

- Remain signed in.
- Require PIN/biometric after full app restart.
- Require it after a configurable background period.
- Allow the user to change this later under Security Settings.
- Never send OTP on every launch.

---

# 15. Returning user story

Ahmed has already registered.

He opens RAFEEQ the next morning.

The splash screen finds:

- Valid device registration.
- Valid refresh session.
- PIN configured.
- Account active.

RAFEEQ shows:

> **Welcome back, Ahmed**

He enters his PIN.

RAFEEQ opens the last safe home destination.

If biometric unlock is enabled, RAFEEQ attempts biometric first and offers:

> **Use PIN instead**

If the session was revoked from another device or by support, PIN success alone is not enough. RAFEEQ sends Ahmed to mobile verification.

---

# 16. Forgot PIN flow

## 16.1 Entry

On PIN screen:

> **Forgot PIN?**

## 16.2 Explanation

> To protect your account, verify your mobile number before creating a new PIN.

## 16.3 Flow

```text
Forgot PIN
    |
    v
Confirm masked phone number
    |
    +---- Number unavailable? ---> Account recovery/support flow
    |
    v
Request OTP
    |
    v
Verify OTP
    |
    v
Revoke local PIN verifier
    |
    v
Create new PIN
    |
    v
Re-register biometric if required
    |
    v
Return to app
```

## 16.4 Security effects

After successful PIN reset:

- Invalidate the old local PIN verifier.
- Record a security audit event.
- Notify the user through push/SMS/email if configured.
- Consider revoking other sessions if risk signals are present.
- Never reveal the old PIN.
- Never allow customer support to view or set a user’s PIN.

## 16.5 No access to phone number

The user selects:

> **I no longer have this mobile number**

For MVP, this should open a controlled support recovery process.

Possible required evidence:

- Previous phone number.
- Full name.
- Recent trip or booking information.
- Government ID only when proportionate and legally permitted.
- Selfie/liveness for higher-risk recovery.
- New phone ownership verification.

Recovery must not be fully automated using easily guessed information.

---

# 17. Minimal profile setup

After first verification and PIN setup, collect only what is needed to create a usable passenger identity.

## 17.1 Screen title

> **Tell us about you**

## 17.2 Body

> This information helps create a trusted RAFEEQ community.

## 17.3 Fields

### Full name

- Required.
- Two to fifty characters.
- Support Arabic and Latin scripts.
- Allow spaces, hyphens, and apostrophes.
- Reject digits-only names.
- Do not force exactly two names.
- Display explanation that the name should match future verification documents if the user becomes a driver.

Placeholder:

> Your full name

### Profile photo

- Optional for initial MVP passenger registration, depending on trust policy.
- May become recommended or required before certain bookings.
- Let user take a photo or select from gallery.
- Crop to portrait.
- Compress securely.
- Strip unnecessary metadata such as location.
- Moderate prohibited content.
- Use a default avatar if skipped.

Copy:

> Add a clear photo so people can recognise you.

### Gender

Because the product may begin with women-focused communities, this field needs careful product and legal review.

Possible values:

- Woman
- Man
- Prefer not to say

Do not use gender alone as identity verification.

Copy:

> This may be used to support commute preferences and community safety settings.

### Date of birth

Recommended if age restrictions apply.

Rules:

- Do not allow users below the minimum supported age.
- Store the date, not only calculated age.
- Apply privacy minimisation.
- Do not display exact birth date publicly.

### Email

Optional during MVP but recommended for recovery and receipts.

- Verify later.
- Do not block initial account completion unless required.
- Normalise and enforce uniqueness if used as identity data.

---

# 18. Profile completion states

Suggested statuses:

```text
not_started
basic_complete
phone_verified
email_verified
identity_pending
identity_verified
restricted
```

Do not combine all verification concepts into one Boolean such as `is_verified`.

A phone-verified passenger is not the same as an identity-verified driver.

---

# 19. Permissions during registration

Do not request all device permissions immediately.

## 19.1 Notifications

Ask after explaining the benefit:

> **Stay updated about commute requests and trip changes**

Buttons:

- Allow notifications
- Not now

## 19.2 Location

Do not request precise location during authentication.

Request it when the user starts searching or creating a route.

Explain:

> RAFEEQ uses your location to show nearby pickup points and improve route selection.

## 19.3 Contacts

Do not request contacts in Chapter 2.

## 19.4 Camera and gallery

Request only when the user chooses to add a profile photo.

This just-in-time permission model improves trust and reduces denials.

---

# 20. Account and session database

The following schema is a product-level recommendation. Backend engineers may adapt types to the chosen database.

## 20.1 `users`

| Column | Example type | Required | Purpose | Chapter impact |
|---|---|---:|---|---|
| `id` | UUID | Yes | Stable person identifier | Referenced everywhere |
| `phone_e164` | VARCHAR | Yes | Canonical verified number | Unique login identity |
| `phone_verified_at` | TIMESTAMP | Yes | Phone verification time | Security and audit |
| `full_name` | VARCHAR | After setup | Public identity name | Used in bookings/trips |
| `profile_photo_url` | TEXT | No | Avatar | Driver/passenger cards |
| `gender` | ENUM/VARCHAR | Policy-based | Preference support | Matching/privacy rules |
| `date_of_birth` | DATE | Policy-based | Age validation | Safety/compliance |
| `email` | VARCHAR | No | Recovery/receipts | Payments/support |
| `email_verified_at` | TIMESTAMP | No | Email trust state | Recovery |
| `account_status` | ENUM | Yes | Active/suspended/etc. | All chapters |
| `profile_status` | ENUM | Yes | Completion state | Routing |
| `preferred_language` | VARCHAR | Yes | Arabic/English | Notifications/UI |
| `created_at` | TIMESTAMP | Yes | Audit | Reporting |
| `updated_at` | TIMESTAMP | Yes | Audit | Synchronisation |
| `deleted_at` | TIMESTAMP | No | Soft deletion | Compliance |

### Constraints

- Unique index on `phone_e164` for active account policy.
- Optional unique index on normalised email.
- `account_status` must use controlled values.
- Avoid storing role as a single passenger/driver field.
- Driver capability will be inferred from a related driver profile.

---

## 20.2 `otp_challenges`

| Column | Purpose |
|---|---|
| `id` | Challenge identifier |
| `phone_e164` | Intended phone |
| `purpose` | Login, PIN reset, phone change, high-risk action |
| `code_hash` | Hashed OTP |
| `expires_at` | Challenge expiry |
| `attempt_count` | Failed attempts |
| `max_attempts` | Configurable limit |
| `resend_count` | Abuse control |
| `status` | Pending, verified, expired, blocked |
| `device_fingerprint_hash` | Risk binding |
| `ip_hash_or_metadata` | Abuse detection with privacy review |
| `created_at` | Audit |
| `verified_at` | Successful use |

Never expose this table directly to the client.

---

## 20.3 `devices`

| Column | Purpose |
|---|---|
| `id` | Device record |
| `user_id` | Owner |
| `device_public_id` | App-generated device identifier |
| `platform` | Android/iOS |
| `device_model` | Support/risk |
| `os_version` | Compatibility |
| `app_version` | Support/update logic |
| `push_token` | Notifications, stored securely |
| `is_trusted` | Trust status |
| `last_seen_at` | Session visibility |
| `revoked_at` | Device access revocation |
| `created_at` | Audit |

Do not use unstable advertising identifiers as the account identity.

---

## 20.4 `sessions`

| Column | Purpose |
|---|---|
| `id` | Session identifier |
| `user_id` | Account |
| `device_id` | Trusted device |
| `refresh_token_hash` | Server-side token verifier |
| `access_token_id` | Token tracking where needed |
| `access_expires_at` | Access expiry |
| `refresh_expires_at` | Session expiry |
| `last_refreshed_at` | Security/audit |
| `revoked_at` | Logout/revocation |
| `revocation_reason` | User logout, stolen device, risk, admin |
| `created_at` | Audit |

Store refresh tokens securely on the client and store only hashed or otherwise safely managed token material server-side.

---

## 20.5 `security_events`

| Column | Purpose |
|---|---|
| `id` | Event identifier |
| `user_id` | Nullable before user resolution |
| `device_id` | Related device |
| `event_type` | OTP requested, login success, lockout, PIN reset |
| `risk_level` | Low, medium, high |
| `metadata` | Minimal structured context |
| `created_at` | Audit |
| `reviewed_at` | Admin/security review |

Do not place secret values inside metadata.

---

## 20.6 `user_consents`

| Column | Purpose |
|---|---|
| `id` | Consent record |
| `user_id` | User |
| `document_type` | Terms, Privacy, marketing |
| `document_version` | Exact accepted version |
| `accepted_at` | Evidence |
| `withdrawn_at` | Where withdrawal is applicable |
| `source` | Registration/settings |

A Boolean such as `accepted_terms = true` is insufficient when legal documents change.

---

# 21. Effect on Chapter 1

Chapter 1 defined the person-level domain model and the decision that users are not separated by role.

Chapter 2 implements that decision by creating:

- The permanent `users.id`.
- Verified phone identity.
- Account state.
- Device trust.
- Session ownership.
- Consent history.
- Minimal profile.

Chapter 1’s conceptual `User` becomes a real database entity here.

No Chapter 1 data should be duplicated.

---

# 22. Effect on Chapter 3

Chapter 3 must extend Chapter 2 like this:

```text
users
  |
  | 1 to 0..1
  v
driver_profiles
  |
  | 1 to many
  v
vehicles
```

Chapter 3 must not:

- Create `driver_users`.
- Copy the phone number into a separate driver account.
- Create a second session model.
- Require a second general registration.
- Create a separate driver name unless it is a legally verified variation.
- Lose the passenger history when driver capability is enabled.

When a user presses **Become a Driver**, Chapter 3 checks:

- User account active.
- Phone verified.
- Minimum profile complete.
- Minimum age satisfied.
- No active driver application conflict.

Then Chapter 3 creates a `driver_profile` referencing `users.id`.

---

# 23. Authentication API stories

## 23.1 Request OTP

```http
POST /v1/auth/otp/request
```

Request:

```json
{
  "phone": "+201012345678",
  "purpose": "AUTHENTICATION",
  "device": {
    "publicId": "generated-device-id",
    "platform": "ANDROID",
    "appVersion": "1.0.0"
  }
}
```

Successful response:

```json
{
  "success": true,
  "data": {
    "challengeId": "otp_challenge_uuid",
    "expiresInSeconds": 120,
    "resendAvailableInSeconds": 45,
    "maskedPhone": "+20 10 *** 5678"
  }
}
```

Possible errors:

- Invalid phone.
- Unsupported country.
- Rate limited.
- SMS provider unavailable.
- Device blocked.
- Mandatory app update.

---

## 23.2 Verify OTP

```http
POST /v1/auth/otp/verify
```

Request:

```json
{
  "challengeId": "otp_challenge_uuid",
  "code": "482931",
  "devicePublicId": "generated-device-id"
}
```

Response for a new account:

```json
{
  "success": true,
  "data": {
    "accountState": "NEW_USER",
    "nextStep": "CREATE_PIN",
    "accessToken": "short-lived-access-token",
    "refreshToken": "rotating-refresh-token",
    "user": {
      "id": "user_uuid",
      "phone": "+201012345678",
      "profileStatus": "NOT_STARTED"
    }
  }
}
```

Response for returning account:

```json
{
  "success": true,
  "data": {
    "accountState": "EXISTING_USER",
    "nextStep": "LOCAL_SECURITY_SETUP_OR_HOME",
    "accessToken": "short-lived-access-token",
    "refreshToken": "rotating-refresh-token",
    "user": {
      "id": "user_uuid",
      "profileStatus": "BASIC_COMPLETE"
    }
  }
}
```

---

## 23.3 Session refresh

```http
POST /v1/auth/session/refresh
```

Requirements:

- Rotate refresh token.
- Detect reuse of an old rotated token.
- Revoke suspicious token family when reuse is detected.
- Return a new access token.
- Update device last seen.
- Never return user secrets.

---

## 23.4 Logout current device

```http
POST /v1/auth/logout
```

Effects:

- Revoke current session.
- Remove or invalidate current push token association.
- Clear secure local token storage.
- Clear local PIN-protected secret.
- Preserve user account.
- Return to Auth Entry.

---

## 23.5 Logout another device

```http
DELETE /v1/account/devices/{deviceId}/session
```

Effects:

- Revoke that device’s sessions.
- Mark device untrusted or revoked.
- Send a security notification.
- Do not log out the current device unless selected.

---

# 24. Flutter feature structure

Suggested structure:

```text
features/
└── authentication/
    ├── data/
    │   ├── datasources/
    │   │   ├── auth_remote_data_source.dart
    │   │   └── auth_secure_local_data_source.dart
    │   ├── models/
    │   │   ├── otp_challenge_model.dart
    │   │   ├── auth_session_model.dart
    │   │   ├── user_model.dart
    │   │   └── device_model.dart
    │   └── repositories/
    │       └── auth_repository_impl.dart
    ├── domain/
    │   ├── entities/
    │   ├── repositories/
    │   └── usecases/
    └── presentation/
        ├── onboarding/
        ├── phone_entry/
        ├── otp/
        ├── pin_setup/
        ├── pin_unlock/
        ├── biometric_setup/
        └── profile_setup/
```

## Recommended state separation

Do not place every authentication concern in one giant Cubit.

Possible Cubits/controllers:

- `LaunchRouterCubit`
- `OnboardingCubit`
- `PhoneAuthCubit`
- `OtpCubit`
- `PinSetupCubit`
- `AppLockCubit`
- `ProfileSetupCubit`
- `SessionCubit`

Shared session state should be exposed at application level.

---

# 25. Detailed scenario catalogue

## Scenario A — New user completes registration

Mariam opens RAFEEQ, completes onboarding, enters a valid mobile number, receives OTP, enters it correctly, creates a secure PIN, enables biometric unlock, adds her name, and opens Home.

Expected:

- One user record.
- Phone marked verified.
- One device record.
- One active session.
- Consent records stored.
- PIN secret only in secure local storage.
- No driver profile.
- Passenger capability available.

---

## Scenario B — Existing user reinstalls the app

Ahmed deletes and reinstalls RAFEEQ.

Local session and PIN are gone.

He enters the same phone number and verifies OTP.

Expected:

- Existing user reused.
- No duplicate user.
- New device installation record or refreshed device identity based on policy.
- New session.
- PIN setup required for this installation.
- Existing trip and account history restored.

---

## Scenario C — User forgets PIN

Sara enters the wrong PIN repeatedly, then taps **Forgot PIN?**

Expected:

- OTP verification required.
- Old local PIN verifier removed.
- New PIN created.
- Security event written.
- Other sessions remain or are revoked based on risk policy.
- Support cannot reveal the old PIN.

---

## Scenario D — Attacker knows the phone number

An attacker enters Mariam’s phone number but does not control the phone.

Expected:

- Attacker cannot learn whether account exists.
- Attacker cannot sign in without OTP.
- Rate limiting prevents SMS harassment.
- Mariam may receive a security notification after suspicious repeated requests.
- No personal information returned before verification.

---

## Scenario E — OTP arrives after resend

Ahmed requests OTP A, waits, then requests OTP B. SMS A arrives late.

Expected:

- OTP A fails.
- OTP B succeeds.
- UI explains that only the newest code works.
- Attempt count applies to active challenge policy.

---

## Scenario F — User changes mobile number later

A signed-in user requests a phone-number change.

Expected high-level flow:

1. Re-authenticate locally.
2. Verify old number when available.
3. Verify new number.
4. Check that new number is not assigned to another active account.
5. Update canonical phone in one transaction.
6. Revoke or review sessions.
7. Notify both old and new channels where appropriate.
8. Record security event.

This is an account-security feature, not a simple profile edit.

---

## Scenario G — Device is stolen

Ahmed opens RAFEEQ on another device and revokes the stolen phone.

Expected:

- Stolen device refresh token revoked.
- Push token removed.
- Existing access token expires quickly or is actively denied according to backend strategy.
- Local PIN alone cannot restore server access.
- Security event and user notification created.

---

## Scenario H — User is suspended

The session is locally valid, but backend account status is suspended.

Expected:

- Splash does not open Home.
- Show suspension reason category without exposing internal security logic.
- Allow Help/Appeal where applicable.
- Block bookings, commute publishing, and profile-sensitive actions.
- Do not silently create a new account using the same number.

---

## Scenario I — No internet during phone entry

The user enters a valid phone number and presses send while offline.

Expected:

- No false claim that OTP was sent.
- Show connectivity error.
- Keep entered phone number.
- Retry button available.
- Do not create duplicate local requests.

---

## Scenario J — No internet after successful login

The user verified OTP, received session tokens, then loses connectivity during profile setup.

Expected:

- Preserve safe draft fields locally.
- Do not mark server profile complete until API succeeds.
- Resume profile setup on next launch.
- Avoid creating a second user/session.
- Do not expose Home features that require complete profile if policy blocks them.

---

# 26. QA test checklist

## Onboarding

- First launch shows onboarding.
- Returning signed-out user does not repeatedly see onboarding.
- Skip works.
- Terms and Privacy links open.
- RTL layout is correct.
- Page state survives app backgrounding.
- Analytics distinguishes skip and completion.

## Phone entry

- Accept valid Egyptian formats.
- Normalise to E.164.
- Reject malformed numbers.
- Prevent account enumeration.
- Button state is correct.
- Double tap does not send multiple challenges.
- Offline error preserves input.

## OTP

- Correct code succeeds.
- Incorrect code shows correct error.
- Expired code fails.
- Old code fails after resend.
- Paste works.
- Android/iOS autofill works where supported.
- Rate limit response is handled.
- App background/foreground preserves safe challenge state.
- OTP never appears in logs.

## PIN

- PIN setup requires confirmation.
- Mismatch handled.
- Weak PIN rules enforced.
- Plain PIN not stored.
- Attempt limits work.
- Forgot PIN starts OTP recovery.
- PIN is device-specific.
- Session revocation overrides PIN success.

## Biometric

- Enable and skip work.
- PIN fallback exists.
- Biometric cancellation handled.
- Device biometric change handled.
- No biometric data is stored by RAFEEQ.
- Repeated failures do not trap the user.

## Sessions

- Access token refreshes.
- Refresh rotation works.
- Logout revokes session.
- Revoked session cannot refresh.
- Second device creates independent session.
- User can view and revoke devices.
- Stolen-device scenario works.
- Account suspension blocks Home.

## Profile

- Name validations work in Arabic and English.
- Optional photo skip works.
- Photo metadata is stripped.
- Minimum age rule works.
- Profile draft resumes after interruption.
- Profile completion status is correct.

---

# 27. Definition of done

Chapter 2 is complete only when:

- Onboarding content is approved.
- Terms and Privacy links are available.
- Phone normalisation is tested.
- OTP provider integration works.
- OTP abuse limits exist server-side.
- One-account-per-phone rules are enforced.
- Login and registration share one flow.
- Local PIN is securely implemented.
- Biometric fallback works.
- Sessions use secure token storage.
- Logout and device revocation work.
- Minimal profile setup is implemented.
- Account status routing is implemented.
- Database migrations are reviewed.
- Security events are logged.
- Unit, widget, integration, and manual tests pass.
- Chapter 3 can create a driver profile using the same `user_id`.

---

# 28. Final product behaviour

After this chapter, RAFEEQ should feel simple to the user:

> Open the app, understand the idea, verify your phone once, create a quick PIN, and stay signed in.

Underneath that simple experience, the system must provide:

- Strong identity continuity.
- Secure sessions.
- Safe local unlocking.
- Recoverable account access.
- Abuse controls.
- Clear account states.
- A database foundation that every future chapter can trust.

That balance—simple outside, strict inside—is the purpose of Chapter 2.
