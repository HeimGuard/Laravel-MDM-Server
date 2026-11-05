# syntax=docker/dockerfile:1

# Single Alpine base for all stages: small, and only one image to pull.
ARG ALPINE_VERSION=3.22

FROM alpine:${ALPINE_VERSION} AS php

# Only the extensions the application needs (see `composer check-platform-reqs --no-dev`).
RUN apk add --no-cache \
        php84 \
        php84-ctype \
        php84-curl \
        php84-dom \
        php84-fileinfo \
        php84-iconv \
        php84-mbstring \
        php84-opcache \
        php84-openssl \
        php84-pcntl \
        php84-pdo_mysql \
        php84-pdo_pgsql \
        php84-pdo_sqlite \
        php84-posix \
        php84-session \
        php84-simplexml \
        php84-tokenizer \
        php84-xml \
        php84-xmlwriter \
    && ln -s /usr/bin/php84 /usr/bin/php

WORKDIR /var/www


FROM php AS vendor

# Composer phar run by our PHP 8.4 (Alpine's composer package depends on a different PHP version).
RUN apk add --no-cache php84-phar php84-zip
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

COPY src/laravel/composer.json src/laravel/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY src/laravel/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts --no-interaction


FROM alpine:${ALPINE_VERSION} AS assets

RUN apk add --no-cache nodejs npm

WORKDIR /build
COPY src/laravel/package.json src/laravel/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY src/laravel/vite.config.js ./
COPY src/laravel/resources ./resources
RUN npm run build


FROM php AS runtime

# pgloader: copies an SQLite database into PostgreSQL once, when DB_CONNECTION is changed to pgsql (entrypoint).
RUN apk add --no-cache nginx php84-fpm supervisor pgloader \
    && adduser -D -H -u 1000 -s /sbin/nologin app \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx \
    && chown -R app:app /run/nginx /var/lib/nginx /var/log/nginx

COPY src/docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY src/docker/php/php.ini /etc/php84/conf.d/zz-app.ini
COPY src/docker/php/fpm.conf /etc/php84/php-fpm.d/www.conf
COPY src/docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY src/docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --from=vendor --chown=app:app /var/www ./
COPY --from=assets --chown=app:app /build/public/build ./public/build
# Served at /agent/app.ps1 for the install commands.
COPY --chown=app:app src/powershell/app.ps1 ./resources/agent/app.ps1

# /usr/share/laravel-mdm-database: pristine copy the entrypoint restores migrations from when a
# volume is mounted over database/.
RUN rm -rf tests .env .env.example phpunit.xml \
    && php artisan package:discover --ansi \
    && ln -sfn /var/www/storage/app/public public/storage \
    && chown -R app:app bootstrap/cache storage \
    && cp -a database /usr/share/laravel-mdm-database

USER app

ENV APP_ENV=production \
    APP_DEBUG=false \
    REVERB_ENABLED=true \
    SCHEDULER_ENABLED=true \
    QUEUE_ENABLED=true

VOLUME ["/var/www/storage"]
EXPOSE 8000

ENTRYPOINT ["entrypoint"]
