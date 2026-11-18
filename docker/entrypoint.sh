#!/bin/sh
set -e

# Create .env from .env.example if missing
if [ ! -f /app/.env ]; then
    cp /app/.env.example /app/.env
fi

# APP_KEY encrypts the stored secrets and must come from the .env next to
# docker-compose.yml. It is never generated here: a key that only lives in the
# container is lost on the next update, and every stored secret with it.
if [ -z "$APP_KEY" ] && ! grep -q "^APP_KEY=." /app/.env 2>/dev/null; then
    echo "" >&2
    echo "  ERROR: APP_KEY is not set. Add it to the .env next to docker-compose.yml:" >&2
    echo '    echo "APP_KEY=base64:$(openssl rand -base64 32)" >> .env' >&2
    echo "  then run: docker compose up -d" >&2
    echo "" >&2
    exit 1
fi

# Refuse to start with debug output in production; the app also enforces this at runtime.
if [ "${APP_ENV:-production}" = "production" ] && [ "${APP_DEBUG:-false}" = "true" ]; then
    echo "  WARNING: APP_DEBUG=true is ignored in production." >&2
    export APP_DEBUG=false
fi

# Create SQLite database if missing
if [ ! -f /app/database/database.sqlite ]; then
    touch /app/database/database.sqlite
fi
chown www-data:www-data /app/database/database.sqlite

# Fix storage permissions
mkdir -p /app/storage/logs /app/storage/framework/sessions /app/storage/framework/views /app/storage/framework/cache

# Run migrations
php /app/artisan migrate --force --no-interaction

# Cache only in production
if [ "$APP_ENV" = "production" ]; then
    php /app/artisan config:cache
    php /app/artisan route:cache
    php /app/artisan view:cache
else
    php /app/artisan config:clear 2>/dev/null || true
    php /app/artisan route:clear 2>/dev/null || true
    php /app/artisan view:clear 2>/dev/null || true
fi

# Hand ownership to the web user
chown -R www-data:www-data /app/storage /app/bootstrap/cache /app/database
chmod -R ug+rwX /app/storage /app/bootstrap/cache

echo ""
echo "  StreamRadar is ready!"
echo ""

# First start: no accounts exist yet. Print a one-time token so the operator
# can create the first administrator on /setup. Nothing is created automatically.
php /app/artisan auth:setup-token --if-needed || true

exec "$@"
