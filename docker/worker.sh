#!/bin/sh
# ==============================================================================
# LogMonitor - notification queue worker + daily log/queue retention maintenance
#   * drains queue_jobs every QUEUE_POLL_SECONDS (default 5s)
#   * archives & purges old logs and queue jobs once per day at MAINTENANCE_HOUR
# ==============================================================================
set -u

cd /var/www/html || exit 1

POLL=${QUEUE_POLL_SECONDS:-5}
HOUR=${MAINTENANCE_HOUR:-03}
last_maintenance=""

trap 'echo "[worker] stopping"; exit 0' TERM INT

echo "[worker] started (poll=${POLL}s, maintenance at ${HOUR}:00)"

while :; do
    php logmonitor worker:run >/dev/null || echo "[worker] worker:run exited with $?" >&2

    today=$(date +%F)
    if [ "$(date +%H)" = "$HOUR" ] && [ "$last_maintenance" != "$today" ]; then
        echo "[worker] daily maintenance $today"
        php logmonitor logs:purge "${LOG_RETENTION_DAYS:-30}" "storage/backups" || true
        php logmonitor queue:purge "${QUEUE_PURGE_DAYS:-15}" || true
        find storage/framework/cache -type f -name '*.cache' -mmin +120 -delete 2>/dev/null || true
        last_maintenance=$today
    fi

    sleep "$POLL" &
    wait $!
done

