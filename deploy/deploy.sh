#!/usr/bin/env bash
# RAFEEQ — deploy one commit to one environment on the VPS.
#
# Called by .github/workflows/deploy.yml over SSH, as the `deploy` user:
#
#   bash /var/www/rafeeq/deploy/deploy.sh <commit-sha>           # production
#   bash /var/www/rafeeq-staging/deploy/deploy.sh <commit-sha>   # staging
#
# The environment is whichever checkout the script lives in — each has its own .env,
# database, queue worker and Reverb port, so the same script serves both.
#
# Can also be run by hand on the server with no argument to deploy the tip of the branch the
# checkout is on (main for production, staging for staging).
#
# 🔴 Everything lives inside main() on purpose. Bash reads a script as it runs it, and
# step 1 below rewrites THIS file with `git reset`. Without the function, bash would carry
# on reading the new file from the old byte offset and run half a line of something else.
# A function is parsed whole before it starts, so the running copy never changes under it.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_FPM_SERVICE="php8.4-fpm"

main() {
    local sha="${1:-}"

    cd "$APP_DIR"

    echo "==> deploying to $APP_DIR"
    echo "==> 1/6 fetching code"
    git fetch --quiet origin
    if [[ -n "$sha" ]]; then
        git reset --hard --quiet "$sha"
    else
        git reset --hard --quiet "origin/$(git rev-parse --abbrev-ref HEAD)"
    fi
    echo "    now at $(git rev-parse --short HEAD): $(git log -1 --pretty=%s)"

    echo "==> 2/6 composer install"
    # `--no-dev`: pest/paratest/larastan have no business on a server (see nixpacks.toml).
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

    echo "==> 3/6 migrations"
    php artisan migrate --force

    echo "==> 4/6 caches"
    # Clear first: a cached config from the previous release would otherwise survive a
    # changed .env or a renamed config key.
    php artisan optimize:clear
    php artisan optimize

    echo "==> 5/6 restarting workers"
    # ⚠️ Long-lived PHP processes keep the code they booted with. Without these two the
    # queue and the WebSocket server would go on running the previous release.
    # Both work by writing a flag to the cache, so they need no sudo.
    php artisan queue:restart
    php artisan reverb:restart
    # OPcache in PHP-FPM holds compiled files from the old release. The deploy user may
    # run exactly this one command with sudo (see /etc/sudoers.d/rafeeq-deploy).
    sudo /usr/bin/systemctl reload "$PHP_FPM_SERVICE"

    echo "==> 6/6 health check"
    # Through the real URL (DNS → nginx → TLS → PHP-FPM), so a broken certificate or nginx
    # config fails the deploy too, not just a broken app.
    local app_url attempt
    app_url="$(grep -m1 '^APP_URL=' .env | cut -d= -f2- | tr -d '"'"'"' ')"
    for attempt in 1 2 3 4 5; do
        if curl --silent --fail --location --max-time 5 --output /dev/null "$app_url/up"; then
            echo "    $app_url/up answered 200"
            echo "==> deployed $(git rev-parse --short HEAD)"
            return 0
        fi
        sleep 2
    done

    echo "!! /up did not answer 200 after deploy — check storage/logs/laravel.log" >&2
    return 1
}

main "$@"
exit
