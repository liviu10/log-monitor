#!/usr/bin/env bash
# ==============================================================================
# LogMonitor - Unified Local Workstation Manager (scripts/local.sh)
#
# Usage: ./ops <command> [options]   (or ./scripts/local.sh <command> [options])
# ==============================================================================
set -Eeuo pipefail

# Guard: Run ONLY on local development workstation (refuse on Debian server)
if [ -f /etc/os-release ] && grep -qi '^ID=.*debian' /etc/os-release; then
    echo -e "\033[0;31m❌ Error: scripts/local.sh is strictly for local development and cannot run on the Debian server.\033[0m" >&2
    exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

BOLD="\033[1m"; GREEN="\033[0;32m"; YELLOW="\033[0;33m"; RED="\033[0;31m"; BLUE="\033[0;34m"; CYAN="\033[0;36m"; NC="\033[0m"
LOCAL_ENV_FILE=".env.testing"
COMPOSE_FILES=(--env-file "$ROOT/$LOCAL_ENV_FILE" -f docker-compose.yml -f compose.dev.yml)

TMP_WORK_DIR=""
cleanup_all() {
    local rc=$?
    if [ -n "$TMP_WORK_DIR" ] && [ -d "$TMP_WORK_DIR" ]; then
        rm -rf "$TMP_WORK_DIR" 2>/dev/null || true
    fi
    return "$rc"
}
trap cleanup_all EXIT INT TERM

ensure_tmp_dir() {
    if [ -z "$TMP_WORK_DIR" ] || [ ! -d "$TMP_WORK_DIR" ]; then
        TMP_WORK_DIR="$(mktemp -d)"
    fi
}

usage() {
    cat <<'EOF'

  LogMonitor — Local Workstation Operations (./ops <command> [options])

  COMMANDS
    up [OPTIONS]            Verify prerequisites (.env.testing, local secrets,
                            vendor/) and start the local Podman dev stack.
      --fresh               Rebuild images without cache before starting.

    publish [OPTIONS]       Run Git pre-flight checks, all QA gates (Pint
                            first, parallel static/unit/audit checks, Trivy
                            last), then sync 'dev' -> 'origin/master'.
      --fresh               Force full --no-cache container build for Trivy.
      --coverage            Enable PCOV code coverage during PHPUnit.
      --skip-trivy          Skip container image build & Trivy security scan.

    reset [OPTIONS]         DESTRUCTIVE (dev only): tear down containers, wipe
                            local DB/storage volumes, ensure local secrets,
                            rebuild images, and start a clean dev environment.
      --fresh               Rebuild images without cache (--pull --no-cache).

    status                  Show local container status (app, log-worker, worker, db).

    logs [SERVICE...]       Follow live container logs (default: all services,
                            or specify e.g. 'app', 'log-worker', 'worker', 'db').

    help, -h, --help        Display this help message.

  EXAMPLES
    ./ops up                Ensure prerequisites and start local environment
    ./ops publish           Run fast QA gates and publish 'dev' -> 'master'
    ./ops publish --fresh   Run QA with from-scratch container build
    ./ops reset --fresh     Wipe local volumes and rebuild from scratch

EOF
}

require_podman() {
    command -v podman >/dev/null 2>&1 || {
        echo -e "${RED}❌ Error: 'podman' was not found on host. Podman is required.${NC}" >&2
        exit 1
    }
}

