# Stage 1: Build dependencies
FROM docker.io/library/composer:2 AS builder
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-autoloader --no-scripts

# Stage 2: Final Image
FROM docker.io/dunglas/frankenphp:latest-alpine

# Instalam pachetele de sistem necesare
RUN apk add --no-cache \
    git \
    curl \
    netcat-openbsd \
    tzdata \
    cronie \
    bash

# Instalam extensiile PHP (folosind utilitarul preinstalat in imagine)
RUN install-php-extensions gd pdo_mysql mysqli zip mbstring intl bcmath opcache

# Setari PHP si Caddy
COPY docker/php.ini /usr/local/etc/php/conf.d/app-optimizations.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile

WORKDIR /var/www/html

# Copiem vendor-ul generat proaspat in etapa builder
COPY --from=builder /app/vendor ./vendor

# Copiem codul sursa
COPY . .

# Optimizare autoload
COPY --from=builder /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev && rm /usr/bin/composer

# Permisiuni
RUN chown -R root:root /var/www/html && chmod -R 755 /var/www/html

# Setup Entrypoint si Cron
COPY docker/docker-entrypoint.sh /usr/local/bin/
COPY docker/crontab /var/www/html/docker/crontab
RUN chmod +x /usr/local/bin/docker-entrypoint.sh && mkdir -p storage/logs

EXPOSE 80
EXPOSE 443
EXPOSE 443/udp

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
