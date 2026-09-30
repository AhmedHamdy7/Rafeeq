#!/bin/sh
# RAFEEQ — container start.
#
# 🔴 Everything here runs at START, not at build, and that is the whole point of the file. At build
# time the platform has not injected its service variables, so a `config:cache` there would bake in
# an empty database password and the wrong APP_URL — and succeed, which is worse than failing.

set -e

PORT="${PORT:-8080}"

# A hard-coded port is the most common cause of "Application failed to respond": the edge connects
# to a port nothing is listening on.
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf

# 🔴 Refuse to start without an application key rather than booting into confusing failures. Every
# encrypted column — the national ID, the licence number, the push token — is unreadable without
# it, and the errors that surface are about decryption rather than about configuration.
if [ -z "${APP_KEY}" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show' and set it" >&2
    echo "       in the platform's variables. Encrypted columns are unreadable without it." >&2
    exit 1
fi

# Now that the environment exists, cache it.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 🔴 Migrations are NOT run here, deliberately. Two replicas starting at once would run them
# concurrently, and a half-applied migration is worse than an unmigrated database. Run them as the
# platform's pre-deploy/release step, which happens once:
#
#     php artisan migrate --force
#
# ⚠️ And the queue worker, Reverb and the scheduler are separate services. Without them,
# notifications never arrive, the live map never moves, and GPS trails are lost — none of which
# reports an error anywhere. See deploy/RAILWAY_SETUP.md.

exec supervisord -c /etc/supervisord.conf