env_value() {
    grep -E "^$1=" "$ROOT/$LOCAL_ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

ensure_local_deps() {
    command -v php >/dev/null 2>&1 || { echo -e "${RED}❌ Error: 'php' is not installed on host.${NC}" >&2; exit 1; }
    command -v composer >/dev/null 2>&1 || { echo -e "${RED}❌ Error: 'composer' is not installed on host.${NC}" >&2; exit 1; }

    if [ ! -f "$ROOT/vendor/autoload.php" ]; then
        echo -e "${YELLOW}→ Installing root Composer dependencies...${NC}"
        composer install --no-interaction --no-progress --prefer-dist
    fi
}

ensure_local_env() {
    local env_path="$ROOT/$LOCAL_ENV_FILE"
    if [ ! -f "$env_path" ]; then
        echo -e "${YELLOW}→ $LOCAL_ENV_FILE missing — creating from .env.example...${NC}"
        cp "$ROOT/.env.example" "$env_path"
        sed -i 's/^APP_ENV=.*/APP_ENV=testing/' "$env_path"
        sed -i 's|^APP_URL=.*|APP_URL="http://localhost:8082"|' "$env_path"
        sed -i 's|^LOG_SERVER_URL=.*|LOG_SERVER_URL="http://127.0.0.1:8082/api/log.php"|' "$env_path"
        chmod 600 "$env_path"
    fi

    if grep -qiE '^APP_ENV="?(production|prod)"?' "$env_path" 2>/dev/null; then
        echo -e "${RED}❌ Refusing local container operation: $LOCAL_ENV_FILE has APP_ENV=production.${NC}" >&2
        exit 1
    fi
}

ensure_local_secrets() {
    mkdir -p "$ROOT/secrets"
    local s
    for s in app_key db_password db_root_password log_api_key smtp_password admin_password; do
        if [ ! -s "$ROOT/secrets/$s" ]; then
            if [ "$s" = "log_api_key" ]; then
                printf '%s' "dev-local-log-key" > "$ROOT/secrets/$s"
            else
                openssl rand -hex 16 > "$ROOT/secrets/$s"
            fi
            chmod 644 "$ROOT/secrets/$s"
            echo -e "${YELLOW}→ Created local placeholder secret: secrets/$s${NC}"
        fi
    done
}

is_app_healthy() {
    local port="$1"
    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 1 "http://127.0.0.1:${port}/healthz" >/dev/null 2>&1 && return 0
    fi
    podman compose "${COMPOSE_FILES[@]}" exec -T app wget -q -O /dev/null http://127.0.0.1:8080/healthz 2>/dev/null
}

wait_for_healthz() {
    local compose=(podman compose "${COMPOSE_FILES[@]}")
    local port
    port="$(env_value APP_PORT)"
    port="${port:-8082}"

    for _ in $(seq 1 120); do
        if is_app_healthy "$port"; then
            echo -e "${GREEN}✓ Local environment is up and healthy.${NC}"
            echo -e "   App: ${BOLD}http://localhost:${port}${NC}"
            return 0
        fi
        sleep 1
    done

    echo -e "${RED}❌ App did not become ready in time. Recent logs:${NC}" >&2
    "${compose[@]}" logs --tail 50 app || true
    return 1
}

cmd_up() {
    local fresh=false
    while [ $# -gt 0 ]; do
        case "$1" in
            --fresh) fresh=true ;;
            --help|-h) usage; exit 0 ;;
            *) echo -e "${RED}Unknown option for 'up': $1${NC}" >&2; usage; exit 1 ;;
        esac
        shift
    done

    require_podman
    ensure_local_deps
    ensure_local_env
    ensure_local_secrets

    local compose=(podman compose "${COMPOSE_FILES[@]}")
    local port
    port="$(env_value APP_PORT)"
    port="${port:-8082}"

    if ! $fresh && is_app_healthy "$port"; then
        echo -e "${GREEN}✓ Local Podman stack is already running and healthy.${NC}"
        echo -e "   App: ${BOLD}http://localhost:${port}${NC}"
        return 0
    fi

    echo -e "${BOLD}${BLUE}🚀 Starting LogMonitor local development stack...${NC}"
    if $fresh; then
        "${compose[@]}" build --pull --no-cache
        "${compose[@]}" up -d
    else
        "${compose[@]}" up -d --build
    fi
    wait_for_healthz
}

check_git_preflight() {
    if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo -e "${RED}❌ Error: Must be executed inside the git repository.${NC}" >&2
        exit 1
    fi

    local current_branch
    current_branch=$(git rev-parse --abbrev-ref HEAD)
    if [ "$current_branch" != "dev" ]; then
        echo -e "${RED}❌ Error: Must be on the 'dev' branch to publish (current branch: ${current_branch}).${NC}" >&2
        echo "   Run 'git checkout dev' first." >&2
        exit 1
    fi

    if [ -n "$(git status --porcelain)" ]; then
        echo -e "${RED}❌ Error: Uncommitted changes detected in working tree.${NC}" >&2
        echo "   Commit or stash your changes before publishing." >&2
        git status -s
        exit 1
    fi

    git fetch origin dev master --quiet 2>/dev/null || git fetch origin --quiet
    if git show-ref --verify --quiet refs/remotes/origin/master \
        && ! git merge-base --is-ancestor origin/master HEAD; then
        echo -e "${RED}❌ Error: 'origin/master' has commits not yet merged into 'dev'.${NC}" >&2
        echo "   Run 'git merge origin/master' on 'dev' first." >&2
        exit 1
    fi
}

