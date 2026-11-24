#!/bin/sh
# Runs the web server, queue worker and scheduler in one container, so the app
# works on a single free web instance (no separate worker service needed).
set -e

# Render can generate a random base64 secret; Laravel expects the "base64:" prefix.
case "$APP_KEY" in
    base64:*) ;;
    "") echo "APP_KEY is not set" >&2; exit 1 ;;
    *) export APP_KEY="base64:$APP_KEY" ;;
esac
export APP_URL="${APP_URL:-$RENDER_EXTERNAL_URL}"

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

# Queue worker (submissions + webhook processing), restarted if it ever exits.
(while true; do php artisan queue:work --sleep=1 --tries=8 --max-time=3600 || true; sleep 2; done) &

# Scheduler: the polling fallback for late or missing webhooks.
(while true; do php artisan schedule:work || true; sleep 2; done) &

exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
