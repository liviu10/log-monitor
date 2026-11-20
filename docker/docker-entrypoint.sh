#!/bin/sh
# ==============================================================================
# LogMonitor - container entrypoint (POSIX sh)
#   1. builds tmpfs .env from non-secret env vars + /run/secrets/*
#   2. waits for MariaDB
#   3. on the web container only: clears stale compiled views, runs migrations & seeders
# ==============================================================================
set -eu

APP_DIR=/var/www/html
ENV_FILE=/tmp/logmonitor.env
SECRETS_DIR=/run/secrets

write_env() {
    escaped=$(printf '%s' "$2" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\$/\\$/g')
    printf '%s="%s"\n' "$1" "$escaped" >> "$ENV_FILE"
}

sanitize_env_default() {
    case "$1" in
        \$\{*:-*\})
            val=${1#*:-}
            printf '%s' "${val%\}}"
            ;;
        \$\{*\})
            printf ''
            ;;
        *)
            printf '%s' "$1"
            ;;
    esac
}

umask 077
: > "$ENV_FILE"

env | grep -E '^(APP_|DB_|SMTP_|LOG_|QUEUE_|PROXY_|TZ=|FRANKENPHP_)' | while IFS= read -r line; do
    key=${line%%=*}
    value=${line#*=}
    value=${value#\"}; value=${value%\"}
    value=$(sanitize_env_default "$value")
    [ -n "$value" ] || continue
    write_env "$key" "$value"
done

FRANKENPHP_WORKERS=$(sanitize_env_default "${FRANKENPHP_WORKERS:-}")
case "$FRANKENPHP_WORKERS" in
    ''|*[!0-9]*) FRANKENPHP_WORKERS=4 ;;
esac
export FRANKENPHP_WORKERS

AUTO_MIGRATE=$(sanitize_env_default "${AUTO_MIGRATE:-true}")
AUTO_SEED=$(sanitize_env_default "${AUTO_SEED:-true}")
ADMIN_EMAIL=$(sanitize_env_default "${ADMIN_EMAIL:-}")
export ADMIN_EMAIL

for pair in app_key:APP_KEY db_password:DB_PASSWORD log_api_key:LOG_API_KEY smtp_password:SMTP_PASSWORD; do
    file="$SECRETS_DIR/${pair%%:*}"
    var=${pair#*:}
    if [ -r "$file" ]; then
        value=$(cat "$file")
        [ -n "$value" ] || continue
        sed -i "/^${var}=/d" "$ENV_FILE"
        write_env "$var" "$value"
    fi
done
umask 027

for required in APP_KEY DB_PASSWORD LOG_API_KEY; do
    if ! grep -q "^${required}=\"..*\"$" "$ENV_FILE"; then
        echo "[entrypoint] CRITICAL: $required is empty (re-run: sudo ./ops server-setup <user>)." >&2
        exit 1
    fi
done

if [ -z "${DB_HOST:-}" ] || [ -z "${DB_PORT:-}" ]; then
    echo "[entrypoint] CRITICAL: DB_HOST / DB_PORT not defined." >&2
    exit 1
fi

mkdir -p "$APP_DIR/storage/logs" "$APP_DIR/storage/views" "$APP_DIR/storage/cache" \
         "$APP_DIR/storage/sessions" "$APP_DIR/storage/framework/cache" "$APP_DIR/storage/commands" \
         "$APP_DIR/storage/profile" "$APP_DIR/storage/backups"
mkdir -p /tmp/opcache

i=0
until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT"), $e, $s, 1) ? 0 : 1);'; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] CRITICAL: database $DB_HOST:$DB_PORT unreachable after 60s." >&2
        exit 1
    fi
    sleep 1
done

if [ "${1:-}" = "frankenphp" ]; then
    rm -f "$APP_DIR"/storage/views/*.bladec "$APP_DIR"/storage/phinx_temp_*.php 2>/dev/null || true

    case "$(printf '%s' "${APP_ENV:-production}" | tr '[:upper:]' '[:lower:]')" in
        production|prod) PHINX_ENV=production ;;
        *)               PHINX_ENV=development ;;
    esac

    if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
        echo "[entrypoint] Migrations (phinx env: $PHINX_ENV)..."
        php "$APP_DIR/vendor/bin/phinx" migrate -c "$APP_DIR/config/phinx.php" -e "$PHINX_ENV"
    fi

    if [ "${AUTO_SEED:-true}" = "true" ]; then
        echo "[entrypoint] Seeders (phinx env: $PHINX_ENV)..."
        admin_password=""
        [ -r "$SECRETS_DIR/admin_password" ] && admin_password=$(cat "$SECRETS_DIR/admin_password")
        ADMIN_PASSWORD="$admin_password" \
            php "$APP_DIR/vendor/bin/phinx" seed:run -c "$APP_DIR/config/phinx.php" -e "$PHINX_ENV"
        unset admin_password
    fi
fi

unset ADMIN_EMAIL ADMIN_PASSWORD

exec "$@"
