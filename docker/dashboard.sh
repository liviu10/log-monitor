#!/bin/bash
# Dashboard de monitorizare unificata pentru LogMonitor
# Rulati acest script de pe masina gazda (Fedora)

# Abordare Fail-Fast: Ne asiguram ca avem acces la containerele active
if ! podman ps | grep -q "log-monitor"; then
    echo "EROARE: Niciun container din proiectul log-monitor nu ruleaza."
    exit 1
fi

LOG_FILE="test_performanta_5000_loguri.txt"

# ------------------------------------------------------------------------
# FAZA DE PREGATIRE AUTOMATA A TESTULUI
# ------------------------------------------------------------------------
echo "=== INITIALIZARE AUTOMATA SCHEMA TEST ==="
echo "1. Oprire defensiva log-monitor-worker..."
podman stop log-monitor-worker >/dev/null 2>&1

echo "2. Curatare tabela log_queue (Truncate)..."
podman exec log-monitor-db mariadb -u user -p"password" -e "TRUNCATE TABLE log_queue;" log_monitor >/dev/null 2>&1

echo "3. Lansare simulator in interiorul containerului..."
# Pornim simulatorul in fundal (folosim -i in loc de -it pentru procese de background)
podman exec -i log-monitor-app php bin/simulate-logs.php &
SIM_PID=$!

echo "4. Pornire monitorizare activa..."
echo "=== START TEST ULTRA-SIMPLIFICAT: $(date) ===" > "$LOG_FILE"
sleep 1

# ------------------------------------------------------------------------
# BUCLA DINAMICA: Ruleaza STRICT cat timp simulatorul este in viata
# ------------------------------------------------------------------------
while kill -0 $SIM_PID 2>/dev/null; do
    clear
    echo "========================================================================"
    echo "       PANOU DE CONTROL SI MONITORIZARE REAL-TIME - LOGMONITOR"
    echo "       Marcaj Timp Gazda: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "       STATUS SIMULATOR: INJECTEAZA DATE (PROCES ACTIV: $SIM_PID)"
    echo "========================================================================"
    
    echo ""
    echo "1. CONSUM RESURSE (PODMAN STATS):"
    echo "------------------------------------------------------------------------"
    podman stats --no-stream $(podman ps -qf "name=log-monitor")
    
    echo ""
    echo "2. STARE WORKER DAEMON IN SISTEM:"
    echo "------------------------------------------------------------------------"
    podman ps -a -f "name=log-monitor-worker" --format "Container: {{.Names}} | Stare curenta: {{.Status}}"
    
    echo ""
    echo "3. STARE INCHIDERE COZI (MARIADB METRICS):"
    echo "------------------------------------------------------------------------"
    podman exec log-monitor-db mariadb -u user -p"password" -e "
        SELECT 
            (SELECT COUNT(*) FROM log_queue) AS 'LOGURI IN COADA (QUEUE)',
            (SELECT COUNT(*) FROM logs) AS 'LOGURI PROCESATE FINAL (LOGS)';
    " log_monitor 2>/dev/null
    
    echo "========================================================================"
    echo " Status: Simulatorul trimite date. Scriptul se va inchide singur la final."
    echo "========================================================================"
    
    # Salveaza automat istoricul si in fisierul tau text de performanta
    echo "--- Marcaj: $(date '+%H:%M:%S') ---" >> "$LOG_FILE"
    podman stats --no-stream $(podman ps -qf "name=log-monitor") >> "$LOG_FILE"
    
    sleep 1
done

# ------------------------------------------------------------------------
# FAZA DE FINALIZARE AUTOMATA (ZERO IDLE RUN)
# ------------------------------------------------------------------------
clear
echo "========================================================================"
echo "       TEST INGESTIE COADA FINALIZAT! S-A OPRIT AUTOMAT"
echo "       Marcaj Timp Gazda: $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================================================"
echo ""
echo "IMAGINEA REALA A BAZEI DE DATE LA INCHIDEREA SIMULATORULUI:"
echo "------------------------------------------------------------------------"
podman exec log-monitor-db mariadb -u user -p"password" -e "
    SELECT 
        (SELECT COUNT(*) FROM log_queue) AS 'LOGURI PARCATE IN COADA (QUEUE)',
        (SELECT COUNT(*) FROM logs) AS 'LOGURI PROCESATE VECHI (LOGS)';
" log_monitor 2>/dev/null
echo ""
echo "========================================================================"
echo " Succes! Terminalul a fost eliberat automat."
echo " Istoricul resurselor a fost salvat in: $LOG_FILE"
echo " Pentru a porni devorarea cozii, rulati: podman start log-monitor-worker"
echo "========================================================================"
echo "=== REZULTAT FINAL SALVAT IN EXECUTIE: $(date) ===" >> "$LOG_FILE"