run_git_sync() {
    echo -e "\n${BOLD}${BLUE}==========================================================${NC}"
    echo -e "${BOLD}${BLUE}🔄 LogMonitor Branch Synchronization: dev ➔ master${NC}"
    echo -e "${BOLD}${BLUE}==========================================================${NC}"

    if [ -n "$(git status --porcelain)" ]; then
        echo -e "${RED}❌ Error: Working tree became dirty during QA. Commit changes first.${NC}" >&2
        git status -s
        exit 1
    fi

    echo -e "\n${BOLD}[1/3] Pushing 'dev' to 'origin/dev'...${NC}"
    git push origin dev
    echo -e "${GREEN}✓ 'dev' branch is up to date on remote.${NC}"

    local use_gh=false
    if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
        use_gh=true
    fi

    if [ "$use_gh" = true ]; then
        echo -e "\n${BOLD}[2/3] Managing GitHub Pull Request with Assignee '@me'...${NC}"
        local existing_pr pr_number latest_commit_msg pr_url
        existing_pr=$(gh pr list --head dev --base master --state open --json number --jq '.[0].number' 2>/dev/null || true)

        if [ -n "$existing_pr" ] && [ "$existing_pr" != "null" ]; then
            echo "   Found existing open PR #${existing_pr}."
            pr_number="$existing_pr"
            gh pr edit "$pr_number" --add-assignee "@me" >/dev/null 2>&1 || true
        else
            latest_commit_msg=$(git log -1 --pretty=%s)
            echo "   Creating new Pull Request assigned to @me..."
            pr_url=$(gh pr create \
                --base master \
                --head dev \
                --title "Merge dev into master: ${latest_commit_msg}" \
                --body "Automated branch promotion from \`dev\` into \`master\`." \
                --assignee "@me")
            echo -e "${GREEN}✓ Created Pull Request: ${pr_url}${NC}"
            pr_number=$(gh pr view --json number --jq '.number')
        fi

        echo -e "\n${BOLD}[3/3] Merging Pull Request #${pr_number} on GitHub and syncing 'dev'...${NC}"
        gh pr merge "$pr_number" --merge --delete-branch=false
        git fetch origin master --quiet
        git merge --no-edit origin/master
        git push origin dev
        echo -e "${GREEN}✓ Pull Request #${pr_number} merged and 'dev' synced.${NC}"
    else
        echo -e "\n${BOLD}[2/3] Promoting 'dev' directly to 'origin/master' (staying on local 'dev')...${NC}"
        git push origin dev:master
        echo -e "\n${BOLD}[3/3] Verifying local branch remains on 'dev'...${NC}"
        echo -e "${GREEN}✓ 'origin/master' updated directly from 'dev'.${NC}"
    fi

    echo -e "\n${BOLD}${GREEN}==========================================================${NC}"
    echo -e "${BOLD}${GREEN}✅ Publish complete!${NC}"
    echo -e "   - Local branch remains on 'dev'; 'origin/master' is updated for production."
    echo -e "   - Locally, run: ${BOLD}./ops up${NC} (start local environment)"
    echo -e "   - On the Debian server, run: ${BOLD}./ops deploy${NC}"
    echo -e "${BOLD}${GREEN}==========================================================${NC}"
}

run_composer_security_audit() {
    echo "Auditing root composer.lock..."
    composer audit --locked --ignore-unreachable
}

run_trivy_container_audit() {
    local fresh_build="${1:-false}"
    require_podman

    trivy config --severity MEDIUM,HIGH,CRITICAL --exit-code 1 docker/Dockerfile

    local scan_image="log-monitor-app:latest"
    local build_args=(--pull -f docker/Dockerfile -t "$scan_image" .)
    if [ "$fresh_build" = true ]; then
        echo "Building clean image from scratch (--pull --no-cache) as $scan_image..."
        build_args=(--pull --no-cache -f docker/Dockerfile -t "$scan_image" .)
    else
        echo "Building image (with layer cache, checking base image updates) as $scan_image..."
    fi

    podman build "${build_args[@]}"

    ensure_tmp_dir
    local podman_log="$TMP_WORK_DIR/trivy-podman.log"
    local rc=0
    trivy image --image-src podman --scanners vuln --severity MEDIUM,HIGH,CRITICAL --exit-code 2 "$scan_image" >"$podman_log" 2>&1 || rc=$?

    if [ "$rc" -eq 0 ] || [ "$rc" -eq 2 ]; then
        cat "$podman_log"
        [ "$rc" -eq 2 ] && rc=1
    else
        local scan_tar="$TMP_WORK_DIR/trivy-scan.tar"
        podman save -o "$scan_tar" "$scan_image"
        rc=0
        trivy image --scanners vuln --severity MEDIUM,HIGH,CRITICAL --exit-code 1 --input "$scan_tar" || rc=$?
        rm -f "$scan_tar"
    fi

    podman image prune -f >/dev/null 2>&1 || true
    return "$rc"
}

