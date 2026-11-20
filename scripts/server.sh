#!/usr/bin/env bash
# ==============================================================================
# LogMonitor - Unified Production Server Manager (scripts/server.sh)
#
# Usage: ./ops <command> [options]   (or ./scripts/server.sh <command> [options])
# Designed to be idempotent and reusable across LogMonitor-powered deployments.
# ==============================================================================
set -Eeuo pipefail

# Guard: Run ONLY on Debian (Production Server)
if [ ! -f /etc/os-release ] || ! grep -qi '^ID=.*debian' /etc/os-release; then
    echo -e "\033[0;31m❌ Error: scripts/server.sh is strictly for running on Debian (the production server).\033[0m" >&2
    exit 1
fi

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

c_b="\033[1m"; c_g="\033[0;32m"; c_y="\033[0;33m"; c_r="\033[0;31m"; c_n="\033[0m"
step() { echo -e "\n${c_b}==> $*${c_n}"; }
ok()   { echo -e "${c_g}✓ $*${c_n}"; }
warn() { echo -e "${c_y}! $*${c_n}"; }
die()  { echo -e "${c_r}✗ $*${c_n}" >&2; exit 1; }

# Global cleanup registry for temporary/partial files on exit or interruption
TMP_CLEANUP_PATHS=()
register_cleanup() { TMP_CLEANUP_PATHS+=("$1"); }
run_cleanup() {
    local rc=$? p
    for p in "${TMP_CLEANUP_PATHS[@]:-}"; do
        [ -n "$p" ] && [ -e "$p" ] && rm -rf "$p" 2>/dev/null || true
    done
    return "$rc"
}
trap run_cleanup EXIT INT TERM

env_value() {
    grep -E "^$1=" "$APP_DIR/.env" 2>/dev/null | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

# Derive project slug from .env (COMPOSE_PROJECT_NAME), docker-compose.yml (name:), or fallback 'log-monitor'
detect_slug() {
    local slug
    slug="$(env_value COMPOSE_PROJECT_NAME || true)"
    if [ -z "$slug" ] && [ -f "$APP_DIR/docker-compose.yml" ]; then
        slug="$(awk -F: '/^name:[[:space:]]*/ {gsub(/[[:space:]"'\'']/, "", $2); print $2; exit}' "$APP_DIR/docker-compose.yml" || true)"
    fi
    slug="${slug:-log-monitor}"
    printf '%s' "$slug"
}

APP_SLUG="$(detect_slug)"
APP_SLUG_UPPER="$(printf '%s' "$APP_SLUG" | tr '[:lower:]-' '[:upper:]_')"
IMAGE="${APP_SLUG}-app"
STATE_DIR="$APP_DIR/.deploy"
SECRETS_DIR="$APP_DIR/secrets"
ALL_SECRETS=(app_key db_password db_root_password log_api_key smtp_password admin_password)
APP_GID=82
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"
FRESH_BUILD_DAYS="${FRESH_BUILD_DAYS:-7}"

usage() {
    cat <<EOF

  LogMonitor — Debian Production Server Operations (./ops <command> [options])

  COMMANDS
    deploy [OPTIONS]        Verify server prerequisites (.env, secrets, systemd
                            setup), pull latest 'master' branch, build image,
                            take an encrypted pre-deploy backup, recreate app
                            containers (keeping DB warm), and verify HTTP health
                            (auto-rollback on failure). Runs as deploy user.
      --fresh               Force full image rebuild without Docker/Podman cache.
      --no-pull             Skip automatic 'git pull --ff-only origin master'.
      --skip-backup         Skip pre-deploy backup (use only for rapid hotfixes).
      --rollback            Revert immediately to the previous image and commit.
      --expect <sha>        Abort if 'master' HEAD does not match <sha>.

    rollback                Alias for 'deploy --rollback'.

    backup [LABEL]          Create an encrypted snapshot of MariaDB and user
                            uploads (storage/profile, storage/backups).
      LABEL                 Optional snapshot label (default: 'manual'; systemd
                            timer passes 'daily'; deploy passes 'pre-deploy-<sha>').

    setup <USER> [REPO]     (Alias: server-setup) Requires root (sudo). Complete
                            idempotent host & app provisioning: installs Podman,
                            ufw, age, pigz, enables rootless linger, hardens
                            sysctl/ufw/ssh, clones 'master' if missing, configures
                            .env and all secrets interactively, and installs
                            systemd units (${APP_SLUG}-egress, ${APP_SLUG}-backup).
      USER                  Unprivileged Linux deploy user (e.g. liviu).
      REPO                  Optional Git repository URL.

    egress <ACTION>         Requires root (sudo). Manage outbound iptables rules
                            on the container bridge (default from docker-compose.yml).
      apply                 Apply outbound rules (DNS 53, HTTPS 443, SMTP 587/465).
      remove                Remove the ${APP_SLUG_UPPER}-EGRESS iptables chain.
      status                Display active FORWARD and ${APP_SLUG_UPPER}-EGRESS rules.

    status                  Show current deployed commit, container status,
                            backup usage, and CPU/memory stats.

    logs [SERVICE...]       Stream live logs (default: app log-worker worker; or specify e.g. db).

    help, -h, --help        Display this help message.

  EXAMPLES
    sudo ./ops server-setup liviu
    ./ops deploy
    ./ops deploy --fresh --no-pull
    ./ops backup manual
    ./ops rollback

EOF
}

require_root() {
    [ "$(id -u)" -eq 0 ] || die "This command must be run as root (use: sudo ./ops $*)."
}

require_podman() {
    command -v podman >/dev/null 2>&1 || die "'podman' was not found. Run: sudo ./ops setup <deploy-user>"
}

# Detect whether the target Podman runtime is rootless for the given user (cached).
_CACHED_ROOTLESS_USER=""
_CACHED_ROOTLESS_VAL=""
is_rootless_for_user() {
    local target_user="${1:-$(id -un)}"
    if [ "$target_user" = "root" ]; then
        return 1
    fi

    if [ "$_CACHED_ROOTLESS_USER" != "$target_user" ]; then
        local flag
        if [ "$(id -u)" -eq 0 ]; then
            flag="$(sudo -u "$target_user" podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null || echo "true")"
        else
            flag="$(podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null || echo "true")"
        fi
        _CACHED_ROOTLESS_USER="$target_user"
        _CACHED_ROOTLESS_VAL="$flag"
    fi

    [ "$_CACHED_ROOTLESS_VAL" = "true" ]
}

# Determine who owns the repository/deploy workflow when running secrets commands.
resolve_deploy_user() {
    if [ -n "${DEPLOY_USER:-}" ]; then
        printf '%s' "$DEPLOY_USER"
    elif [ "$(id -u)" -ne 0 ]; then
        id -un
    else
        stat -c '%U' "$APP_DIR" 2>/dev/null || echo "root"
    fi
}

