#!/bin/bash
set -e

# Wait for the database to be accessible
echo "Waiting for database to be ready..."
until nc -z $DB_HOST $DB_PORT; do
  sleep 1
done
echo "Database is up!"

# Install dependencies if vendor folder is missing or if we are in development and need dev dependencies
if [ ! -d "vendor" ]; then
    echo "Installing/Updating composer dependencies..."
    if [ "$APP_ENV" = "development" ]; then
        composer update --no-interaction
    else
        composer install --no-interaction --optimize-autoloader --no-dev
    fi
elif [ "$APP_ENV" = "development" ] && [ ! -f "vendor/bin/phpunit" ]; then
    echo "Installing development dependencies..."
    composer update --no-interaction
fi

# Run migrations
echo "Running database migrations..."
./vendor/bin/phinx migrate -e development

# Run seeds
echo "Running database seeds..."
./vendor/bin/phinx seed:run -e development

# Register and start cron
if [ -f "docker/crontab" ]; then
    echo "Registering cron jobs..."
    crontab docker/crontab
    # Start crond on Alpine (cronie)
    crond
fi

# Start application server
echo "Starting Application Server..."
exec "$@"