run_bg_gate() {
    local out_log="$1" time_file="$2"
    shift 2
    local t0 t1
    t0=$(date +%s)
    if "$@" >"$out_log" 2>&1; then
        t1=$(date +%s)
        echo $((t1 - t0)) > "$time_file"
        return 0
    else
        t1=$(date +%s)
        echo $((t1 - t0)) > "$time_file"
        return 1
    fi
}

run_qa_gates() {
    local fresh_build="${1:-false}"
    local with_coverage="${2:-false}"
    local skip_trivy="${3:-false}"

    ensure_local_deps
    ensure_tmp_dir

    echo -e "${BOLD}${BLUE}==========================================================${NC}"
    echo -e "${BOLD}${BLUE}🧪 LogMonitor Local Quality Assurance & Test Suite${NC}"
    echo -e "${BOLD}${BLUE}==========================================================${NC}"

    local start_time failed_steps=()
    start_time=$(date +%s)

    echo -e "\n${BOLD}${CYAN}[1/5] Code Style Formatting (Laravel Pint auto-fix)...${NC}"
    local s_start s_end
    s_start=$(date +%s)
    if vendor/bin/pint; then
        s_end=$(date +%s)
        echo -e "${GREEN}✓ Code Style Formatting passed ($((s_end - s_start))s).${NC}"
        if [ -n "$(git status --porcelain)" ]; then
            echo -e "${YELLOW}ℹ️ Laravel Pint reformatted files; committing style fixes automatically...${NC}"
            git status -s
            git add -A
            git commit -m "Apply Laravel Pint code style fixes"
        fi
    else
        s_end=$(date +%s)
        echo -e "${RED}✗ Code Style Formatting failed ($((s_end - s_start))s)!${NC}"
        return 1
    fi

    echo -e "\n${BOLD}${CYAN}[2-4/5] Running PHPStan, PHPUnit & Composer Audit in parallel...${NC}"

    local core_cmd=(php vendor/bin/phpunit --testdox)
    if [ "$with_coverage" = true ]; then
        core_cmd=(php -d opcache.enable_cli=0 -d open_basedir= -d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit --coverage-text --testdox)
    fi

    run_bg_gate "$TMP_WORK_DIR/g2.log" "$TMP_WORK_DIR/g2.time" \
        php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon -l 10 --no-progress &
    local pid_g2=$!

    local core_tmp="$TMP_WORK_DIR/tmp_core"
    mkdir -p "$core_tmp"

    run_bg_gate "$TMP_WORK_DIR/g3.log" "$TMP_WORK_DIR/g3.time" \
        env TMPDIR="$core_tmp" "${core_cmd[@]}" &
    local pid_g3=$!

    run_bg_gate "$TMP_WORK_DIR/g4.log" "$TMP_WORK_DIR/g4.time" \
        run_composer_security_audit &
    local pid_g4=$!

    report_parallel_gate() {
        local num="$1" name="$2" pid="$3" log="$4" tfile="$5"
        local dur
        if wait "$pid"; then
            dur=$(cat "$tfile" 2>/dev/null || echo 0)
            echo -e "${GREEN}✓ [${num}/5] ${name} passed (${dur}s).${NC}"
        else
            dur=$(cat "$tfile" 2>/dev/null || echo 0)
            echo -e "${RED}✗ [${num}/5] ${name} failed (${dur}s)!${NC}"
            cat "$log"
            failed_steps+=("${name}")
        fi
    }

    report_parallel_gate "2" "Static Type Analysis (PHPStan Level 10)" "$pid_g2" "$TMP_WORK_DIR/g2.log" "$TMP_WORK_DIR/g2.time"
    report_parallel_gate "3" "Test Suite (PHPUnit)" "$pid_g3" "$TMP_WORK_DIR/g3.log" "$TMP_WORK_DIR/g3.time"
    if [ "$with_coverage" = true ] && [ -f "$TMP_WORK_DIR/g3.log" ]; then
        awk '/^Code Coverage Report:/{flag=1} flag' "$TMP_WORK_DIR/g3.log"
    fi
    report_parallel_gate "4" "Dependency Security Audit (Composer)" "$pid_g4" "$TMP_WORK_DIR/g4.log" "$TMP_WORK_DIR/g4.time"

    if [ ${#failed_steps[@]} -eq 0 ]; then
        if [ "$skip_trivy" = true ]; then
            echo -e "\n${YELLOW}⚠️ [5/5] Container Security Audit skipped (--skip-trivy).${NC}"
        elif command -v trivy >/dev/null 2>&1; then
            echo -e "\n${BOLD}${CYAN}[5/5] Container Security Audit (Trivy Dockerfile & Built Image)...${NC}"
            s_start=$(date +%s)
            if run_trivy_container_audit "$fresh_build"; then
                s_end=$(date +%s)
                echo -e "${GREEN}✓ Container Security Audit passed ($((s_end - s_start))s).${NC}"
            else
                s_end=$(date +%s)
                echo -e "${RED}✗ Container Security Audit failed ($((s_end - s_start))s)!${NC}"
                failed_steps+=("Container Security Audit (Trivy)")
            fi
        else
            echo -e "\n${YELLOW}[5/5] Trivy not found on host. Skipping container security audit.${NC}"
        fi
    else
        echo -e "\n${YELLOW}[5/5] Skipping Container Security Audit because earlier QA gates failed.${NC}"
    fi

    local total_end total_duration
    total_end=$(date +%s)
    total_duration=$((total_end - start_time))

    echo -e "\n${BOLD}${BLUE}==========================================================${NC}"
    echo -e "${BOLD}QA Summary Report (Total Time: ${total_duration}s):${NC}"

    if [ ${#failed_steps[@]} -eq 0 ]; then
        echo -e "${BOLD}${GREEN}✅ ALL QA GATES PASSED! The codebase is in pristine condition.${NC}"
        echo -e "${BOLD}${BLUE}==========================================================${NC}"
        return 0
    else
        echo -e "${BOLD}${RED}❌ QA GATES FAILED (${#failed_steps[@]} failures detected):${NC}"
        for failed in "${failed_steps[@]}"; do
            echo -e "   - ${RED}${failed}${NC}"
        done
        echo -e "\nPlease resolve the issues above before publishing."
        echo -e "${BOLD}${BLUE}==========================================================${NC}"
        return 1
    fi
}

cmd_publish() {
    local fresh=false coverage=false skip_trivy=false
    while [ $# -gt 0 ]; do
        case "$1" in
            --fresh)      fresh=true ;;
            --coverage)   coverage=true ;;
            --skip-trivy) skip_trivy=true ;;
            --help|-h)    usage; exit 0 ;;
            *) echo -e "${RED}Unknown option for 'publish': $1${NC}" >&2; usage; exit 1 ;;
        esac
        shift
    done

    check_git_preflight
    run_qa_gates "$fresh" "$coverage" "$skip_trivy"
    run_git_sync
}

