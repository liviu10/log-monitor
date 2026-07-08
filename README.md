# 🪵 LogMonitor

A secure, high-performance centralized logging system designed to collect, queue, and process logs asynchronously for PHP applications.

Optimized for **PHP 8.4** and containerized using **Podman/Docker**, this repository utilizes **FrankenPHP** (with JIT compilation and worker mode enabled) and **MariaDB** to achieve extremely low resource footprints and sub-millisecond request processing times.

---

## ⚡ Asynchronous Architecture

LogMonitor uses a decoupled, queue-first architecture to handle high-throughput traffic spikes without slowing down client applications:

```text
  [ Client App ]
        │ (HTTP POST with X-API-KEY)
        ▼
┌──────────────────────────────────────┐
│  LogMonitor API (api/log.php)        │  ◄── FrankenPHP Workers (Fast Ingestion)
└──────────────────┬───────────────────┘
                   │ (Write raw JSON string)
                   ▼
┌──────────────────────────────────────┐
│  Queue Table (log_queue)             │  ◄── Async buffer (Prevents HTTP block)
└──────────────────┬───────────────────┘
                   │ (Pushed by worker daemon)
                   ▼
┌──────────────────────────────────────┐
│  Worker Daemon (bin/worker.php)      │  ◄── FOR UPDATE SKIP LOCKED (Bulk commit)
└──────────────────┬───────────────────┘
                   ▼
┌──────────────────────────────────────┐
│  Logs Table (logs)                   │  ◄── Indexing & Storage
└──────────────────────────────────────┘
```

1. **Ingestion Phase**: Clients send log payloads to `api/log.php`. The server validates the `X-API-KEY`, bypasses JSON decoding overhead, and instantly writes the raw payload to the `log_queue` table (returning an immediate `202 Accepted` response).
2. **Processing Phase**: A background daemon (`bin/worker.php`) runs concurrently, pulling jobs in bulk using optimized locks (`FOR UPDATE SKIP LOCKED`) and moving them into the indexed `logs` table while checking for custom notification rules.

---

## 🚀 Key Features

* **Sub-Millisecond Ingestion**: Blazing fast HTTP ingestion backed by persistent FrankenPHP worker processes.
* **Database-Backed Async Queue**: Heavy payloads are deferred to a background queue, preventing client timeouts and protecting the system during massive traffic spikes.
* **Real-time Administration Panel**:
  * Metrics dashboard overview (Total logs, active applications, alerts, and system warnings).
  * Auto-refreshing logs timeline (Live tailing powered by **Alpine.js**).
  * Full-text search across log messages and contextual metadata.
* **Queue & Job Inspector**:
  * Real-time queue volume gauges and worker status visualization.
  * Interactive JSON payload formatting and clipboard copy.
  * DB queue purging and individual job pruning.
* **Smart Alerts**: Automatic notification dispatching via **SMTP Email** or **Microsoft Teams** webhook channels for critical events.
* **transpilation Pipeline**: Downgrade modern PHP 8.4 syntax down to PHP 8.0 or PHP 7.4.33 automatically using **Rector** for older hosting environments.

---

## 📊 Performance Benchmarks & Stress Tests

LogMonitor includes a comprehensive benchmarking suite to measure maximum throughput, CPU overhead, RAM consumption, and SSD I/O impact under load.

### ⚡ Running the Benchmark

You can trigger a full 50,000 log stress-test using the host monitoring script:

```bash
# Execute from the host terminal (requires development environment)
./dashboard.sh
```

### 📋 Benchmark Report Details

