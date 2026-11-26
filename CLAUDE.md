# CLAUDE.md

## ⚠️ Production on the VPS runs with a FIXED OTP (`123456`)

The VPS (153.79.251.111, Namecheap) hosts two copies of the app:

| | Production | Staging |
|---|---|---|
| URL | `api.yugoeg.com` | `staging.yugoeg.com` |
| Folder | `/var/www/rafeeq` | `/var/www/rafeeq-staging` |
| Branch | `main` | `staging` |
| Deploys | automatically on push to `main`, after `tests` passes | automatically on push to `staging`, after `tests` passes |

There is no SMS provider yet, so **both** run `APP_ENV=staging` with `RAFEEQ_DEV_OTP_CODE=123456` — the fixed code is refused outright under `APP_ENV=production` (`RequestOtpAction`). On production that means **anyone who knows a phone number can sign in as that person**.

**Before real users sign in on `api.yugoeg.com`:** set up the SMS provider (`RAFEEQ_OTP_DRIVER=http` + `RAFEEQ_SMS_*`, see `DEPLOYMENT.md`), remove `RAFEEQ_DEV_OTP_CODE`, choose a real virus scanner, and set `APP_ENV=production` in `/var/www/rafeeq/.env`. Production already has rate limits on and dashboard MFA on.

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
