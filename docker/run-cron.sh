#!/bin/bash
# Helper script to execute cron tasks inside the rootless container
# This script should be called by the host's crontab

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR"

# Execute the PHP cron command inside the 'app' container using rootless Podman
podman compose exec -T app php bin/purge-logs.php 30
