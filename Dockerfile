FROM php:8.5-fpm AS base


RUN apt-get update && apt-get install -y \
    git unzip curl sqlite3 libsqlite3-dev nginx supervisor \
    && docker-php-ext-install pdo_sqlite bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install Node.js for asset building
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader

# Copy package files and install npm dependencies
COPY package.json package-lock.json ./
RUN npm ci

# Copy application code
COPY . .

# Save version from git
RUN if [ -f .git/HEAD ]; then \
        REF=$(cat .git/HEAD); \
        if echo "$REF" | grep -q "^ref:"; then \
            REF_PATH=$(echo "$REF" | sed 's/^ref: //'); \
            cat ".git/$REF_PATH" > VERSION 2>/dev/null || echo "unknown" > VERSION; \
        else \
            echo "$REF" > VERSION; \
        fi; \
    else echo "unknown" > VERSION; fi

# Complete composer autoload
RUN composer dump-autoload --optimize

# Build frontend assets
RUN npm run build && rm -rf node_modules

# Create required directories
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && mkdir -p database

# Configure nginx
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Configure supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/streampigeon.conf

# Set permissions
RUN chown -R www-data:www-data storage bootstrap/cache database

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
