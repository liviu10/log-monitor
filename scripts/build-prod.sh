#!/usr/bin/env bash
#
# LogMonitor Production Container Image Packaging Script
# Builds, audits, and exports the production container image for Debian deployment.
#

set -euo pipefail

# Text formatting
BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
RED="\033[0;31m"
BLUE="\033[0;34m"
NC="\033[0m" # No Color

echo -e "${BOLD}${BLUE}==========================================================${NC}"
echo -e "${BOLD}${BLUE}🚢 LogMonitor Production Packaging: FrankenPHP & PHP 8.5${NC}"
echo -e "${BOLD}${BLUE}==========================================================${NC}"

# Detect container engine (prefer podman, fallback to docker)
if command -v podman >/dev/null 2>&1; then
    CONTAINER_CLI="podman"
elif command -v docker >/dev/null 2>&1; then
    CONTAINER_CLI="docker"
else
    echo -e "${RED}❌ Error: Neither 'podman' nor 'docker' was found on host.${NC}"
    exit 1
fi
echo -e "Using container engine: ${BOLD}${CONTAINER_CLI}${NC}"

# 1. Audit Dockerfile for security misconfigurations
echo -e "\n${BOLD}[1/4] Auditing Dockerfile with Trivy...${NC}"
if command -v trivy >/dev/null 2>&1; then
    trivy config --severity MEDIUM,HIGH,CRITICAL --exit-code 1 docker/Dockerfile
    echo -e "${GREEN}✓ Dockerfile passed security configuration audit.${NC}"
else
    echo -e "${YELLOW}⚠️ Notice: Trivy not found. Skipping Dockerfile security audit.${NC}"
fi

# 2. Build production image with FrankenPHP & PHP 8.5
IMAGE_TAG="log-monitor-app:latest"
echo -e "\n${BOLD}[2/4] Building production container image '${IMAGE_TAG}'...${NC}"
$CONTAINER_CLI build -t "$IMAGE_TAG" -f docker/Dockerfile .
echo -e "${GREEN}✓ Production image '${IMAGE_TAG}' built successfully.${NC}"

# 3. Audit built image for vulnerabilities
echo -e "\n${BOLD}[3/4] Auditing built image with Trivy...${NC}"
if command -v trivy >/dev/null 2>&1; then
    trivy image --scanners vuln --severity MEDIUM,HIGH,CRITICAL --exit-code 1 "$IMAGE_TAG"
    echo -e "${GREEN}✓ Built container image passed vulnerability scan.${NC}"
else
    echo -e "${YELLOW}⚠️ Notice: Trivy not found. Skipping built image vulnerability scan.${NC}"
fi

# 4. Export image to compressed archive and generate sha256 checksum
ARCHIVE_NAME="log-monitor-app.tar.gz"
echo -e "\n${BOLD}[4/4] Exporting image to '${ARCHIVE_NAME}' and generating SHA256 checksum...${NC}"
$CONTAINER_CLI save "$IMAGE_TAG" | gzip > "$ARCHIVE_NAME"
sha256sum "$ARCHIVE_NAME" > "${ARCHIVE_NAME}.sha256"

ARCHIVE_SIZE=$(du -h "$ARCHIVE_NAME" | cut -f1)
echo -e "${GREEN}✓ Exported '${ARCHIVE_NAME}' (${ARCHIVE_SIZE}).${NC}"
echo -e "${GREEN}✓ Generated '${ARCHIVE_NAME}.sha256'.${NC}"

echo -e "\n${BOLD}${GREEN}==========================================================${NC}"
echo -e "${BOLD}${GREEN}✅ Production packaging complete!${NC}"
echo -e "To deploy to Debian server:"
echo -e "  1. scp ${ARCHIVE_NAME} ${ARCHIVE_NAME}.sha256 user@server:/var/www/log-monitor.insightdevhub.ro/"
echo -e "  2. ssh user@server 'podman load -i /var/www/log-monitor.insightdevhub.ro/${ARCHIVE_NAME}'"
echo -e "  3. ssh user@server 'cd /var/www/log-monitor.insightdevhub.ro/docker && podman compose up -d'"
echo -e "${BOLD}${GREEN}==========================================================${NC}"
