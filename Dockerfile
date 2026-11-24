# ---- 1. PHP dependencies -------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# ---- 2. Frontend build (needs vendor/ for the Ziggy route helper) --------
FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. Runtime: FrankenPHP (Caddy + PHP 8.4) -----------------------------
FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions pdo_pgsql pdo_sqlite bcmath intl pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/start.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

ENV PORT=8080
EXPOSE 8080
CMD ["docker/start.sh"]
