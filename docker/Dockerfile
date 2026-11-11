# Stage 1: Dependency Builder
FROM docker.io/library/composer:2 AS builder
RUN apk add --no-cache git
WORKDIR /app
COPY . .
RUN composer install --optimize-autoloader --no-interaction --no-scripts

# Stage 2: Final Production Image
FROM docker.io/dunglas/frankenphp:1-php8.5-alpine
RUN apk upgrade --no-cache \
    && apk add --no-cache \
    curl \
    netcat-openbsd \
    tzdata \
    bash \
    libcap \
    && rm -rf /var/cache/apk/*
RUN install-php-extensions gd pdo_mysql mysqli zip mbstring intl bcmath opcache

COPY docker/Caddyfile /etc/caddy/Caddyfile
WORKDIR /var/www/html
COPY . .
COPY --from=builder /app/vendor ./vendor
COPY docker/php.ini /usr/local/etc/php/conf.d/app-optimizations.ini
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Enable low-port binding for unprivileged user and set defensive permissions
RUN setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy /var/www/html/storage/logs /var/www/html/storage/reports /var/www/html/storage/backups \
    && chown -R www-data:www-data /data/caddy /config/caddy /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/storage

USER www-data

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=20s --retries=3 \
    CMD curl -f http://localhost:80/login.php || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