# ------------------------------------------------------------------------------
# Secrets helpers (auto-detects rootless vs rootful ownership)
# ------------------------------------------------------------------------------
secret_has_valid_value() {
    local file="$SECRETS_DIR/$1" min_len="${2:-1}" len
    [ -r "$file" ] || return 1
    LC_ALL=C grep -q '[[:cntrl:]]' "$file" 2>/dev/null && return 1
    len="$(tr -d '[:space:]' < "$file" 2>/dev/null | wc -c)"
    [ "${len:-0}" -ge "$min_len" ]
}

read_secret_prompt() {
    local prompt="$1" raw
    # Disable terminal bracketed paste so pasted secrets never capture ESC[200~/201~
    printf '\e[?2004l' >&2
    read -r -s -p "$prompt" raw; echo >&2
    printf '%s' "$raw" | sed -e $'s/\x1b\\[[0-9;?]*[A-Za-z~]//g' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' | tr -d '[:cntrl:]'
}

ensure_secrets_dir() {
    local owner
    owner="$(resolve_deploy_user)"
    mkdir -p "$SECRETS_DIR"
    if is_rootless_for_user "$owner"; then
        [ "$(id -u)" -eq 0 ] && [ "$owner" != "root" ] && chown "$owner:$owner" "$SECRETS_DIR"
        chmod 0700 "$SECRETS_DIR"
    else
        chmod 0755 "$SECRETS_DIR"
    fi
}

write_secret() {
    local name="$1" value="$2" tmp owner mode="0644"
    owner="$(resolve_deploy_user)"
    [ "$name" = "db_root_password" ] && mode="0600"
    tmp="$(mktemp)"
    register_cleanup "$tmp"
    chmod 600 "$tmp"
    printf '%s' "$value" > "$tmp"

    if is_rootless_for_user "$owner"; then
        # Parent ./secrets is 0700 (blocking other host users); inside rootless
        # Podman, host $owner maps to uid 0 while app/worker run as www-data (uid 82),
        # so bind-mounted app secrets need 0644 to be readable inside containers.
        if [ "$(id -u)" -eq 0 ] && [ "$owner" != "root" ]; then
            install -o "$owner" -g "$owner" -m "$mode" "$tmp" "$SECRETS_DIR/$name"
        else
            install -m "$mode" "$tmp" "$SECRETS_DIR/$name"
        fi
    else
        # Rootful container runtime (app/worker run as www-data gid 82)
        local sudo_cmd=""
        [ "$(id -u)" -eq 0 ] || sudo_cmd="sudo"
        if [ "$name" = "db_root_password" ]; then
            $sudo_cmd install -o root -g root -m 0400 "$tmp" "$SECRETS_DIR/$name"
        else
            $sudo_cmd install -o root -g "$APP_GID" -m 0440 "$tmp" "$SECRETS_DIR/$name"
        fi
    fi

    rm -f "$tmp"
}

random_hex()  { openssl rand -hex 32; }
random_pass() { openssl rand -base64 33 | tr -d '/+=\n' | cut -c1-32; }

secrets_init() {
    ensure_secrets_dir
    local name owner
    owner="$(resolve_deploy_user)"
    for name in "${ALL_SECRETS[@]}"; do
        if [ -s "$SECRETS_DIR/$name" ]; then
            echo "  = $name (exists)"
            continue
        fi
        case "$name" in
            app_key)
                write_secret "$name" "$(random_hex)"
                echo "  + $name (generated)"
                ;;
            db_password|db_root_password)
                write_secret "$name" "$(random_pass)"
                echo "  + $name (generated)"
                ;;
            *)
                [ -e "$SECRETS_DIR/$name" ] || write_secret "$name" ""
                echo "  ! $name (empty — configured interactively)"
                ;;
        esac
    done

    # Repair permissions on pre-existing secrets (e.g. 0600 files created earlier)
    if is_rootless_for_user "$owner"; then
        for name in "${ALL_SECRETS[@]}"; do
            [ -e "$SECRETS_DIR/$name" ] || continue
            [ "$(id -u)" -eq 0 ] && [ "$owner" != "root" ] && chown "$owner:$owner" "$SECRETS_DIR/$name"
            if [ "$name" = "db_root_password" ]; then
                chmod 0600 "$SECRETS_DIR/$name"
            else
                chmod 0644 "$SECRETS_DIR/$name"
            fi
        done
    fi
}

secrets_import_env() {
    [ -f "$APP_DIR/.env" ] || die ".env not found."
    ensure_secrets_dir
    local pair name var value backed_up=false
    for pair in app_key:APP_KEY db_password:DB_PASSWORD db_root_password:MYSQL_ROOT_PASSWORD \
                log_api_key:LOG_API_KEY smtp_password:SMTP_PASSWORD admin_password:ADMIN_PASSWORD; do
        name="${pair%%:*}"; var="${pair#*:}"
        value="$(env_value "$var" || true)"
        if [ -n "$value" ]; then
            write_secret "$name" "$value"
            echo "  → $var moved to secrets/$name"
        elif [ ! -e "$SECRETS_DIR/$name" ]; then
            write_secret "$name" ""
        fi
        if grep -qE "^$var=" "$APP_DIR/.env"; then
            if ! $backed_up; then
                cp -p "$APP_DIR/.env" "$APP_DIR/.env.bak-secrets"
                chmod 600 "$APP_DIR/.env.bak-secrets" 2>/dev/null || true
                backed_up=true
            fi
            sed -i "/^$var=/d" "$APP_DIR/.env"
        fi
    done
    chmod 600 "$APP_DIR/.env" 2>/dev/null || true
    ok "Secrets imported from .env. Verify the stack, then delete .env.bak-secrets (shred -u .env.bak-secrets)."
}

secrets_status() {
    local name state size
    for name in "${ALL_SECRETS[@]}"; do
        if [ ! -e "$SECRETS_DIR/$name" ]; then
            state="MISSING"
        else
            size="$(stat -c %s "$SECRETS_DIR/$name" 2>/dev/null || sudo stat -c %s "$SECRETS_DIR/$name" 2>/dev/null || echo 0)"
            if [ "$size" -eq 0 ]; then state="empty"; else state="set"; fi
        fi
        printf '  %-18s %s\n' "$name" "$state"
    done
}

