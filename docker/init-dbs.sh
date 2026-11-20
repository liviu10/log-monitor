#!/bin/bash
set -e

if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "prod" ]; then
    echo "🛡️ Production environment detected. Skipping creation of dev database."
    exit 0
fi

echo "🛠️ Bootstrapping Local Development (Dev) database..."

# In local development mode, MariaDB automatically initializes $DB_DATABASE.
# This script additionally creates the ${DB_DATABASE}_dev database and grants permissions.
ROOT_PW="${MARIADB_ROOT_PASSWORD:-$(cat /run/secrets/db_root_password 2>/dev/null || true)}"
MYSQL_PWD="$ROOT_PW" mariadb -u root <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}_dev\`;
    GRANT ALL PRIVILEGES ON \`${DB_DATABASE}_dev\`.* TO '$DB_USERNAME'@'%';
    FLUSH PRIVILEGES;
EOSQL

echo "✅ Local development database initialized successfully."
