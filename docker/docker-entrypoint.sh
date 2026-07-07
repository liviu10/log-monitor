#!/bin/bash
set -e

echo "=== RECEPTIE BOOT: Incepe faza de initializare din entrypoint ==="

# Solutia Arhitecturala Finisata:
# Initializam un fisier .env curat la radacina aplicatiei
echo "Filtram si normalizam variabilele destinate fisierului .env..."
> /var/www/html/.env

# Filtram strict folosind o lista alba (white-list) bazata pe prefixele aplicatiei LogMonitor.
# Astfel eliminam 100% din cheile parazite de SSH/GPG transmise de Podman rootless.
env | grep -E '^(APP_|DB_|MYSQL_|SMTP_|LOG_|NIST_|TZ=)' | while read -r line; do
    # Extragem cheia si valoarea in mod securizat la nivel de shell
    key="${line%%=*}"
    value="${line#*=}"
    
    # Programare defensiva: Eliminam eventualele ghilimele exterioare 
    # daca ele au fost deja interpretate sau pastrate in bufferul de memorie
    value="${value#\"}"
    value="${value%\"}"
    
    # Scriem variabila impachetata uniform si sigur pentru parserul de PHP 8.4
    echo "${key}=\"${value}\"" >> /var/www/html/.env
done

# Aplicam permisiunile de securitate pentru utilizatorul de FrankenPHP
chown www-data:www-data /var/www/html/.env

# Verificare stricta de siguranta (Fail Fast)
if [ -z "$DB_HOST" ] || [ -z "$DB_PORT" ]; then
    echo "EROARE CRITICA: DB_HOST sau DB_PORT nu sunt definite in configuratie."
    exit 1
fi

echo "Se asteapta conexiunea cu serviciul de baza de date ($DB_HOST:$DB_PORT)..."
until nc -z "$DB_HOST" "$DB_PORT"; do
  sleep 1
done
echo "Baza de date MariaDB este accesibila si stabila!"

# Executam migratiile si seederul EXCLUSIV pe containerul web principal (app)
if [ "$1" = "frankenphp" ]; then
    echo "Container principal (app) detectat. Pornim alinierea structurii SQL..."
    
    if [ ! -f "./vendor/bin/phinx" ]; then
        echo "EROARE CRITICA: Executabilul Phinx nu a fost gasit in vendor."
        exit 1
    fi

    ENV_TARGET=${APP_ENV:-development}
    echo "Rulam migratiile active pentru mediul: $ENV_TARGET"
    
    # Solutia: Adaugam flag-ul -c phinx.php pentru a forta utilizarea lui si a scoate la suprafata erorile ascunse
    ./vendor/bin/phinx migrate -c phinx.php -e "$ENV_TARGET"
    
    if [ "$APP_ENV" = "development" ]; then
        echo "Mediu de dezvoltare activ. Rulam pachetele de seed..."
        ./vendor/bin/phinx seed:run -c phinx.php -e "$ENV_TARGET"
    fi
fi

echo "Pornim procesul principal solicitat de serviciu: $@"
exec "$@"