# ------------------------------------------------------------------------------
# Interactive .env & secrets configurator (idempotent)
# ------------------------------------------------------------------------------
set_env_var() {
    local key="$1" val="$2" file="$APP_DIR/.env"
    local quoted tmp_file="${file}.tmp.$$"
    register_cleanup "$tmp_file"
    quoted="$(printf '%s' "$val" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\$/\\$/g')"
    if grep -qE "^${key}=" "$file"; then
        awk -v k="$key" -v v="\"$quoted\"" 'BEGIN{OFS="="} $0 ~ "^"k"=" {print k, v; next} {print}' "$file" > "$tmp_file"
        mv "$tmp_file" "$file"
    else
        printf '%s="%s"\n' "$key" "$quoted" >> "$file"
    fi
    chmod 600 "$file"
}

prompt_env_key() {
    local key="$1" label="$2" default_val="$3" current val
    current="$(env_value "$key" || true)"
    [ -z "$current" ] && current="$default_val"
    read -r -p "  ${label} [${current}]: " val
    val="${val:-$current}"
    set_env_var "$key" "$val"
}

configure_env_and_secrets() {
    local owner="${1:-$(resolve_deploy_user)}"
    local created_env=false

    if [ ! -f "$APP_DIR/.env" ]; then
        [ -f "$APP_DIR/.env.example" ] || die ".env.example is missing in $APP_DIR."
        install -m 600 "$APP_DIR/.env.example" "$APP_DIR/.env"
        [ "$(id -u)" -eq 0 ] && [ "$owner" != "root" ] && chown "$owner:$owner" "$APP_DIR/.env"
        created_env=true

        if [ -t 0 ]; then
            step "Interactive .env configuration (press Enter to accept default)"
            prompt_env_key "APP_NAME"             "Application name"                    "LogMonitor"
            prompt_env_key "APP_URL"              "Public URL (https://...)"            "https://log-monitor.insightdevhub.ro"
            prompt_env_key "APP_PORT"             "Host loopback port (cloudflared)"    "8082"
            prompt_env_key "TZ"                   "Timezone"                            "Europe/Bucharest"
            prompt_env_key "DB_DATABASE"          "Database name"                       "${APP_SLUG}"
            prompt_env_key "DB_USERNAME"          "Database user"                       "${APP_SLUG}_user"
            prompt_env_key "ADMIN_EMAIL"          "First admin email"                   "admin@logmonitor.local"
            prompt_env_key "LOG_SERVER_URL"       "LogMonitor URL (https://...)"        "https://log-monitor.insightdevhub.ro/api/log.php"
            prompt_env_key "SMTP_HOST"            "SMTP host"                           "smtp.your-mail-provider.com"
            prompt_env_key "SMTP_PORT"            "SMTP port"                           "587"
            prompt_env_key "SMTP_USERNAME"        "SMTP username (optional)"            ""
            prompt_env_key "SMTP_FROM"            "SMTP From address"                   "noreply@log-monitor.insightdevhub.ro"
            prompt_env_key "BACKUP_DIR"           "Backup directory (optional)"         ""
            prompt_env_key "BACKUP_AGE_RECIPIENT" "Age public key for backups (age1..)" ""
            prompt_env_key "BACKUP_RCLONE_REMOTE" "rclone off-site remote (optional)"   ""
            [ "$(id -u)" -eq 0 ] && [ "$owner" != "root" ] && chown "$owner:$owner" "$APP_DIR/.env"
            ok ".env configured."
        else
            warn ".env created from .env.example (non-interactive shell) — review $APP_DIR/.env before deploy."
        fi
    fi

    # Refresh slug in case .env was updated
    APP_SLUG="$(detect_slug)"
    APP_SLUG_UPPER="$(printf '%s' "$APP_SLUG" | tr '[:lower:]-' '[:upper:]_')"
    IMAGE="${APP_SLUG}-app"

    if grep -qE '^(APP_KEY|DB_PASSWORD|MYSQL_ROOT_PASSWORD|LOG_API_KEY|SMTP_PASSWORD|ADMIN_PASSWORD)=.+' "$APP_DIR/.env"; then
        secrets_import_env
    fi

    secrets_init

    if [ -t 0 ]; then
        if ! $created_env && [ ! -f "$STATE_DIR/current_commit" ]; then
            if [ -z "$(env_value ADMIN_EMAIL || true)" ]; then
                prompt_env_key "ADMIN_EMAIL" "First admin email" "admin@logmonitor.local"
            fi
            local current_log_url
            current_log_url="$(env_value LOG_SERVER_URL || true)"
            case "$current_log_url" in
                https://*) ;;
                *) prompt_env_key "LOG_SERVER_URL" "LogMonitor URL (https://...)" "https://log-monitor.insightdevhub.ro/api/log.php" ;;
            esac
            [ "$(id -u)" -eq 0 ] && [ "$owner" != "root" ] && chown "$owner:$owner" "$APP_DIR/.env"
        fi

        local val
        if [ -e "$SECRETS_DIR/log_api_key" ] && [ -s "$SECRETS_DIR/log_api_key" ] && ! secret_has_valid_value "log_api_key" 8; then
            warn "secrets/log_api_key looks invalid/truncated ($(wc -c < "$SECRETS_DIR/log_api_key" 2>/dev/null || echo 0) bytes) — please re-enter it."
        fi
        while ! secret_has_valid_value "log_api_key" 8; do
            val="$(read_secret_prompt "  Enter secret log_api_key (required, >= 8 chars): ")"
            if [ ${#val} -ge 8 ]; then
                write_secret "log_api_key" "$val"
            else
                warn "log_api_key is required and must be at least 8 non-whitespace characters."
            fi
        done
        if [ ! -s "$SECRETS_DIR/smtp_password" ] && [ -n "$(env_value SMTP_USERNAME || true)" ]; then
            val="$(read_secret_prompt "  Enter secret smtp_password (Enter to skip): ")"
            [ -n "$val" ] && write_secret "smtp_password" "$val"
        fi
        if [ ! -s "$SECRETS_DIR/admin_password" ] && [ ! -f "$STATE_DIR/current_commit" ]; then
            while true; do
                val="$(read_secret_prompt "  Enter initial admin_password (>= 14 chars, Enter to skip): ")"
                if [ -z "$val" ]; then
                    break
                elif [ ${#val} -lt 14 ]; then
                    warn "admin_password must be at least 14 characters long."
                else
                    write_secret "admin_password" "$val"
                    break
                fi
            done
        fi
    elif $created_env; then
        warn "Run interactively to finish setup: sudo ./ops server-setup $owner"
    fi
}

# ------------------------------------------------------------------------------
# Egress firewall (root required)
# ------------------------------------------------------------------------------
cmd_egress() {
    local action="${1:-help}"
    case "$action" in
        help|-h|--help) usage; exit 0 ;;
    esac

    require_root "egress $action"
    command -v iptables >/dev/null || die "iptables not found."

    local bridge="${EGRESS_BRIDGE:-}"
    if [ -z "$bridge" ] && [ -f "$APP_DIR/docker-compose.yml" ]; then
        bridge="$(awk '/com\.docker\.network\.bridge\.name:/ {gsub(/["'\''[:space:]]/, "", $2); print $2; exit}' "$APP_DIR/docker-compose.yml" || true)"
    fi
    bridge="${bridge:-${APP_SLUG}-front}"
    bridge="${bridge:0:15}"
    local chain="${APP_SLUG_UPPER}-EGRESS"
    local ipt="iptables"

    egress_remove() {
        while $ipt -C FORWARD -i "$bridge" -j "$chain" 2>/dev/null; do
            $ipt -D FORWARD -i "$bridge" -j "$chain"
        done
        $ipt -F "$chain" 2>/dev/null || true
        $ipt -X "$chain" 2>/dev/null || true
    }

    egress_apply() {
        $ipt -N "$chain" 2>/dev/null || true
        $ipt -F "$chain"

        $ipt -A "$chain" -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
        $ipt -A "$chain" -p udp --dport 53 -j RETURN
        $ipt -A "$chain" -p tcp --dport 53 -j RETURN
        for net in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16 100.64.0.0/10; do
            $ipt -A "$chain" -d "$net" -j DROP
        done

        if [ "${EGRESS_HTTPS_CLOUDFLARE_ONLY:-0}" = "1" ]; then
            local ranges
            ranges="$(curl -fsS --max-time 10 https://www.cloudflare.com/ips-v4 || true)"
            if [ -n "$ranges" ]; then
                for net in $ranges; do $ipt -A "$chain" -p tcp --dport 443 -d "$net" -j RETURN; done
            else
                warn "Could not fetch Cloudflare ranges — allowing HTTPS to any destination."
                $ipt -A "$chain" -p tcp --dport 443 -j RETURN
            fi
        else
            $ipt -A "$chain" -p tcp --dport 443 -j RETURN
        fi

        for port in 587 465 ${EGRESS_EXTRA_PORTS:-}; do
            $ipt -A "$chain" -p tcp --dport "$port" -j RETURN
        done

        $ipt -A "$chain" -m limit --limit 6/min -j LOG --log-prefix "${APP_SLUG_UPPER}-EGRESS-DROP " --log-level 4
        $ipt -A "$chain" -j DROP

        $ipt -C FORWARD -i "$bridge" -j "$chain" 2>/dev/null || $ipt -I FORWARD 1 -i "$bridge" -j "$chain"
        ok "Egress firewall applied on bridge $bridge (chain $chain)."
    }

    case "$action" in
        apply)  egress_apply ;;
        remove) egress_remove; ok "Egress firewall removed." ;;
        status) $ipt -L FORWARD -n -v --line-numbers; echo; $ipt -L "$chain" -n -v --line-numbers 2>/dev/null || echo "$chain not present." ;;
        *) die "Unknown egress action '$action'. Use: apply | remove | status" ;;
    esac
}

