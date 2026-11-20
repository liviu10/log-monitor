# syntax=docker/dockerfile:1
# ==============================================================================
# LogMonitor - production image (FrankenPHP + PHP 8.5, Alpine)
#   * base images pinned by digest
#   * only required PHP extensions (pdo_mysql, zip)
#   * no dev dependencies, authoritative classmap
#   * application code owned by root and read-only for runtime user
#   * runs unprivileged on port 8080 (no capabilities needed)
# ==============================================================================

FROM docker.io/library/composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca AS composer

FROM docker.io/dunglas/frankenphp:1-php8.5-alpine@sha256:a7a2edb7ae75b5be7c366ca2ab4bc23f3940bb5ac9f89f5d0e4296815f75f6de AS base
RUN apk upgrade --no-cache \
    && install-php-extensions pdo_mysql zip \
    && rm -rf /var/cache/apk/* /tmp/* /usr/local/lib/php/doc /usr/local/lib/php/test

FROM base AS vendor
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY src/ src/
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

FROM docker.io/library/alpine:3@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf AS assets
RUN apk add --no-cache zstd
COPY public/ /public/
RUN find /public/assets -type f -size +1k \
        \( -name '*.css' -o -name '*.js' -o -name '*.svg' -o -name '*.json' -o -name '*.ttf' -o -name '*.map' \) \
        -exec sh -c 'for f; do gzip -9 -n -c "$f" > "$f.gz" && zstd -19 -q "$f" -o "$f.zst"; done' _ {} +

FROM base AS runtime

WORKDIR /var/www/html

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --chmod=0755 docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY --chmod=0755 docker/worker.sh /usr/local/bin/queue-worker

COPY --from=vendor /app/vendor ./vendor
COPY logmonitor composer.json ./
COPY bin/ ./bin/
COPY config/ ./config/
COPY db/ ./db/
COPY lang/ ./lang/
COPY --from=assets /public/ ./public/
COPY resources/ ./resources/
COPY routes/ ./routes/
COPY src/ ./src/

RUN mkdir -p storage/logs storage/views storage/cache storage/sessions \
        storage/framework/cache storage/commands storage/profile storage/backups \
        /data/caddy /config/caddy \
    && ln -sf /tmp/logmonitor.env /var/www/html/.env \
    && chown -R www-data:www-data storage /data/caddy /config/caddy \
    && chmod -R u=rwX,g=rX,o= storage

USER www-data

EXPOSE 8080
STOPSIGNAL SIGTERM

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
