#!/bin/sh
# RAFEEQ — container start.
#
# 🔴 Everything here runs at START, not at build, and that is the whole point of the file. At build
# time the platform has not injected its service variables, so a `config:cache` there would bake in
# an empty database password and the wrong APP_URL — and succeed, which is worse than failing.
#
# 🔴 And the checks below run BEFORE any artisan command, all of them, reporting everything wrong at
# once. That ordering was learned the hard way: each missing variable used to surface on its own,
# one redeploy at a time, and the one that cost the most was not even a missing value — it was
# `BROADCAST_CONNECTION=reverb` with no Reverb keys, which dies inside the Pusher constructor with
# a type error about `$auth_key` and never mentions broadcasting, configuration, or what to do.

set -e

PORT="${PORT:-8080}"

# A hard-coded port is the most common cause of "Application failed to respond": the edge connects
# to a port nothing is listening on.
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf

# ---------------------------------------------------------------------------
# Preflight. Collect every problem, then report once.
# ---------------------------------------------------------------------------

FATAL=""
WARN=""

fatal() { FATAL="${FATAL}
  ✗ $1"; }
warn()  { WARN="${WARN}
  ! $1"; }

# 🔴 Without a key every encrypted column — the national ID, the licence number, the push token —
# is unreadable, and what surfaces at runtime are decryption errors rather than a word about
# configuration.
if [ -z "${APP_KEY}" ]; then
    fatal "APP_KEY is not set. Generate one with:  php artisan key:generate --show"
elif [ "${APP_KEY#base64:}" = "${APP_KEY}" ] && [ ${#APP_KEY} -ne 32 ]; then
    # Either a raw 32-byte key or the usual `base64:` form. Anything else decrypts nothing.
    fatal "APP_KEY is not a valid key. It should look like 'base64:…' — regenerate it with:  php artisan key:generate --show"
fi

# The database. Named individually because "connection refused" tells you nothing about WHICH of
# five variables was left out.
for VAR in DB_HOST DB_PORT DB_DATABASE DB_USERNAME; do
    eval "VALUE=\${$VAR}"
    [ -z "${VALUE}" ] && fatal "${VAR} is not set. On Railway use the service reference, e.g. DB_HOST=\${{MySQL.MYSQLHOST}}"
done

# A blank password is legitimate locally and almost never intended on a hosted database, so it
# warns rather than refuses.
[ -z "${DB_PASSWORD}" ] && warn "DB_PASSWORD is empty. Intended only if the database genuinely has no password."

# 🔴 The one that cost a deploy. See the note at the top of this file.
if [ "${BROADCAST_CONNECTION}" = "reverb" ]; then
    MISSING=""
    [ -z "${REVERB_APP_ID}" ] && MISSING="${MISSING} REVERB_APP_ID"
    [ -z "${REVERB_APP_KEY}" ] && MISSING="${MISSING} REVERB_APP_KEY"
    [ -z "${REVERB_APP_SECRET}" ] && MISSING="${MISSING} REVERB_APP_SECRET"

    if [ -n "${MISSING}" ]; then
        fatal "BROADCAST_CONNECTION=reverb but missing:${MISSING}
    Reverb needs all three secrets AND a running 'php artisan reverb:start' service.
    If you do not have one yet, set BROADCAST_CONNECTION=log — the live map stops
    updating by itself and everything else works, including position polling."
    fi
fi

# APP_URL without a scheme produces links like 'rafeeq.up.railway.app/s/abc', which no client can
# open — and the live-share link a passenger sends a relative is built from exactly this value.
case "${APP_URL}" in
    "")            warn "APP_URL is not set. Generated links (live share, signed URLs) will be wrong." ;;
    http://*)      warn "APP_URL is http://. Session cookies are marked secure, so sign-in will fail over plain HTTP." ;;
    https://*)     ;;
    *)             fatal "APP_URL has no scheme: '${APP_URL}'. It must start with https:// — every link we generate is built from it." ;;
esac

# 🔒 Two settings that are safe locally and dangerous on anything reachable.
[ "${APP_DEBUG}" = "true" ] && warn "APP_DEBUG=true. The error page shows SQL, environment values and source. Set it to false."
[ "${RAFEEQ_RATE_LIMITS}" = "off" ] && warn "RAFEEQ_RATE_LIMITS=off. OTP, search, chat and report limits are OFF (ignored on APP_ENV=production). For testing only."
[ "${LOG_LEVEL}" = "debug" ] && warn "LOG_LEVEL=debug. On a non-production APP_ENV this writes OTP CODES to the log, which is a sign-in bypass for anyone who can read it."

if [ -n "${WARN}" ]; then
    printf '\n⚠️  RAFEEQ — configuration warnings (starting anyway):%s\n\n' "${WARN}" >&2
fi

if [ -n "${FATAL}" ]; then
    printf '\n🔴 RAFEEQ — refusing to start. Fix these in the platform'\''s variables:%s\n\n' "${FATAL}" >&2
    printf 'Full reference: .env.production.example · deploy/RAILWAY_SETUP.md\n\n' >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Now that the environment exists and is sane, cache it.
# ---------------------------------------------------------------------------

php artisan config:cache
php artisan route:cache
php artisan view:cache

# 🔴 Migrations are NOT run here, deliberately. Two replicas starting at once would run them
# concurrently, and a half-applied migration is worse than an unmigrated database. Run them as the
# platform's pre-deploy/release step, which happens once:
#
#     php artisan migrate --force
#
# 🔴 But they ARE checked here. Without the pre-deploy step the new code starts against the old
# schema, and what that looks like is a bare "500 Server Error" on the first page that reads a new
# table — the admin dashboard, in the case that added this check — with nothing about migrations
# anywhere. Refusing to start says it in one line, and the platform keeps serving the previous
# deployment instead of new code on an old database.
#
# `--pending=3` exits 3 only when migrations are pending; any other failure (database unreachable,
# no migrations table yet) is reported but does not block the start.
set +e
PENDING_OUTPUT="$(php artisan migrate:status --pending=3 2>&1)"
PENDING_STATUS=$?
set -e

if [ "${PENDING_STATUS}" -eq 3 ]; then
    printf '\n🔴 RAFEEQ — refusing to start: the database is behind the code.\n%s\n\n' "${PENDING_OUTPUT}" >&2
    printf 'Run the migrations once, as the platform'\''s pre-deploy step:\n    php artisan migrate --force\nOn Railway: Service → Settings → Deploy → Pre-deploy Command. See deploy/RAILWAY_SETUP.md.\n\n' >&2
    exit 1
elif [ "${PENDING_STATUS}" -ne 0 ]; then
    printf '\n⚠️  RAFEEQ — could not check for pending migrations (starting anyway):\n%s\n\n' "${PENDING_OUTPUT}" >&2
fi
#
# ⚠️ And the queue worker, Reverb and the scheduler are separate services. Without them,
# notifications never arrive, the live map never moves, and GPS trails are lost — none of which
# reports an error anywhere. See deploy/RAILWAY_SETUP.md.

exec supervisord -c /etc/supervisord.conf
