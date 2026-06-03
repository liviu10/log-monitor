#!/bin/bash

# ==========================================
# CONFIGURARE LOGMONITOR SHIPPER
# ==========================================
API_URL="http://localhost:8080/log.php"
API_KEY="cheia_mea_secreta" # Modifică aici cu cheia ta reală generată în panou!

# Asigură-te că jq este instalat (este necesar pentru formatarea JSON)
if ! command -v jq &> /dev/null; then
    echo "Eroare: 'jq' nu este instalat. Rulează: sudo apt install jq"
    exit 1
fi

# ==========================================
# FUNCȚIA CENTRALĂ DE EXPEDIERE (HTTP POST)
# ==========================================
send_log() {
    local level="$1"
    local message="$2"
    local source="$3"
    local failed_process="${4:-"N/A"}"      
    local root_cause_hint="${5:-"N/A"}"    

    PAYLOAD=$(jq -n \
              --arg lvl "$level" \
              --arg msg "$message" \
              --arg src "$source" \
              --arg proc "$failed_process" \
              --arg hint "$root_cause_hint" \
              '{
                 level: $lvl, 
                 message: $msg, 
                 context: {
                     source: $src, 
                     OS: "Linux Mint", 
                     failed_process: $proc, 
                     root_cause_hint: $hint
                 }
              }')

    curl -s -X POST "$API_URL" \
         -H "X-API-KEY: $API_KEY" \
         -H "Content-Type: application/json" \
         -H "User-Agent: MintLogShipper/1.0" \
         -d "$PAYLOAD" > /dev/null &
}

# ==========================================
# SURSA 1: MONITORIZARE SYSTEMD + KERNEL (SIGURĂ)
# ==========================================
monitor_systemd() {
    journalctl -p 3 -f --output=json | while read -r line; do
        local msg=$(echo "$line" | jq -r '.MESSAGE')
        local priority=$(echo "$line" | jq -r '.__PRIORITY')
        local unit=$(echo "$line" | jq -r '._SYSTEMD_UNIT // "kernel"')

        # ANTIBUCLĂ: Ignorăm logurile generate de propriul nostru ecosistem LogMonitor
        if echo "$msg" | grep -Ei 'log-monitor|podman-log|mariadb|frankenphp' > /dev/null; then
            continue
        fi

        if [ "$priority" = "null" ] || [ -z "$priority" ]; then
            priority=3
        fi

        local lvl="ERROR"
        if [ "$priority" -le 1 ]; then lvl="CRITICAL"; fi

        local exec_name=$(echo "$line" | jq -r '._COMM // "Unknown"')
        local pid=$(echo "$line" | jq -r '._PID // "N/A"')
        local real_hint="Service Unit: $unit | Process ID: $pid"

        send_log "$lvl" "$msg" "systemd://$unit" "Process: $exec_name" "$real_hint"
    done
}

# ==========================================
# SURSA 2: MONITORIZARE FIȘIERE TEXT (IZOLATĂ & PROTEJATĂ)
# ==========================================
monitor_text_files() {
    # EXCLUDEM folderul podman/docker și fișierele sistem globale (syslog, kern.log) 
    # deoarece ele sunt deja acoperite 100% de monitor_systemd() de mai sus!
    find /var/log -type f -mtime -2 \
        ! -name "*syslog*" \
        ! -name "*kern*" \
        ! -name "*auth*" \
        ! -name "*journal*" \
        ! -name "*.gz" \
        ! -name "*.wtmp" \
        ! -name "*.btmp" 2>/dev/null | while read -r log_file; do
        
        tail -Fn0 "$log_file" 2>/dev/null | while read -r line; do

            # Ignorăm linii care conțin activitatea serverului nostru local
            if echo "$line" | grep -Ei 'log-monitor|podman|mariadb' > /dev/null; then
                continue
            fi

            # Prinde doar cuvinte cu adevărat alarmante în logurile aplicațiilor terțe
            if echo "$line" | grep -Ei 'panic|alert|emerg|critical|kernel panic' > /dev/null; then
                
                # În loc de un alt sub-tail recursiv care punea sistemul în genunchi, 
                # trimitem linia curentă și metadatele sigure ale fișierului
                local file_info="File path: $log_file | Check lines manually to avoid read-loops"

                send_log "CRITICAL" "$line" "file://$log_file" "File: $(basename "$log_file")" "$file_info"
            fi
        done &
    done
}

# Pornește ambele surse în fundal în mod securizat
monitor_systemd &
monitor_text_files &

# Menține scriptul principal în viață
wait