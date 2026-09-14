#!/usr/bin/env bash
# Backend container entrypoint (Railway). Migrate, cache config, then start Octane.
set -euo pipefail

# Fail fast if the MySQL reference variables were not linked into this service.
: "${DB_HOST:?DB_HOST missing — link the MySQL service variables into the backend}"

# Same rule for the SPA origin, because getting this wrong is SILENT on the server:
# config/cors.php falls back to http://localhost:5173, every preflight from the real frontend
# is answered with that origin, and the browser blocks the request. Nothing reaches the logs —
# the only symptom is a CORS error in someone's client console. Scoped to production so a
# local container keeps using the default.
if [ "${APP_ENV:-production}" = "production" ] && [ -z "${FRONTEND_URL:-}" ]; then
    echo "FRONTEND_URL missing — set it to the SPA's public URL (it is the allowed CORS origin)" >&2
    exit 1
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache

exec php artisan octane:start \
    --server=swoole \
    --host=0.0.0.0 \
    --port="${PORT:-8000}" \
    --workers=2 \
    --max-requests=500
