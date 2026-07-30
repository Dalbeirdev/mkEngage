# mkEngage control plane (Laravel) on FrankenPHP.
# The same image runs the web server, the queue worker and the outbox relay —
# the command decides (see deploy/docker-compose.prod.yml).
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql pgsql pcntl intl zip bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# FrankenPHP serves /app/public by default; keep it on :8000 to match dev.
ENV SERVER_NAME=:8000

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000
