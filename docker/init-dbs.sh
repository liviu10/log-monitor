#!/bin/bash
set -e

if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "prod" ]; then
    echo "🛡️ Production environment detected. Skipping creation of dev database."
    exit 0
fi

echo "🛠️ Bootstrapping Local Development (Dev) database..."

# Automatically create the required databases and grant privileges
# relying strictly on the secure environment variables passed from .env
DB_NAME="${MYSQL_DATABASE:-${DB_DATABASE:-log_monitor}}"
DB_USER_NAME="${MYSQL_USER:-${DB_USERNAME:-user}}"

mariadb -u root -p"$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}_dev\`;
    GRANT ALL PRIVILEGES ON \`${DB_NAME}_dev\`.* TO '${DB_USER_NAME}'@'%';
    FLUSH PRIVILEGES;
EOSQL

echo "✅ Local database initialized successfully."
