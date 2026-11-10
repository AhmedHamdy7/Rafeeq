# CLAUDE.md

## ⚠️ Rate limits are currently DISABLED

`RAFEEQ_RATE_LIMITS=off` is set (local `.env` and `.env.production.example`) so the team can test without locking itself out. This turns off:

- OTP request limits (5/hour per phone, 20/hour per IP)
- OTP resend cooldown (45s) and max resends (3)
- OTP verify limit (10/minute per challenge)
- Search, chat and report limits

The 3-wrong-attempts limit and 5-minute lock per number still apply.

**Before going to production, set `RAFEEQ_RATE_LIMITS=on` (or remove it) on every server where real people sign in**, including the Railway env vars and `.env.production.example`.

The switch is ignored when `APP_ENV=production` (see `app/Domains/Shared/Support/RateLimits.php`), but staging runs with `APP_ENV=staging`, so it does take effect there.

## ⚠️ Dashboard authenticator code is currently DISABLED

`RAFEEQ_ADMIN_MFA_ENABLED=false` is set (local `.env` and `.env.production.example`), so the admin dashboard signs in with email and password only — no Google Authenticator code.

**Before real staff use the dashboard, set `RAFEEQ_ADMIN_MFA_ENABLED=true` (or remove it)**, including the Railway env vars and `.env.production.example`.

Like the rate-limit switch, it is ignored when `APP_ENV=production` (see `app/Domains/Admin/Support/AdminMfa.php`) and takes effect on staging. `phpunit.xml` forces it `true` so the MFA tests keep running.
