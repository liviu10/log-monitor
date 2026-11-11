#!/bin/bash
set -e

echo "=== BOOT RECEPTION: Initialisation phase begins from entrypoint ==="

# Initialize a clean .env file at the root of the application
echo "Filtering and normalizing variables for the .env file..."
> /var/www/html/.env

# Filter strictly using a white-list based on the application prefixes.
env | grep -E '^(APP_|DB_|MYSQL_|SMTP_|LOG_|NIST_|TZ=)' | while read -r line; do
    key="${line%%=*}"
    value="${line#*=}"
    
    value="${value#\"}"
    value="${value%\"}"

    echo "${key}=\"${value}\"" >> /var/www/html/.env
done

# Apply strict security permissions for the environment file
chmod 600 /var/www/html/.env

# Strict security verification (Fail Fast)
if [ -z "$DB_HOST" ] || [ -z "$DB_PORT" ]; then
    echo "CRITICAL ERROR: DB_HOST or DB_PORT are not defined in the configuration."
    exit 1
fi

echo "Waiting for connection to the database service ($DB_HOST:$DB_PORT)..."
until nc -z "$DB_HOST" "$DB_PORT"; do
  sleep 1
done
echo "The MariaDB database is accessible and stable!"

# Execute migrations and seeder EXCLUSIVELY on the main web container (app)
if [ "$1" = "frankenphp" ]; then
    echo "Main container (app) detected. Starting SQL structure alignment..."

    # Purge stale compiled Blade views if present
    rm -f /var/www/html/storage/views/*.bladec
    
    if [ ! -f "./vendor/bin/phinx" ]; then
        echo "CRITICAL ERROR: Phinx executable not found in vendor."
        exit 1
    fi

    # Normalize environment: prod/production -> production, dev/development -> development
    CURRENT_ENV=${APP_ENV:-development}
    if [ "$CURRENT_ENV" = "production" ] || [ "$CURRENT_ENV" = "prod" ]; then
        ENV_TARGET="production"
    else
        ENV_TARGET="development"
    fi

    echo "Running active migrations for environment: $ENV_TARGET"
    ./vendor/bin/phinx migrate -c db/phinx.php -e "$ENV_TARGET"
    
    if [ "$ENV_TARGET" = "development" ]; then
        echo "Development environment active. Running seed packages..."
        ./vendor/bin/phinx seed:run -c db/phinx.php -e "$ENV_TARGET"
    fi
fi

echo "Starting the main process requested by the service: $@"
exec "$@"
