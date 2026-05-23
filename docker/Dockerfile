# Stage 1: Build dependencies
FROM docker.io/library/composer:2 AS builder
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-autoloader --no-scripts

# Stage 2: Final Image
FROM docker.io/dunglas/frankenphp:latest-alpine

# Instalare pachete sistem necesare
RUN apk add --no-cache \
    git \
    curl \
    netcat-openbsd \
    tzdata \
    cronie \
    bash

# Instalare extensii PHP sigure
RUN install-php-extensions gd pdo_mysql mysqli zip mbstring intl bcmath opcache

# Configurații PHP si Caddy
COPY docker/php.ini /usr/local/etc/php/conf.d/app-optimizations.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile

WORKDIR /var/www/html

# Copiere dependinte din builder stage
COPY --from=builder /app/vendor ./vendor

# Copiere cod sursa aplicatie
COPY . .

# Optimizare autoload
COPY --from=builder /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev && rm /usr/bin/composer

# Configurare directoare si permisiuni stricte pentru non-root user
RUN mkdir -p storage/logs && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 555 /var/www/html && \
    chmod -R 775 /var/www/html/storage

# Setup Entrypoint si Cron
COPY docker/docker-entrypoint.sh /usr/local/bin/
COPY docker/crontab /var/www/html/docker/crontab
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
EXPOSE 443
EXPOSE 443/udp

# Schimbare utilizator pentru rulare sigura rootless
USER www-data

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]