cmd_reset() {
    local fresh=false
    while [ $# -gt 0 ]; do
        case "$1" in
            --fresh) fresh=true ;;
            --help|-h) usage; exit 0 ;;
            *) echo -e "${RED}Unknown option for 'reset': $1${NC}" >&2; usage; exit 1 ;;
        esac
        shift
    done

    require_podman
    ensure_local_env
    ensure_local_secrets

    local compose=(podman compose "${COMPOSE_FILES[@]}")
    echo -e "${BOLD}${BLUE}🧹 LogMonitor Local Environment Reset${NC}"

    echo -e "\n${BOLD}[1/3] Stopping containers and wiping volumes...${NC}"
    "${compose[@]}" down -v --remove-orphans

    echo -e "\n${BOLD}[2/3] Building images...${NC}"
    if $fresh; then
        "${compose[@]}" build --pull --no-cache
    else
        "${compose[@]}" build
    fi

    echo -e "\n${BOLD}[3/3] Starting stack (migrations + seeders run in the app entrypoint)...${NC}"
    "${compose[@]}" up -d
    wait_for_healthz
    echo -e "   Demo login: ${BOLD}admin@logmonitor.local / admin123${NC}"
}

cmd="${1:-help}"
[ $# -gt 0 ] && shift

case "$cmd" in
    up)               cmd_up "$@" ;;
    publish)          cmd_publish "$@" ;;
    reset)            cmd_reset "$@" ;;
    status)           require_podman; podman compose "${COMPOSE_FILES[@]}" ps ;;
    logs)             require_podman; exec podman compose "${COMPOSE_FILES[@]}" logs -f --tail 100 "$@" ;;
    help|-h|--help)   usage ;;
    *)
        echo -e "${RED}Unknown local command: $cmd${NC}" >&2
        usage
        exit 1
        ;;
esac

