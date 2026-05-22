#!/bin/bash
set -e

# Asteapta pana cand baza de date este accesibila
echo "Waiting for database to be ready..."
until nc -z $DB_HOST $DB_PORT; do
  sleep 1
done
echo "Database is up!"

# Instaleaza dependintele daca folderul vendor lipseste sau este incomplet
if [ ! -d "vendor" ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --optimize-autoloader
fi

# Ruleaza migrarile
echo "Running database migrations..."
./vendor/bin/phinx migrate -e development

# Ruleaza seed-urile
echo "Running database seeds..."
./vendor/bin/phinx seed:run -e development

# Inregistreaza si porneste Cron
if [ -f "docker/crontab" ]; then
    echo "Registering cron jobs..."
    crontab docker/crontab
    # Pornim crond pe Alpine (cronie)
    crond
fi

# Porneste procesul principal
echo "Starting Application Server..."
exec "$@"
