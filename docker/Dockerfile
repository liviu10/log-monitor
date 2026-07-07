# Etapa 1: Constructor de dependinte
FROM docker.io/library/composer:2 AS builder
WORKDIR /app

# Copiem configuratiile de dependinte
COPY composer.json composer.lock ./

# Rulam instalarea fara scripturi si generam autoload-ul optimizat direct aici
RUN composer install --no-dev --no-interaction --no-scripts --optimize-autoloader

# Etapa 2: Imaginea Finala de Productie
FROM docker.io/dunglas/frankenphp:php8.4-alpine

# Instalam pachetele de sistem stricte si curatam cache-ul apk in aceeasi rulare
RUN apk add --no-cache \
    curl \
    netcat-openbsd \
    tzdata \
    bash \
    && rm -rf /var/cache/apk/*

# Instalam extensiile PHP necesare
RUN install-php-extensions gd pdo_mysql mysqli zip mbstring intl bcmath opcache

# Copiem configurarile optimizate pe un singur rand (Corectat)
COPY docker/php.ini /usr/local/etc/php/conf.d/app-optimizations.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile

WORKDIR /var/www/html

# Copiem codul sursa al aplicatiei
COPY . .

# Copiem folderul vendor direct optimizat din builder (Fara Composer in imaginea finala)
COPY --from=builder /app/vendor ./vendor

# Pregatirea si inregistrarea fisierului Crontab (Corectat)
COPY docker/crontab /etc/crontabs/root

# Pregatirea si mutarea lina a scriptului de Entrypoint in PATH (Corectat)
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Setam permisiunile in mod defensiv (www-data este utilizatorul standard non-root)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p storage/logs storage/backups \
    && chown -R www-data:www-data storage

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
