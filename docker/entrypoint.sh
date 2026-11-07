#!/bin/sh
set -e

# Create .env from .env.example if missing
if [ ! -f /app/.env ]; then
    cp /app/.env.example /app/.env
fi

# Generate APP_KEY if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    if grep -q "^APP_KEY=$" /app/.env 2>/dev/null || ! grep -q "^APP_KEY=" /app/.env 2>/dev/null; then
        php /app/artisan key:generate --force
    fi
fi

# Create SQLite database if missing
if [ ! -f /app/database/database.sqlite ]; then
    touch /app/database/database.sqlite
fi
chown www-data:www-data /app/database/database.sqlite

# Fix storage permissions
mkdir -p /app/storage/logs /app/storage/framework/{sessions,views,cache}

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

exec "$@"