# Resolve container ID for a compose service (pass 'all' as $2 to include stopped containers)
service_cid() {
    local svc="$1" include_all="${2:-}" flags=(-q) cid=""
    [ "$include_all" = "all" ] && flags=(-aq)
    cid="$(podman ps "${flags[@]}" --filter "label=io.podman.compose.project=${APP_SLUG}" --filter "label=io.podman.compose.service=${svc}" 2>/dev/null | head -n1 || true)"
    if [ -z "$cid" ]; then
        cid="$(podman ps "${flags[@]}" --filter "label=com.docker.compose.project=${APP_SLUG}" --filter "label=com.docker.compose.service=${svc}" 2>/dev/null | head -n1 || true)"
    fi
    if [ -z "$cid" ]; then
        cid="$(podman ps "${flags[@]}" --filter "name=^${APP_SLUG}_${svc}" 2>/dev/null | head -n1 || true)"
    fi
    printf '%s' "$cid"
}

# ------------------------------------------------------------------------------
# Encrypted Backup (parallel pigz compression when available)
# ------------------------------------------------------------------------------
cmd_backup() {
    case "${1:-}" in
        --help|-h) usage; exit 0 ;;
    esac

    require_podman
    local label="${1:-manual}"
    label="${label//[^A-Za-z0-9._-]/_}"

    local backup_dir keep_days recipient remote ts
    backup_dir="${BACKUP_DIR:-$(env_value BACKUP_DIR || true)}"
    backup_dir="${backup_dir:-$HOME/backups/${APP_SLUG}}"
    keep_days="${BACKUP_KEEP_DAYS:-$(env_value BACKUP_KEEP_DAYS || true)}"
    keep_days="${keep_days:-14}"
    recipient="$(env_value BACKUP_AGE_RECIPIENT || true)"
    remote="$(env_value BACKUP_RCLONE_REMOTE || true)"
    ts="$(date +%Y%m%d-%H%M%S)"

    local gz_cmd=(gzip -4)
    if command -v pigz >/dev/null 2>&1; then
        gz_cmd=(pigz -4)
    fi

    umask 077
    mkdir -p "$backup_dir"
    chmod 700 "$backup_dir"

    exec 8>"$backup_dir/.lock"
    flock -w 600 8 || die "[backup] Another backup is running."

    local ext
    if [ -n "$recipient" ]; then
        command -v age >/dev/null 2>&1 || die "BACKUP_AGE_RECIPIENT is set but 'age' is not installed (apt install age)."
        ext=".gz.age"
        seal() { "${gz_cmd[@]}" | age -r "$recipient"; }
    else
        ext=".gz"
        seal() { "${gz_cmd[@]}"; }
        warn "[backup] BACKUP_AGE_RECIPIENT not set — backups are NOT encrypted."
    fi

    local created=() out db_cid app_cid
    db_cid="$(service_cid db)"
    if [ -n "$db_cid" ]; then
        out="$backup_dir/db-$ts-$label.sql$ext"
        register_cleanup "$out.part"
        if podman exec "$db_cid" sh -c \
            'MYSQL_PWD="$(cat /run/secrets/db_root_password)" exec mariadb-dump -uroot --single-transaction --quick --routines --triggers --events --databases "$MARIADB_DATABASE"' \
            | seal > "$out.part"; then
            mv "$out.part" "$out"; created+=("$out")
            echo "[backup] database → $out ($(du -h "$out" | cut -f1))"
        else
            rm -f "$out.part"; die "[backup] Database dump failed."
        fi
    else
        echo "[backup] db container not running — database skipped."
    fi

    local storage_vol="${APP_SLUG}_storage_data"
    local storage_source="none"
    app_cid="$(service_cid app)"
    if [ -n "$app_cid" ] && [ "$(podman inspect -f '{{.RestartCount}}' "$app_cid" 2>/dev/null || echo 1)" -eq 0 ] \
        && podman exec "$app_cid" true 2>/dev/null; then
        storage_source="exec"
    elif podman volume inspect "$storage_vol" >/dev/null 2>&1 \
        && podman image inspect "$IMAGE:latest" >/dev/null 2>&1; then
        storage_source="volume"
    fi

    run_storage_cmd() {
        local script="$1"
        if [ "$storage_source" = "exec" ]; then
            podman exec "$app_cid" sh -c "$script"
        else
            podman run --rm --user 0:0 --entrypoint sh -v "$storage_vol:/var/www/html/storage:ro" "$IMAGE:latest" -c "$script"
        fi
    }

    if [ "$storage_source" != "none" ]; then
        local has_dirs
        has_dirs="$(run_storage_cmd 'cd /var/www/html/storage 2>/dev/null && for d in profile backups; do [ -d "$d" ] && printf "%s " "$d"; done' 2>/dev/null || true)"
        if [ -n "$has_dirs" ]; then
            out="$backup_dir/storage-$ts-$label.tar$ext"
            register_cleanup "$out.part"
            if run_storage_cmd 'cd /var/www/html/storage && set --; for d in profile backups; do [ -d "$d" ] && set -- "$@" "$d"; done; exec tar -cf - "$@"' \
                | seal > "$out.part"; then
                mv "$out.part" "$out"; created+=("$out")
                echo "[backup] storage  → $out ($(du -h "$out" | cut -f1))"
            else
                rm -f "$out.part"; die "[backup] Storage archive failed."
            fi
        else
            echo "[backup] no profile/backups directories found — storage skipped."
        fi
    else
        echo "[backup] app container and storage volume unavailable — storage skipped."
    fi

    find "$backup_dir" -maxdepth 1 -type f \( -name 'db-*' -o -name 'storage-*' \) -mtime +"$keep_days" -delete

    if [ -n "$remote" ] && [ "${#created[@]}" -gt 0 ]; then
        if command -v rclone >/dev/null 2>&1; then
            for f in "${created[@]}"; do rclone copy --no-traverse "$f" "$remote"; done
            echo "[backup] off-site copy → $remote"
        else
            warn "[backup] BACKUP_RCLONE_REMOTE set but rclone is not installed."
        fi
    fi

    if [ "$label" = "daily" ] && [ -n "$db_cid" ]; then
        podman exec "$db_cid" sh -c \
            'f=/var/lib/mysql/slow.log; [ -f "$f" ] && [ "$(stat -c %s "$f")" -gt 20971520 ] && : > "$f"; true' || true
    fi

    ok "[backup] Done."
}

