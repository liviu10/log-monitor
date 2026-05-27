# Stage 1: Build dependencies
FROM docker.io/library/composer:2 AS builder
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-autoloader --no-scripts

# Stage 2: Final Image
FROM docker.io/dunglas/frankenphp:php8.4-alpine

# Install system packages
RUN apk add --no-cache \
    git \
    curl \
    netcat-openbsd \
    tzdata \
    cronie \
    bash

# Install PHP extensions (using the utility preinstalled in the image)
RUN install-php-extensions gd pdo_mysql mysqli zip mbstring intl bcmath opcache

# PHP and Caddy settings
COPY docker/php.ini /usr/local/etc/php/conf.d/app-optimizations.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile

WORKDIR /var/www/html

# Copy vendor from builder stage
COPY --from=builder /app/vendor ./vendor

# Copy source code
COPY . .

# Optimize autoload and keep composer for development purposes
COPY --from=builder /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev

# Permissions
RUN chown -R root:root /var/www/html && chmod -R 755 /var/www/html

# Setup Entrypoint and Cron
COPY docker/docker-entrypoint.sh /usr/local/bin/
COPY docker/crontab /var/www/html/docker/crontab
RUN chmod +x /usr/local/bin/docker-entrypoint.sh && mkdir -p storage/logs

EXPOSE 80
EXPOSE 443
EXPOSE 443/udp

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
