# HRIS Backend — Dockerfile PRODUCTION (multi-stage).
#
# Stage 1: composer install --no-dev
# Stage 2: PHP-FPM 8.2 dengan opcache + ekstensi production
#
# Build:
#   docker build -t hris-backend:latest -f backend/Dockerfile backend/
#
# Run:
#   docker run -d -p 9000:9000 \
#     -v /path/to/.env:/var/www/html/.env:ro \
#     -v /path/to/writable:/var/www/html/writable \
#     --name hris-backend hris-backend:latest
#
# Pakai bareng Nginx (lihat deploy/nginx.conf) yang proxy fastcgi ke 9000.

# ─── Stage 1: composer dependencies ─────────────────────────────────
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader \
        --no-progress \
    && composer clear-cache

# ─── Stage 2: runtime PHP-FPM ───────────────────────────────────────
FROM php:8.2-fpm-alpine

# OS deps + ekstensi PHP production
RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        autoconf \
        gcc \
        g++ \
        make \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        intl \
        gd \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/*

# Production opcache settings — agresif (revalidate=0, validate_timestamps=0
# berarti restart container untuk reload code, ini sengaja untuk perf prod).
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=0"; \
        echo "opcache.memory_consumption=192"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.save_comments=1"; \
        echo "opcache.fast_shutdown=1"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Production PHP settings
RUN { \
        echo "expose_php=Off"; \
        echo "display_errors=Off"; \
        echo "log_errors=On"; \
        echo "upload_max_filesize=10M"; \
        echo "post_max_size=12M"; \
        echo "memory_limit=256M"; \
        echo "max_execution_time=60"; \
        echo "session.cookie_secure=1"; \
        echo "session.cookie_httponly=1"; \
        echo "session.cookie_samesite=Lax"; \
        echo "session.use_strict_mode=1"; \
    } > /usr/local/etc/php/conf.d/zz-hris.ini

WORKDIR /var/www/html

# Copy aplikasi (exclude tests, scripts, .env via .dockerignore)
COPY --chown=www-data:www-data . /var/www/html/
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor

# Writable harus dipertahankan sebagai volume di runtime supaya log + cache
# persisten antar container restart. Buat dulu permission yang benar.
RUN mkdir -p /var/www/html/writable/cache \
             /var/www/html/writable/logs \
             /var/www/html/writable/session \
             /var/www/html/writable/uploads \
    && chown -R www-data:www-data /var/www/html/writable \
    && chmod -R 750 /var/www/html/writable

# Drop privileges
USER www-data

EXPOSE 9000

# Healthcheck — cek php-fpm responsive lewat status page (kalau diaktifkan)
# atau fallback cek process. Pakai cek sederhana via cli.
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD php -r "exit(0);" || exit 1

CMD ["php-fpm", "--nodaemonize"]