# ------------------------------------------------------------------------------
# Host Setup (root required, idempotent)
# ------------------------------------------------------------------------------
cmd_setup() {
    case "${1:-}" in
        --help|-h) usage; exit 0 ;;
    esac

    require_root "setup $*"
    local deploy_user="${1:?Usage: ./ops setup <deploy-user> [repo-url]}"
    local repo_url="${2:-https://github.com/liviu10/log-monitor.git}"
    local target_dir="${APP_DIR}"

    id "$deploy_user" >/dev/null 2>&1 || die "User '$deploy_user' does not exist."
    local deploy_home
    deploy_home="$(getent passwd "$deploy_user" | cut -d: -f6)"

    export DEBIAN_FRONTEND=noninteractive

    step "Base packages & Podman (Debian)"
    local needed=()
    for pkg in ca-certificates curl gnupg git ufw unattended-upgrades apt-listchanges age pigz rclone iptables podman podman-compose; do
        dpkg -s "$pkg" >/dev/null 2>&1 || needed+=("$pkg")
    done
    if [ ${#needed[@]} -gt 0 ]; then
        apt-get update -q
        apt-get install -y -q "${needed[@]}"
    else
        ok "All base packages already installed."
    fi

    step "Unattended security upgrades"
    cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CONF
    cat > /etc/apt/apt.conf.d/52unattended-upgrades-local <<'CONF'
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "04:30";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
CONF

    step "Kernel / network hardening (90-${APP_SLUG}.conf)"
    cat > "/etc/sysctl.d/90-${APP_SLUG}.conf" <<'CONF'
kernel.kptr_restrict = 2
kernel.dmesg_restrict = 1
kernel.unprivileged_bpf_disabled = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.icmp_echo_ignore_broadcasts = 1
CONF
    sysctl --system >/dev/null
    mkdir -p /etc/systemd/journald.conf.d
    printf '[Journal]\nSystemMaxUse=200M\n' > /etc/systemd/journald.conf.d/size.conf
    systemctl restart systemd-journald

    step "Firewall (ufw)"
    local ssh_port
    ssh_port="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')"
    ssh_port="${ssh_port:-22}"
    ufw default deny incoming >/dev/null
    ufw default allow outgoing >/dev/null
    if [ -n "${SSH_ALLOW_FROM:-}" ]; then
        ufw limit from "$SSH_ALLOW_FROM" to any port "$ssh_port" proto tcp comment 'SSH (restricted)' >/dev/null
        ufw delete limit "${ssh_port}/tcp" >/dev/null 2>&1 || true
    else
        ufw limit "${ssh_port}/tcp" comment 'SSH' >/dev/null
    fi
    ufw --force enable
    ufw status verbose | head -n 12

    step "SSH hardening"
    if [ -s "$deploy_home/.ssh/authorized_keys" ]; then
        cat > /etc/ssh/sshd_config.d/10-hardening.conf <<'CONF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
X11Forwarding no
AllowAgentForwarding no
MaxAuthTries 4
LoginGraceTime 30
CONF
        sshd -t && systemctl reload ssh
        ok "Key-only SSH enabled."
    else
        warn "Skipped: $deploy_home/.ssh/authorized_keys is empty — add your key first, then re-run."
    fi

    step "Application directory & repository (master branch)"
    mkdir -p "$(dirname "$target_dir")"
    if [ ! -d "$target_dir/.git" ]; then
        mkdir -p "$target_dir"
        chown "$deploy_user:$deploy_user" "$target_dir"
        sudo -u "$deploy_user" git clone -b master "$repo_url" "$target_dir"
    fi
    find "$target_dir" -path "$target_dir/secrets" -prune -o -exec chown "$deploy_user:$deploy_user" {} +

    step "Environment (.env) & Secrets"
    DEPLOY_USER="$deploy_user" configure_env_and_secrets "$deploy_user"
    secrets_status

    step "Systemd units (${APP_SLUG}-egress, ${APP_SLUG}-backup)"
    cat > "/etc/systemd/system/${APP_SLUG}-egress.service" <<UNIT
[Unit]
Description=${APP_SLUG} egress firewall for app containers
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$target_dir/scripts/server.sh egress apply
ExecStop=$target_dir/scripts/server.sh egress remove

[Install]
WantedBy=multi-user.target
UNIT

    cat > "/etc/systemd/system/${APP_SLUG}-backup.service" <<UNIT
[Unit]
Description=${APP_SLUG} encrypted backup (database + uploads)
After=network-online.target

[Service]
Type=oneshot
User=$deploy_user
WorkingDirectory=$target_dir
ExecStart=$target_dir/scripts/server.sh backup daily
Nice=10
IOSchedulingClass=idle
UNIT

    cat > "/etc/systemd/system/${APP_SLUG}-backup.timer" <<UNIT
[Unit]
Description=Daily ${APP_SLUG} backup

[Timer]
OnCalendar=*-*-* 02:30
RandomizedDelaySec=10m
Persistent=true

[Install]
WantedBy=timers.target
UNIT

    chmod +x "$target_dir/ops" "$target_dir/scripts/local.sh" "$target_dir/scripts/server.sh"
    if is_rootless_for_user "$deploy_user" && command -v loginctl >/dev/null 2>&1; then
        loginctl enable-linger "$deploy_user" 2>/dev/null || true
    fi
    systemctl daemon-reload
    systemctl enable --now "${APP_SLUG}-egress.service"
    systemctl enable --now "${APP_SLUG}-backup.timer"

    sudo -u "$deploy_user" podman compose config >/dev/null 2>&1 \
        || warn "podman compose config reported an issue — review $target_dir/.env before deploy."

    ok "Host provisioning complete. Next step as $deploy_user: cd $target_dir && ./ops deploy"
}

# ------------------------------------------------------------------------------
# Deploy & Rollback (unprivileged deploy user)
# ------------------------------------------------------------------------------
check_deploy_host_ready() {
    require_podman

    local repo_owner
    repo_owner="$(stat -c '%U' "$APP_DIR" 2>/dev/null || echo "root")"
    if [ "$(id -u)" -eq 0 ] && [ "$repo_owner" != "root" ]; then
        die "Run deploy as '$repo_owner' (not root) so Podman rootless images/volumes stay under $repo_owner."
    fi

    if [ ! -f "/etc/systemd/system/${APP_SLUG}-egress.service" ]; then
        die "Server setup incomplete (${APP_SLUG}-egress.service missing). Run once: sudo ./ops setup $(id -un)"
    fi
}

ensure_server_prereqs() {
    check_deploy_host_ready
    configure_env_and_secrets "$(id -un)"

    local missing_nonempty=()
    for s in app_key db_password db_root_password; do
        secret_has_valid_value "$s" 1 || missing_nonempty+=("$s")
    done
    secret_has_valid_value "log_api_key" 8 || missing_nonempty+=("log_api_key")
    if [ ${#missing_nonempty[@]} -gt 0 ]; then
        die "Missing or invalid required secrets (${missing_nonempty[*]}). Re-run: sudo ./ops server-setup $(id -un)"
    fi

    if [ ! -s "$SECRETS_DIR/admin_password" ] && [ ! -f "$STATE_DIR/current_commit" ]; then
        warn "secrets/admin_password is empty on initial deploy (production admin will not be created until set)."
    fi

    podman compose config >/dev/null || die "docker-compose.yml / .env is invalid."
}

sync_master_branch() {
    if [ ! -d "$APP_DIR/.git" ]; then
        warn "Not a git checkout — skipping git pull."
        return 0
    fi

    local branch
    branch="$(git rev-parse --abbrev-ref HEAD)"
    if [ "$branch" != "master" ]; then
        if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
            die "Repository is on '$branch' with uncommitted changes. Clean or switch to 'master' manually."
        fi
        step "Switching repository branch from '$branch' to 'master'"
        git checkout master
    fi

    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        die "Uncommitted tracked changes detected on server. Commit/revert them before deploying."
    fi

    step "Pulling latest commits from origin/master"
    git pull --ff-only origin master
}

sql_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g"
}

db_creds_hash() {
    local db_name db_user
    db_name="$(env_value DB_DATABASE || true)"
    db_user="$(env_value DB_USERNAME || true)"
    {
        printf 'db=%s\nuser=%s\n' "${db_name:-$APP_SLUG}" "${db_user:-${APP_SLUG}_user}"
        tr -d '\r\n' < "$SECRETS_DIR/db_password" 2>/dev/null || true
        printf '\n'
        tr -d '\r\n' < "$SECRETS_DIR/db_root_password" 2>/dev/null || true
        printf '\n'
    } | sha256sum | awk '{print $1}'
}

mariadb_probe_pw() {
    local cid="$1" user="$2" pw="$3" db="${4:-}"
    { printf '%s\n' "$pw"; printf 'SELECT 1;\n'; } \
        | podman exec -i "$cid" sh -c 'IFS= read -r MYSQL_PWD; export MYSQL_PWD; exec mariadb -u"$1" ${2:+"$2"} >/dev/null 2>&1' _ "$user" "$db"
}

# Keep running MariaDB users/grants in sync with .env + ./secrets/* without wiping data
reconcile_db_credentials() {
    DB_NEEDS_RECREATE=false
    local db_cid
    db_cid="$(service_cid db)"
    [ -n "$db_cid" ] || return 0

    local db_name db_user host_db_pw host_root_pw current_hash recorded_hash=""
    db_name="$(env_value DB_DATABASE || true)"; db_name="${db_name:-$APP_SLUG}"
    db_user="$(env_value DB_USERNAME || true)"; db_user="${db_user:-${APP_SLUG}_user}"
    host_db_pw="$(tr -d '\r\n' < "$SECRETS_DIR/db_password" 2>/dev/null || true)"
    host_root_pw="$(tr -d '\r\n' < "$SECRETS_DIR/db_root_password" 2>/dev/null || true)"
    current_hash="$(db_creds_hash)"
    [ -f "$STATE_DIR/db_creds.sha256" ] && recorded_hash="$(cat "$STATE_DIR/db_creds.sha256" 2>/dev/null || true)"

    # Detect stale /run/secrets/* inodes inside a long-running db container
    local host_db_h host_root_h ctr_db_h ctr_root_h
    host_db_h="$(printf '%s' "$host_db_pw" | sha256sum | awk '{print $1}')"
    host_root_h="$(printf '%s' "$host_root_pw" | sha256sum | awk '{print $1}')"
    ctr_db_h="$(podman exec "$db_cid" sh -c 'tr -d "\r\n" < /run/secrets/db_password 2>/dev/null | sha256sum | awk "{print \$1}"' 2>/dev/null || true)"
    ctr_root_h="$(podman exec "$db_cid" sh -c 'tr -d "\r\n" < /run/secrets/db_root_password 2>/dev/null | sha256sum | awk "{print \$1}"' 2>/dev/null || true)"
    if [ "$host_db_h" != "$ctr_db_h" ] || [ "$host_root_h" != "$ctr_root_h" ]; then
        DB_NEEDS_RECREATE=true
    fi

    if [ "$recorded_hash" = "$current_hash" ] && [ "$DB_NEEDS_RECREATE" = false ] \
        && mariadb_probe_pw "$db_cid" "$db_user" "$host_db_pw" "$db_name"; then
        return 0
    fi

    if mariadb_probe_pw "$db_cid" "$db_user" "$host_db_pw" "$db_name" \
        && mariadb_probe_pw "$db_cid" "root" "$host_root_pw"; then
        return 0
    fi

    [[ "$db_name" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid DB_DATABASE name '$db_name'."
    [[ "$db_user" =~ ^[A-Za-z0-9_]+$ ]] || die "Invalid DB_USERNAME name '$db_user'."

    local auth_pw="" sync_root=false
    if mariadb_probe_pw "$db_cid" "root" "$host_root_pw"; then
        auth_pw="$host_root_pw"
    else
        local ctr_root_pw
        ctr_root_pw="$(podman exec "$db_cid" sh -c 'tr -d "\r\n" < /run/secrets/db_root_password 2>/dev/null' 2>/dev/null || true)"
        if [ -n "$ctr_root_pw" ] && mariadb_probe_pw "$db_cid" "root" "$ctr_root_pw"; then
            auth_pw="$ctr_root_pw"
            sync_root=true
        else
            die "Database root authentication failed on '$db_cid'. Restore matching secrets/db_root_password (or reset an empty DB with: podman compose down -v)."
        fi
    fi

    local db_pw_sql root_pw_sql sql
    db_pw_sql="$(sql_escape "$host_db_pw")"
    root_pw_sql="$(sql_escape "$host_root_pw")"
    sql="CREATE DATABASE IF NOT EXISTS \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${db_user}'@'%' IDENTIFIED BY '${db_pw_sql}';
ALTER USER '${db_user}'@'%' IDENTIFIED BY '${db_pw_sql}';
GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'%';"
    if $sync_root; then
        sql="${sql}
ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY '${root_pw_sql}';
ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '${root_pw_sql}';"
    fi
    sql="${sql}
FLUSH PRIVILEGES;"

    { printf '%s\n' "$auth_pw"; printf '%s\n' "$sql"; } \
        | podman exec -i "$db_cid" sh -c 'IFS= read -r MYSQL_PWD; export MYSQL_PWD; exec mariadb -uroot' \
        || die "Failed to synchronize MariaDB credentials."

    ok "Synchronized MariaDB credentials for '$db_user' / '$db_name' (existing data preserved)."
}

cmd_deploy() {
    local deploy_args=("$@")
    local DB_NEEDS_RECREATE=false
    local mode="deploy" expect="" fresh=false do_pull=true do_backup=true
    while [ $# -gt 0 ]; do
        case "$1" in
            --fresh)       fresh=true ;;
            --no-pull)     do_pull=false ;;
            --skip-backup) do_backup=false ;;
            --rollback)    mode="rollback" ;;
            --expect)      expect="${2:-}"; shift ;;
            --help|-h)     usage; exit 0 ;;
            *) die "Unknown deploy argument: $1" ;;
        esac
        shift
    done

    mkdir -p "$STATE_DIR"
    exec 9>"$STATE_DIR/lock"
    flock -n 9 || die "Another deploy is already running."

    check_deploy_host_ready

    local engine="podman"
    local compose=(podman compose)

    wait_healthy() {
        local waited=0 cid state status restarts seen_running=false
        echo "Waiting for app container to respond to HTTP health probes (max ${HEALTH_TIMEOUT}s)..."
        while [ "$waited" -lt "$HEALTH_TIMEOUT" ]; do
            cid="$(service_cid app all)"
            if [ -n "$cid" ]; then
                state="$($engine inspect -f '{{.State.Status}} {{.RestartCount}}' "$cid" 2>/dev/null || echo 'unknown 0')"
                status="${state%% *}"
                restarts="${state#* }"
                [ "$status" = "running" ] && seen_running=true
                if [ "$status" = "exited" ] || [ "$status" = "dead" ] \
                    || { $seen_running && [ "$status" = "stopped" ]; } \
                    || [ "${restarts:-0}" -ge 2 ]; then
                    warn "App container ($cid) failed to stay up (state: $status, restarts: ${restarts:-0})."
                    return 1
                fi
                # Real request through Caddy + PHP + DB (not /healthz, which bypasses PHP)
                if [ "$status" = "running" ] && $engine exec "$cid" wget -q -O /dev/null http://127.0.0.1:8080/login 2>/dev/null; then
                    ok "App container is responsive and healthy."
                    return 0
                fi
            fi
            sleep 1
            waited=$((waited + 1))
        done
        warn "Health check timed out after ${HEALTH_TIMEOUT}s."
        return 1
    }

    release_stack() {
        local db_running
        db_running="$(service_cid db)"
        if [ -n "$db_running" ] && [ "$DB_NEEDS_RECREATE" = false ]; then
            # Keep MariaDB warm; explicitly stop/remove app, log-worker & worker before recreating
            # so Debian's podman-compose never fails with 'container name is already in use'.
            "${compose[@]}" stop -t 10 app log-worker worker 2>/dev/null || true
            $engine rm -f "${APP_SLUG}_app_1" "${APP_SLUG}_log-worker_1" "${APP_SLUG}_worker_1" 2>/dev/null || true
            "${compose[@]}" up -d --no-deps app log-worker worker || return 1
        else
            "${compose[@]}" down 2>/dev/null || true
            $engine rm -f "${APP_SLUG}_app_1" "${APP_SLUG}_log-worker_1" "${APP_SLUG}_worker_1" "${APP_SLUG}_db_1" 2>/dev/null || true
            "${compose[@]}" up -d --remove-orphans || return 1
        fi
        wait_healthy
    }

    if [ "$mode" = "rollback" ]; then
        ensure_server_prereqs
        step "Rolling back to the previous release"
        $engine image inspect "$IMAGE:previous" >/dev/null 2>&1 || die "No previous image ($IMAGE:previous) to roll back to."
        local prev_commit
        prev_commit="$(cat "$STATE_DIR/previous_commit" 2>/dev/null || true)"
        if [ -n "$prev_commit" ]; then
            git reset --hard --quiet "$prev_commit"
            ok "Code reset to $prev_commit"
        fi
        $engine tag "$IMAGE:previous" "$IMAGE:latest"
        release_stack || die "Rolled-back release is not healthy. Check: ${compose[*]} logs app"
        [ -n "$prev_commit" ] && echo "$prev_commit" > "$STATE_DIR/current_commit"
        warn "Database migrations are NOT reverted. Restore a dump from backup if needed."
        ok "Rollback complete."
        exit 0
    fi

    if $do_pull; then
        local pre_pull_sha post_pull_sha
        pre_pull_sha="$(git rev-parse HEAD 2>/dev/null || true)"
        sync_master_branch
        post_pull_sha="$(git rev-parse HEAD 2>/dev/null || true)"
        if [ -n "$pre_pull_sha" ] && [ "$pre_pull_sha" != "$post_pull_sha" ] \
            && ! git diff --quiet "$pre_pull_sha" "$post_pull_sha" -- ops scripts/server.sh 2>/dev/null; then
            exec 9>&-
            exec "$APP_DIR/scripts/server.sh" deploy --no-pull "${deploy_args[@]}"
        fi
    fi

    ensure_server_prereqs

    local sha active_commit
    sha="$(git rev-parse --short HEAD)"
    if [ -n "$expect" ] && [ "${expect:0:7}" != "${sha:0:7}" ]; then
        die "Server is at $sha but $expect was expected."
    fi
    echo -e "${c_b}Deploying $sha — $(git log -1 --pretty=%s)${c_n}"

    step "[1/4] Building image"
    active_commit="$(cat "$STATE_DIR/current_commit" 2>/dev/null || true)"
    if $engine image inspect "$IMAGE:latest" >/dev/null 2>&1; then
        $engine tag "$IMAGE:latest" "$IMAGE:previous"
        if [ -n "$active_commit" ] && [ "$active_commit" != "$sha" ]; then
            printf '%s\n' "$active_commit" > "$STATE_DIR/previous_commit"
        fi
    fi

    local fresh_stamp="$STATE_DIR/last_fresh_build"
    if [ ! -f "$fresh_stamp" ] || [ -n "$(find "$fresh_stamp" -mtime +"$((FRESH_BUILD_DAYS - 1))" 2>/dev/null)" ]; then
        fresh=true
    fi
    if $fresh; then
        echo "Full rebuild (--no-cache): refreshing OS packages and dependencies."
        "${compose[@]}" build --pull --no-cache app
        touch "$fresh_stamp"
    else
        "${compose[@]}" build --pull app
    fi
    $engine tag "$IMAGE:latest" "$IMAGE:$sha"
    ok "Image $IMAGE:$sha built."

    step "[2/4] Backing up database and uploads"
    if $do_backup; then
        cmd_backup "pre-deploy-$sha"
    else
        warn "Pre-deploy backup skipped (--skip-backup)."
    fi

    step "[3/4] Releasing (migrations & seeders run in the app entrypoint)"
    reconcile_db_credentials
    if release_stack; then
        printf '%s\n' "$sha" > "$STATE_DIR/current_commit"
        db_creds_hash > "$STATE_DIR/db_creds.sha256"
        if [ -s "$SECRETS_DIR/admin_password" ]; then
            write_secret "admin_password" ""
            ok "Cleared initial admin_password secret after bootstrap."
        fi
        ok "Release $sha is healthy."
    else
        warn "New release failed health checks. Last app logs:"
        "${compose[@]}" logs --tail 40 app || true
        if $engine image inspect "$IMAGE:previous" >/dev/null 2>&1; then
            warn "Automatic rollback to the previous image..."
            $engine tag "$IMAGE:previous" "$IMAGE:latest"
            release_stack && warn "Previous image restored (code dir still at $sha; run './ops rollback' to sync)." \
                          || warn "Previous image is not healthy either!"
        fi
        die "Deploy of $sha failed."
    fi

    step "Checking LogMonitor connectivity"
    if "${compose[@]}" exec -T -e RELEASE="$sha" app php -r '
        require "/var/www/html/config/bootstrap.php";
        $ok = \App\Core\LogViaStream::send(\App\Enums\LogLevel::INFO->value, "Deploy completed", [
            "identifier" => "Deploy_Completed",
            "release" => getenv("RELEASE"),
        ]);
        exit($ok ? 0 : 1);'; then
        ok "LogMonitor accepted the deploy log ($(env_value LOG_SERVER_URL || true))."
    else
        warn "LogMonitor did not accept the log (release stays live). Check LOG_SERVER_URL / LOG_API_KEY."
    fi

    step "[4/4] Cleaning up"
    $engine image prune -f >/dev/null 2>&1 || true
    $engine image ls "$IMAGE" --format '{{.Tag}}' | grep -vE '^(latest|previous|<none>)$' | tail -n +4 \
        | xargs -r -I{} $engine rmi "$IMAGE:{}" >/dev/null 2>&1 || true
    "${compose[@]}" ps
    ok "Deploy of $sha complete."
}

