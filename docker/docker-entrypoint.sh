#!/bin/bash
set -e

# Wait for the database to be accessible
echo "Waiting for database to be ready..."
until nc -z $DB_HOST $DB_PORT; do
  sleep 1
done
echo "Database is up!"

# Install dependencies if vendor folder is missing or incomplete
if [ ! -d "vendor" ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --optimize-autoloader
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
