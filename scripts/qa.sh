#!/usr/bin/env bash
#
# LogMonitor Full Quality Assurance & Test Runner
# Executes all 6 CI/CD validation gates locally.
#

set -uo pipefail

# Text formatting
BOLD="\033[1m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
RED="\033[0;31m"
BLUE="\033[0;34m"
CYAN="\033[0;36m"
NC="\033[0m" # No Color

AUTO_FIX=false
QUICK_MODE=false

for arg in "$@"; do
    case "$arg" in
        --fix)
            AUTO_FIX=true
            ;;
        --quick)
            QUICK_MODE=true
            ;;
        --help|-h)
            echo -e "${BOLD}Usage:${NC} ./scripts/qa.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --fix     Automatically fix code style issues using Laravel Pint"
            echo "  --quick   Skip container security audit and run core test suites"
            echo "  --help    Show this help message"
            exit 0
            ;;
    esac
done

echo -e "${BOLD}${BLUE}==========================================================${NC}"
echo -e "${BOLD}${BLUE}🧪 LogMonitor Local Quality Assurance & Test Suite${NC}"
echo -e "${BOLD}${BLUE}==========================================================${NC}"

START_TIME=$(date +%s)
FAILED_STEPS=()

# Helper function to track step results
run_step() {
    local step_num="$1"
    local step_name="$2"
    local step_cmd="$3"

    echo -e "\n${BOLD}${CYAN}[${step_num}/6] ${step_name}...${NC}"
    local step_start=$(date +%s)

    if eval "$step_cmd"; then
        local step_end=$(date +%s)
        local step_duration=$((step_end - step_start))
        echo -e "${GREEN}✓ ${step_name} passed (${step_duration}s).${NC}"
    else
        local step_end=$(date +%s)
        local step_duration=$((step_end - step_start))
        echo -e "${RED}✗ ${step_name} failed (${step_duration}s)!${NC}"
        FAILED_STEPS+=("${step_name}")
    fi
}

# 1. Code style verification (PSR-12 via Laravel Pint)
if [ "$AUTO_FIX" = true ]; then
    run_step "1" "Code Style Formatting (Pint auto-fix)" "vendor/bin/pint"
else
    run_step "1" "Code Style Verification (Laravel Pint)" "vendor/bin/pint --test"
fi

# 2. Dependency security audit (Composer Audit)
run_step "2" "Dependency Security Audit (Composer Audit)" "composer audit --locked --ignore-unreachable"

# 3. Dockerfile configuration security audit (Trivy Config)
if [ "$QUICK_MODE" = true ]; then
    echo -e "\n${YELLOW}[3/6] Skipping Container Security Audit (--quick mode enabled).${NC}"
else
    if command -v trivy >/dev/null 2>&1; then
        run_step "3" "Dockerfile Security Audit (Trivy)" \
            "trivy config --severity LOW,MEDIUM,HIGH,CRITICAL --exit-code 1 docker/Dockerfile"
    else
        echo -e "\n${YELLOW}[3/6] Trivy not found on host. Skipping container security audit.${NC}"
    fi
fi

# 4. Static type analysis (PHPStan Level 10)
run_step "4" "Static Type Analysis (PHPStan Level 10)" "php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon -l 10"

# 5. Core Unit & Feature test suite with coverage
run_step "5" "Core Test Suite (PHPUnit)" \
    "php -d opcache.enable_cli=0 -d open_basedir= vendor/bin/phpunit"

# 6. Repository vulnerability & secret scan (Trivy Filesystem)
if [ "$QUICK_MODE" = true ]; then
    echo -e "\n${YELLOW}[6/6] Skipping Filesystem Security Audit (--quick mode enabled).${NC}"
else
    if command -v trivy >/dev/null 2>&1; then
        run_step "6" "Repository Security & Secret Audit (Trivy FS)" \
            "trivy fs --severity LOW,MEDIUM,HIGH,CRITICAL --exit-code 1 ."
    else
        echo -e "\n${YELLOW}[6/6] Trivy not found on host. Skipping filesystem security audit.${NC}"
    fi
fi

# Summary Report
TOTAL_END=$(date +%s)
TOTAL_DURATION=$((TOTAL_END - START_TIME))

echo -e "\n${BOLD}${BLUE}==========================================================${NC}"
echo -e "${BOLD}QA Summary Report (Total Time: ${TOTAL_DURATION}s):${NC}"

if [ ${#FAILED_STEPS[@]} -eq 0 ]; then
    echo -e "${BOLD}${GREEN}✅ ALL QA GATES PASSED! The codebase is in pristine condition.${NC}"
    echo -e "${BOLD}${BLUE}==========================================================${NC}"
    exit 0
else
    echo -e "${BOLD}${RED}❌ QA GATES FAILED (${#FAILED_STEPS[@]} failures detected):${NC}"
    for failed in "${FAILED_STEPS[@]}"; do
        echo -e "   - ${RED}${failed}${NC}"
    done
    echo -e "\nPlease resolve the issues above before committing or merging."
    echo -e "${BOLD}${BLUE}==========================================================${NC}"
    exit 1
fi
