#!/bin/sh
# web + queue worker + scheduler in one container (Render free has no workers)
set -e

# Render's generated value has no "base64:" prefix
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

(while true; do php artisan queue:work --sleep=1 --tries=8 --max-time=3600 || true; sleep 2; done) &

(while true; do php artisan schedule:work || true; sleep 2; done) &

exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