The script automatically generates a detailed Markdown report saved to:
👉 [storage/reports/test_performanta_50000_loguri.txt](file:///home/liviuvoica/Projects/log-monitor/storage/reports/test_performanta_50000_loguri.txt)

This report details:
1. **Ingestion Phase Speed**: Average requests/second and duration. (Target: **~3,000+ req/s**).
2. **Processing Phase Speed**: Queue worker throughput. (Target: **~11,000+ logs/s**).
3. **Net Resource Consumption**: Idle baselines are automatically subtracted to report **active net CPU and RAM** per container.
4. **SSD I/O Impact**: Reads and writes in MB and MB/s per container.

> [!TIP]
> **Cold Start vs. Warm Start Behavior**
> * **Cold Start (1st run)**: CPU usage is slightly higher (~170%) because PHP's OpCache is empty. The server must read files from disk and compile them to bytecode, and the database buffer pool is not yet warmed up.
> * **Warm Start (Subsequent runs)**: CPU usage drops to **under 40%** (less than 2.5% of a 16-core system) because compiled opcodes are served directly from RAM and database buffers are fully loaded.

---

## 📡 Client API Integration

To connect your external application and send logs:

1. **Register the Client**: Go to the admin panel, create a new application, and copy its unique `api_key`.
2. **Submit Logs**: Send an HTTP POST request to the API endpoint:
   * **Endpoint**: `POST http://log-monitor.local:8080/api/log.php` (or `https://log-monitor.local:8443/api/log.php`)
   * **Headers**:
     * `X-API-KEY`: `<your_application_api_key>`
     * `Content-Type`: `application/json`
   * **Payload Structure**:
     ```json
     {
       "level": "ERROR",
       "message": "Payment gateway timeout occurred.",
       "context": {
         "order_id": 49201,
         "amount": 129.99,
         "currency": "EUR"
       }
     }
     ```

### Response Codes:
* `202 Accepted`: Payload successfully queued (`{"status": "queued"}`).
* `400 Bad Request`: Missing header or empty body (`{"error": "X-API-KEY header is missing or empty."}`).
* `403 Forbidden`: Invalid or inactive API Key (`{"error": "Invalid or inactive client API Key."}`).
* `500 Internal Server Error`: Database or system failure.

---

## 🛠️ Installation & Setup (Podman/Docker)

### 1. Environment Configuration
Duplicate the example environment file and adjust parameters:
```bash
cp .env.example .env
```
Ensure `APP_ENV="development"` is set in your `.env` to enable developer features like the simulator and benchmarking.

### 2. Boot Services
Spin up the stack in the background:
```bash
# Navigate to the docker dir and launch compose
cd docker
podman-compose up -d
```
The application will be accessible locally at **`http://localhost:8080`** (or securely at **`https://log-monitor.local:8443`** if `log-monitor.local` is mapped to `127.0.0.1` in your `/etc/hosts` file).

### 3. Database Migrations & Seeds
Initialize database schemas and seed default credentials:
```bash
# Run migrations using the config file path
podman exec -it log-monitor-app vendor/bin/phinx migrate -c db/phinx.php -e development

# Run seeds (creates default admin user, settings, and mock logs)
podman exec -it log-monitor-app vendor/bin/phinx seed:run -c db/phinx.php -e development
```

### 4. Admin Credentials (Seeded Defaults)
* **URL**: `http://localhost:8080/login.php` (or `https://log-monitor.local:8443/login.php`)
* **Username**: `admin`
* **Password**: `admin123`

---

## 🔧 Maintenance & Admin Commands

### 1. Database Operations
* **Rollback last migration step**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx rollback -c db/phinx.php -e development
  ```
* **Reset Database (Rollback all)**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx rollback -c db/phinx.php -e development -t 0
  ```
* **Create a new migration class**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx create -c db/phinx.php MyNewMigrationName
  ```

### 2. Log Archiving & Retention
* **Purge logs older than X days**: Archives logs to `/var/www/html/storage/backups/` and deletes old records from the database:
  ```bash
  podman exec -it log-monitor-app php bin/purge-logs.php 30
  ```

### 3. Asynchronous Worker Control
* **Start the queue worker daemon (detached)**:
  ```bash
  podman exec -d log-monitor-app php bin/worker.php
  ```
* **Check running worker processes inside the app container**:
  ```bash
  podman exec log-monitor-app ps aux | grep worker.php
  ```

---

## 🧪 Testing Suite

Tests run against a separate test database (`log_monitor_test`) to ensure development data remains untouched.

```bash
# 1. Create test database
podman exec -it log-monitor-db mysql -u root -prootpassword -e "CREATE DATABASE IF NOT EXISTS log_monitor_test;"

# 2. Run PHPUnit test suite (migrations are handled automatically in setUp)
podman exec -it log-monitor-app vendor/bin/phpunit
```

---

## 🔄 Transpilation Pipeline (Rector Downgrade)

To generate standalone release bundles for environments running older PHP versions:

* **Generate PHP 8.0 bundle** (outputs to `dist/php80/`):
  ```bash
  podman exec log-monitor-app php bin/build-release.php 8.0
  ```
* **Generate PHP 7.4 bundle** (outputs to `dist/php74/`):
  ```bash
  podman exec log-monitor-app php bin/build-release.php 7.4
  ```

---

## 🌍 Directory Structure

```text
log-monitor/
├── api/                 # Fast API Ingestion Endpoints (log.php)
├── bin/                 # CLI Utilities (purge-logs.php, worker.php, simulate-logs.php, rector.php)
├── db/                  # Phinx migrations, data seeds, and configuration (phinx.php)
├── docker/              # Container configuration (Dockerfile, Caddyfile, php.ini, entrypoints, dashboard.sh)
├── lang/                # Translation dictionary JSON files (en.json, ro.json)
├── queue.php            # Queue Manager router/entrypoint
├── src/                 # Application Core (Controllers, Models, Utilities)
├── storage/             # Log archives, emergency logs, and performance reports
└── views/               # PHP HTML templates for the administration dashboard
```

---

## 📄 License
Proprietary. All rights reserved.