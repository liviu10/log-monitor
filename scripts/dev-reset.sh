#!/usr/bin/env bash
#
# Aegis Local Environment Reset Script
# Tears down containers, wipes local volumes, reboots the stack, runs database reset, and clears cache.
#

set -euo pipefail

# Text formatting
BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
RED="\033[0;31m"
BLUE="\033[0;34m"
NC="\033[0m" # No Color

COMPOSE_FILE="docker/docker-compose.yml"

echo -e "${BOLD}${BLUE}==========================================================${NC}"
echo -e "${BOLD}${BLUE}🧹 Aegis Local Environment Reset${NC}"
echo -e "${BOLD}${BLUE}==========================================================${NC}"

# Detect compose tool (prefer podman compose, fallback to docker compose)
if command -v podman >/dev/null 2>&1; then
    COMPOSE_CMD="podman compose -f ${COMPOSE_FILE}"
elif command -v docker >/dev/null 2>&1; then
    COMPOSE_CMD="docker compose -f ${COMPOSE_FILE}"
else
    echo -e "${RED}❌ Error: Neither 'podman' nor 'docker' was found on host.${NC}"
    exit 1
fi

echo -e "\n${BOLD}[1/4] Stopping containers and wiping volumes...${NC}"
$COMPOSE_CMD down -v
echo -e "${GREEN}✓ Containers stopped and volumes removed.${NC}"

echo -e "\n${BOLD}[2/4] Starting fresh container environment...${NC}"
$COMPOSE_CMD up -d
echo -e "${GREEN}✓ Containers booted in detached mode.${NC}"

echo -e "\n${BOLD}[3/4] Waiting for database service readiness...${NC}"
# Wait up to 30 seconds for MySQL to be responsive
MAX_TRIES=30
TRIES=0
until php aegis db:status >/dev/null 2>&1 || [ $TRIES -eq $MAX_TRIES ]; do
    sleep 1
    TRIES=$((TRIES + 1))
    echo -ne "   Waiting for database (${TRIES}/${MAX_TRIES}s)...\r"
done
echo ""

if [ $TRIES -eq $MAX_TRIES ]; then
    echo -e "${YELLOW}⚠️ Notice: Database took longer than expected to initialize. Proceeding with migration attempt...${NC}"
fi

echo -e "\n${BOLD}[4/4] Resetting database schema, running migrations & seeds...${NC}"
php aegis db:reset

echo -e "\n${BOLD}Clearing application cache...${NC}"
php aegis cache:clear

echo -e "\n${BOLD}${GREEN}==========================================================${NC}"
echo -e "${BOLD}${GREEN}✅ Local environment successfully rebuilt and reset!${NC}"
echo -e "   - Database tables migrated and seeded with initial data."
echo -e "   - Caches cleared."
echo -e "   - Access web app at: ${BOLD}http://localhost:8081${NC}"
echo -e "${BOLD}${GREEN}==========================================================${NC}"
