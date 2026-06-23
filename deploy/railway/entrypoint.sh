#!/usr/bin/env bash
# Backend container entrypoint (Railway). Migrate, cache config, then start Octane.
set -euo pipefail

# Fail fast if the MySQL reference variables were not linked into this service.
: "${DB_HOST:?DB_HOST missing — link the MySQL service variables into the backend}"

php artisan migrate --force
php artisan config:cache
php artisan route:cache

exec php artisan octane:start \
    --server=swoole \
    --host=0.0.0.0 \
    --port="${PORT:-8000}" \
    --workers=2 \
    --max-requests=500
