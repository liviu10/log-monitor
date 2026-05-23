#!/bin/bash
# Activeaza modul Fail-Fast: opreste executia la prima eroare
set -e

echo "Validare variabile de mediu esentiale..."
if [ -z "$DB_HOST" ] || [ -z "$DB_PORT" ]; then
  echo "EROARE: DB_HOST sau DB_PORT nu sunt definite. Oprire imediata."
  exit 1
fi

echo "Se asteapta accesibilitatea bazei de date la $DB_HOST:$DB_PORT..."
until nc -z -w 2 "$DB_HOST" "$DB_PORT"; do
  sleep 1
done
echo "Baza de date este pregatita!"

# Instaleaza dependintele doar daca directorul vendor lipseste
if [ ! -d "vendor" ]; then
    echo "Instalare dependinte composer..."
    composer install --no-interaction --optimize-autoloader --no-dev
fi

# Rulare migrari in mediu securizat
echo "Se ruleaza migrarile bazei de date..."
./vendor/bin/phinx migrate -e production

# Inregistrare joburi cron in container daca fisierul exista
if [ -f "docker/crontab" ]; then
    echo "Inregistrare joburi cron..."
    crontab docker/crontab
    # Pornire crond in background, cu trimitere loguri la sistem
    crond -b -S
fi

echo "Pornire Server Aplicatie..."
exec "$@"