cmd_status() {
    require_podman
    local backup_dir
    backup_dir="${BACKUP_DIR:-$(env_value BACKUP_DIR || true)}"
    backup_dir="${backup_dir:-$HOME/backups/${APP_SLUG}}"

    echo "release:  $(cat "$STATE_DIR/current_commit" 2>/dev/null || echo n/a)"
    echo "previous: $(cat "$STATE_DIR/previous_commit" 2>/dev/null || echo n/a)"
    if [ -d "$backup_dir" ]; then
        echo "backups:  $(du -sh "$backup_dir" 2>/dev/null | cut -f1) in $backup_dir"
    fi
    echo
    podman compose ps
    if [ -n "$(podman ps -q 2>/dev/null)" ]; then
        echo
        podman stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}'
    fi
}

cmd_logs() {
    require_podman
    if [ $# -gt 0 ]; then
        exec podman compose logs -f --tail 100 "$@"
    else
        exec podman compose logs -f --tail 100 app log-worker worker
    fi
}

cmd="${1:-help}"
[ $# -gt 0 ] && shift

case "$cmd" in
    deploy)              cmd_deploy "$@" ;;
    rollback)            cmd_deploy --rollback "$@" ;;
    backup)              cmd_backup "$@" ;;
    setup|server-setup)  cmd_setup "$@" ;;
    egress)              cmd_egress "$@" ;;
    status)              cmd_status ;;
    logs)                cmd_logs "$@" ;;
    help|-h|--help)      usage ;;
    *)
        echo -e "${c_r}Unknown server command: $cmd${c_n}" >&2
        usage
        exit 1
        ;;
esac
