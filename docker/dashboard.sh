#!/bin/bash
# ========================================================================
#   DASHBOARD DE BENCHMARK & OPTIMIZARE - INGESTIE & PROCESARE COADA
# ========================================================================
# Rulati acest script de pe masina gazda (Fedora)

export LC_ALL=C

# Verificare mediu (APP_ENV trebuie sa fie 'development')
ENV_FILE="../.env"
if [ -f "$ENV_FILE" ]; then
    APP_ENV=$(grep -E '^APP_ENV=' "$ENV_FILE" | cut -d'=' -f2 | tr -d '"'\'' ')
else
    APP_ENV="production"
fi

if [ "$APP_ENV" != "development" ]; then
    echo "EROARE: Acest script de benchmark poate fi rulat doar in mediul de 'development'."
    echo "Valoarea curenta a APP_ENV este: '$APP_ENV'."
    exit 1
fi

# Abordare Fail-Fast
if ! podman ps | grep -q "log-monitor"; then
    echo "EROARE: Niciun container din proiectul log-monitor nu ruleaza."
    exit 1
fi

LOG_FILE="../storage/reports/test_performanta_50000_loguri.txt"
RAW_STATS_FILE="/tmp/log_monitor_raw_stats.txt"
START_STATS_FILE="/tmp/log_monitor_start_stats.txt"
END_STATS_FILE="/tmp/log_monitor_end_stats.txt"
BASELINE_FILE="/tmp/log_monitor_baseline.txt"

# Curatam fisierele vechi
mkdir -p ../storage/reports
rm -f "$LOG_FILE" "$RAW_STATS_FILE" "$START_STATS_FILE" "$END_STATS_FILE" "$BASELINE_FILE"

# Numarul de loguri de ingerat
NUM_LOGS=50000

echo "========================================================================"
echo "      BENCHMARK PERFORMANCE TEST - LOG MONITOR ($NUM_LOGS LOGURI)"
echo "      Marcaj Timp Gazda: $(date '+%Y-%m-%d %H:%M:%S')"
echo "========================================================================"
echo ""

# ------------------------------------------------------------------------
# PREGĂTIRE MEDIU
# ------------------------------------------------------------------------
echo "1. Pregatire mediu de test..."
echo "   - Oprire worker..."
podman stop log-monitor-worker >/dev/null 2>&1
echo "   - Curatare tabele logs si log_queue..."
podman exec log-monitor-db mariadb -u user -p"password" -e "SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE log_queue; TRUNCATE TABLE logs; SET FOREIGN_KEY_CHECKS = 1;" log_monitor >/dev/null 2>&1
echo "   - Status baza de date curatata cu succes."
echo ""
echo "   - Masurare consum de baza (idle baseline) pentru 3 secunde..."
# Citim stats de 3 ori intr-un interval de 3 secunde pentru a face o medie stabila
for i in {1..3}; do
    podman stats --no-stream --format "{{.Name}},{{.CPUPerc}}" >> "$BASELINE_FILE" 2>/dev/null
    sleep 1
done
echo "   - Masurare baseline finalizata."
echo ""

# Funcție pentru conversia dimensiunilor în MB în AWK
AWK_CONVERT="
function to_mb(str,   val, unit) {
    gsub(/^[ \t\r\n]+|[ \t\r\n]+$/, \"\", str);
    match(str, /[0-9.]+/);
    val = substr(str, RSTART, RLENGTH) + 0;
    unit = substr(str, RSTART + RLENGTH);
    gsub(/[ \t\r\n]+/, \"\", unit);
    unit = tolower(unit);
    
    if (unit ~ /g/) return val * 1024;
    if (unit ~ /m/) return val;
    if (unit ~ /k/) return val / 1024;
    return val / 1024 / 1024;
}
"

# ------------------------------------------------------------------------
# FAZA 1: INGESTIE LOGURI
# ------------------------------------------------------------------------
echo "========================================================================"
echo "      FAZA 1: INGESTIE (SIMULATOR -> APP -> QUEUE DB)"
echo "========================================================================"
echo "Salvare date de pornire I/O..."
podman stats --no-stream --format "{{.Name}},{{.BlockIO}}" > "$START_STATS_FILE"

INGEST_START_TIME=$(date +%s.%N)

# Pornim simulatorul pentru 50.000 loguri in fundal
podman exec -i log-monitor-app php bin/simulate-logs.php $NUM_LOGS &
SIM_PID=$!

echo "Simulator lansat (PID: $SIM_PID). Monitorizare activa..."

# Bucla de monitorizare a ingestiei
while kill -0 $SIM_PID 2>/dev/null; do
    # Capturam statisticile in timp real
    podman stats --no-stream --format "ingest,{{.Name}},{{.CPUPerc}},{{.MemUsage}},{{.BlockIO}}" >> "$RAW_STATS_FILE" 2>/dev/null
    
    # Afisam progresul in consola din DB
    QUEUE_COUNT=$(podman exec log-monitor-db mariadb -u user -p"password" -N -e "SELECT COUNT(*) FROM log_queue;" log_monitor 2>/dev/null | tr -d '\r\n')
    echo -ne "   -> Progres Ingestie: $QUEUE_COUNT / $NUM_LOGS loguri plasate in coada...\r"
    sleep 1
done
echo ""
INGEST_END_TIME=$(date +%s.%N)

# Salvare date I/O dupa ingestie
podman stats --no-stream --format "{{.Name}},{{.BlockIO}}" > "$END_STATS_FILE"

INGEST_DURATION=$(awk -v start="$INGEST_START_TIME" -v end="$INGEST_END_TIME" 'BEGIN {print end - start}')
echo "Ingestie finalizata in: $(printf "%.2f" $INGEST_DURATION) secunde."
echo ""
echo "   - Perioada de racire de 20 secunde pentru a stabiliza metricile si a preveni contaminarea..."
sleep 20
echo ""

# ------------------------------------------------------------------------
# FAZA 2: PROCESARE COADA (WORKER)
# ------------------------------------------------------------------------
echo "========================================================================"
echo "      FAZA 2: PROCESARE COADA (QUEUE DB -> WORKER -> LOGS DB)"
echo "========================================================================"
echo "Salvare date de pornire I/O Worker..."
podman stats --no-stream --format "{{.Name}},{{.BlockIO}}" >> "$START_STATS_FILE"

PROC_START_TIME=$(date +%s.%N)

echo "Pornire log-monitor-worker..."
podman start log-monitor-worker >/dev/null

QUEUE_COUNT=1
# Bucla de monitorizare a procesarii
while [ $QUEUE_COUNT -gt 0 ]; do
    QUEUE_COUNT=$(podman exec log-monitor-db mariadb -u user -p"password" -N -e "SELECT COUNT(*) FROM log_queue;" log_monitor 2>/dev/null | tr -d '\r\n')
    # Daca interogarea esueaza, consideram 0
    if [ -z "$QUEUE_COUNT" ]; then QUEUE_COUNT=0; fi
    
    # Capturam statisticile in timp real
    podman stats --no-stream --format "process,{{.Name}},{{.CPUPerc}},{{.MemUsage}},{{.BlockIO}}" >> "$RAW_STATS_FILE" 2>/dev/null
    
    # Afisam progresul
    PROCESSED_COUNT=$(podman exec log-monitor-db mariadb -u user -p"password" -N -e "SELECT COUNT(*) FROM logs;" log_monitor 2>/dev/null | tr -d '\r\n')
    echo -ne "   -> Loguri ramase in coada: $QUEUE_COUNT | Loguri salvate final: $PROCESSED_COUNT\r"
    
    sleep 1
done
echo ""
PROC_END_TIME=$(date +%s.%N)

# Salvare date I/O la finalul procesarii
podman stats --no-stream --format "{{.Name}},{{.BlockIO}}" >> "$END_STATS_FILE"

PROC_DURATION=$(awk -v start="$PROC_START_TIME" -v end="$PROC_END_TIME" 'BEGIN {print end - start}')
echo "Procesare finalizata in: $(printf "%.2f" $PROC_DURATION) secunde."
echo ""

# Oprim worker-ul la finalizarea testului
echo "Oprire defensiva worker..."
podman stop log-monitor-worker >/dev/null 2>&1
echo ""

# ------------------------------------------------------------------------
# ANALIZĂ STATISTICI ȘI GENERARE RAPORT
# ------------------------------------------------------------------------
echo "========================================================================"
echo "      GENERARE RAPORT DE PERFORMANTA..."
echo "========================================================================"

# Generam raportul folosind AWK pe baza fisierului RAW_STATS_FILE si diferentelor I/O
# Citim mai intai start si end stats pentru a calcula consumul de disc real

awk -F, -v ingest_dur="$INGEST_DURATION" -v proc_dur="$PROC_DURATION" -v num_logs="$NUM_LOGS" '
'"$AWK_CONVERT"'

# Incarcam datele de pornire si oprire I/O
# Fisierele sunt citite inainte de a procesa datele raw
BEGIN {
    # Incarcam I/O de inceput
    while ((getline < "'"$START_STATS_FILE"'") > 0) {
        name = $1;
        split($2, io, "/");
        start_read[name] = to_mb(io[1]);
        start_write[name] = to_mb(io[2]);
    }
    close("'"$START_STATS_FILE"'");

    # Incarcam I/O de sfarsit
    while ((getline < "'"$END_STATS_FILE"'") > 0) {
        name = $1;
        split($2, io, "/");
        end_read[name] = to_mb(io[1]);
        end_write[name] = to_mb(io[2]);
    }
    close("'"$END_STATS_FILE"'");

    # Incarcam consumul de baza (baseline)
    while ((getline < "'"$BASELINE_FILE"'") > 0) {
        name = $1;
        cpu_str = $2;
        gsub(/%/, "", cpu_str);
        cpu = cpu_str + 0;
        baseline_sum[name] += cpu;
        baseline_count[name]++;
    }
    close("'"$BASELINE_FILE"'");
    
    for (name in baseline_sum) {
        if (baseline_count[name] > 0) {
            baseline[name] = baseline_sum[name] / baseline_count[name];
        }
    }
}

# Procesam datele raw de consum periodic
{
    phase = $1;
    name = $2;
    cpu_str = $3;
    mem_str = $4;
    
    gsub(/%/, "", cpu_str);
    cpu = cpu_str + 0;
    
    # Scadem consumul de baza (baseline) pentru a obtine consumul activ net
    if (name in baseline) {
        cpu = cpu - baseline[name];
        if (cpu < 0) cpu = 0;
    }
    
    split(mem_str, mem_arr, "/");
    mem = to_mb(mem_arr[1]);
    
    # Cheie pentru agregare generala si pe faza
    key_general = name;
    key_phase = phase FS name;
    
    # Agregari generale
    samples[key_general]++;
    sum_cpu[key_general] += cpu;
    if (cpu > max_cpu[key_general]) max_cpu[key_general] = cpu;
    sum_mem[key_general] += mem;
    if (mem > max_mem[key_general]) max_mem[key_general] = mem;
    
    # Agregari pe faza
    samples_ph[key_phase]++;
    sum_cpu_ph[key_phase] += cpu;
    if (cpu > max_cpu_ph[key_phase]) max_cpu_ph[key_phase] = cpu;
    sum_mem_ph[key_phase] += mem;
    if (mem > max_mem_ph[key_phase]) max_mem_ph[key_phase] = mem;
    
    containers[name] = 1;
}

END {
    # Printam un raport superb
    print "# RAPORT DETALIAT DE PERFORMANTA - SIMULARE " num_logs " LOGURI"
    print ""
    print "## 1. TIMPI SI RATE DE INGESTIE / PROCESARE"
    print "| Faza | Timp total (s) | Viteza medie (logs/s) |"
    print "| :--- | :---: | :---: |"
    printf "| Ingestie (Simulator -> DB Queue) | %.2fs | %.1f logs/s |\n", ingest_dur, num_logs / ingest_dur
    printf "| Procesare (DB Queue -> Logs DB)  | %.2fs | %.1f logs/s |\n", proc_dur, num_logs / proc_dur
    print ""
    
    print "## 2. CONSUM RESURSE PE CONTAINER (MEDII SI PEAK)"
    print "| Container | Faza | Avg CPU | Max CPU | Avg RAM | Max RAM |"
    print "| :--- | :--- | :---: | :---: | :---: | :---: |"
    
    for (c in containers) {
        # Faza Ingestie
        k_ing = "ingest" FS c;
        if (samples_ph[k_ing] > 0) {
            printf "| %s | Ingestie | %.2f%% | %.2f%% | %.2f MB | %.2f MB |\n",
                c, sum_cpu_ph[k_ing]/samples_ph[k_ing], max_cpu_ph[k_ing], sum_mem_ph[k_ing]/samples_ph[k_ing], max_mem_ph[k_ing]
        }
        # Faza Procesare
        k_prc = "process" FS c;
        if (samples_ph[k_prc] > 0) {
            printf "| %s | Procesare | %.2f%% | %.2f%% | %.2f MB | %.2f MB |\n",
                c, sum_cpu_ph[k_prc]/samples_ph[k_prc], max_cpu_ph[k_prc], sum_mem_ph[k_prc]/samples_ph[k_prc], max_mem_ph[k_prc]
        }
    }
    print ""
    
    print "## 3. STATISTICI DISK I/O (IMPACTUL ASUPRA SSD)"
    print "| Container | Total Citit (MB) | Total Scris (MB) | Rata Medie Citire | Rata Medie Scriere |"
    print "| :--- | :---: | :---: | :---: | :---: |"
    
    for (c in containers) {
        # Calculam citire/scriere pe durata totala a testului
        read_diff = end_read[c] - start_read[c];
        write_diff = end_write[c] - start_write[c];
        if (read_diff < 0) read_diff = 0;
        if (write_diff < 0) write_diff = 0;
        
        total_duration = ingest_dur + proc_dur;
        
        printf "| %s | %.2f MB | %.2f MB | %.2f MB/s | %.2f MB/s |\n",
            c, read_diff, write_diff, read_diff/total_duration, write_diff/total_duration
    }
    print ""
    print "## 4. CONCLUZII DE EFICIENTIZARE CPU SI RAM"
    print "Analizati tabelul de resurse de mai sus. In mod ideal:"
    print "- MariaDB (log-monitor-db) ar trebui sa aiba un consum echilibrat de RAM si CPU datorita noilor tranzactii bulk."
    print "- Worker-ul CLI (log-monitor-worker) ar trebui sa aiba un footprint CPU mult redus in comparatie cu versiunea anterioara care procesa logs unul cate unul."
}' "$RAW_STATS_FILE" > "$LOG_FILE"

# Afisam raportul in consola
cat "$LOG_FILE"

echo ""
echo "========================================================================"
echo " Raportul complet de performanta a fost salvat in: ${LOG_FILE#../}"
echo "========================================================================"

# Curatam fisierele temporare
rm -f "$RAW_STATS_FILE" "$START_STATS_FILE" "$END_STATS_FILE" "$BASELINE_FILE"