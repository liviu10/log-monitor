#!/bin/bash

# ==========================================
# GHID DE INSTALARE SI CONFIGURARE SYSTEMD
# ==========================================
# Urmeaza acesti pasi pentru a rula scriptul ca serviciu permanent:
#
# 1. Copiaza scriptul in directorul de binarie local:
#    sudo cp mint-log-shipper.sh /usr/local/bin/
#
# 2. Seteaza permisiunile corecte (proprietar root si drept de executie):
#    sudo chown root:root /usr/local/bin/mint-log-shipper.sh
#    sudo chmod +x /usr/local/bin/mint-log-shipper.sh
#
# 3. Creeaza fisierul de serviciu Systemd:
#    sudo nano /etc/systemd/system/logmonitor-shipper.service
#
#    --- Adauga urmatorul continut in fisierul .service: ---
#    [Unit]
#    Description=LogMonitor Systemd Log Shipper
#    After=network.target
#
#    [Service]
#    Type=simple
#    ExecStart=/usr/local/bin/mint-log-shipper.sh
#    Restart=always
#    RestartSec=5
#
#    [Install]
#    WantedBy=multi-user.target
#    -------------------------------------------------------
#
# 4. Reincarca configuratia Systemd, porneste si activeaza serviciul la boot:
#    sudo systemctl daemon-reload
#    sudo systemctl enable logmonitor-shipper.service
#    sudo systemctl start logmonitor-shipper.service
#
# 5. Comenzi utile pentru mentenanta:
#    Verificare status:   sudo systemctl status logmonitor-shipper.service
#    Repornire serviciu:  sudo systemctl restart logmonitor-shipper.service
#    Oprire serviciu:     sudo systemctl stop logmonitor-shipper.service
#    Vizualizare loguri:  sudo journalctl -u logmonitor-shipper.service -f

# ==========================================
# CONFIGURARE LOGMONITOR SHIPPER
# ==========================================
API_URL="http://localhost:8080/log.php"
API_KEY="cheia_mea_secreta" # Modifica aici cu cheia ta reala generata in panou!

# ==========================================
# VERIFICARE SI INSTALARE AUTOMATA JQ
# ==========================================
if ! command -v jq &> /dev/null; then
    echo "[CONECTARE] 'jq' nu este instalat. Se initiaza instalarea automata..."
    
    # Actualizam cache-ul apt in liniste si instalam jq fara a cere confirmare (-y)
    apt-get update -y &> /dev/null
    apt-get install -y jq
    
    # O scurta verificare de siguranta dupa instalare
    if [ $? -eq 0 ]; then
        echo "[SUCCES] 'jq' a fost instalat cu succes. Se continua rularea scriptului."
    else
        echo "[EROARE] Nu s-a putut instala 'jq'. Scriptul are nevoie de internet si privilegii root."
        exit 1
    fi
fi

# ==========================================
# FUNCTIA CENTRALA DE EXPEDIERE (HTTP POST)
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
# SURSA CENTRALA: MONITORIZARE SYSTEMD
# ==========================================
monitor_systemd() {
    # Ascultam doar prioritatile de la 0 (Emergency) pana la 3 (Error) in format JSON
    # Am eliminat complet prioritatea 4 (Warning) pentru a reduce volumul de loguri zgomotoase
    journalctl -p 0..3 -f --output=json | while read -r line; do
        local msg=$(echo "$line" | jq -r '.MESSAGE')
        local priority=$(echo "$line" | jq -r '.__PRIORITY')
        local unit=$(echo "$line" | jq -r '._SYSTEMD_UNIT // "kernel"')

        # ANTIBUCLA: Ignoram logurile generate de propriul nostru ecosistem LogMonitor
        if echo "$msg" | grep -Ei 'log-monitor|podman-log|mariadb|frankenphp' > /dev/null; then
            continue
        fi

        # Mapare inteligenta a nivelurilor in functie de prioritatea systemd (fara Warning)
        local lvl="ERROR"
        case "$priority" in
            3)
                lvl="ERROR"
                ;;
            0|1|2)
                lvl="CRITICAL"
                ;;
            *)
                lvl="ERROR"
                ;;
        esac

        # Fortam CRITICAL daca mesajul vine din kernel si indica probleme grave sau lipsa de RAM
        if [ "$unit" = "kernel" ] && echo "$msg" | grep -Ei 'panic|hardware error|mcelog|oom-killer|out of memory' > /dev/null; then
            lvl="CRITICAL"
        fi

        local exec_name=$(echo "$line" | jq -r '._COMM // "Unknown"')
        local pid=$(echo "$line" | jq -r '._PID // "N/A"')
        local hostname=$(echo "$line" | jq -r '._HOSTNAME // "Linux-Mint"')
        
        # Extragem codul de eroare din sistem (ERRNO) daca acesta exista pentru un plus de context
        local errno_hint=$(echo "$line" | jq -r '.ERRNO // ""')
        local real_hint="Host: $hostname | Unit: $unit | PID: $pid"
        if [ -n "$errno_hint" ] && [ "$errno_hint" != "null" ]; then
            real_hint="$real_hint | Errno: $errno_hint"
        fi

        send_log "$lvl" "$msg" "systemd://$unit" "Process: $exec_name" "$real_hint"
    done
}

# Porneste monitorizarea imbunatatita in fundal
monitor_systemd &

# Mentina scriptul principal in viata
wait