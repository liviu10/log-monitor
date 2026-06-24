# 🪵 LogMonitor

A secure, high-performance centralized logging system designed for PHP applications. 

This repository is optimized for **PHP 8.4** and runs containerized in a secure **Podman** environment utilizing **FrankenPHP** (with JIT compilation enabled) and **MariaDB**.

---

## 🚀 Key Features

* **Centralized API Logging**: Securely collect logs from multiple external applications via server-to-server HTTP POST requests.
* **Database-Backed Async Queue**: High-throughput ingestion. Payloads are instantly queued as raw strings in the database, avoiding overhead in the HTTP thread path and preventing data loss during traffic spikes.
* **Modern Administration Dashboard**:
  * Real-time metrics overview (Total Logs, Active Applications, Critical Alerts, Warnings).
  * Auto-refreshing timeline (Live tailing powered by **Alpine.js**).
  * Advanced full-text search across log messages and JSON context metadata.
  * Filters for severe log levels and specific applications.
* **Queue Manager Dashboard**:
  * Real-time queue volume metrics and worker status visualization (Active/Inactive).
  * Detailed payload inspection (interactive JSON formatting) and clipboard copying.
  * Individual job deletions and database queue purging capabilities.
* **Granular Alerts**: Instant automatic alerts dispatched via **SMTP Email** or **Microsoft Teams** webhook channels for critical errors.
* **CLI Maintenance Utility**: Automatic log archiving to `.txt` files and database cleanup (`bin/purge-logs.php`) for retention management.
* **CLI Queue Worker**: A high-efficiency daemon (`bin/worker.php`) utilizing SQL transaction locks (`FOR UPDATE SKIP LOCKED`) to consume queued payloads concurrently without race conditions.
* **Robust Security Suite**:
  * Strict Content Security Policy (CSP) with cryptographically secure inline nonces.
  * Clickjacking defense (`X-Frame-Options: DENY`) and MIME Sniffing protection.
  * HTTP Strict Transport Security (HSTS) integration.
  * Secured session lifecycle with anti-fixation and cookie restrictions (`HttpOnly`, `Secure`, `SameSite=Lax`).
  * CSRF Protection on all POST actions.
  * Argon2id/BCRYPT password hashing for administrators.
* **Multi-lingual Interface**: Seamless support for English and Romanian.

---

## 🛠️ Architecture & Tech Stack

* **Backend Engine**: PHP 8.4 (using native features like `json_validate`, `match` expressions, typed class constants, constructor property promotion, and union types).
* **Application Server**: **FrankenPHP** (Alpine-based, built-in Caddy server, high performance with OPcache JIT compilation enabled).
* **Database**: MariaDB (structured logs with `FULLTEXT` indexing on `message` and `context` fields).
* **Frontend UI**: Bootstrap 5.3.3, Alpine.js v3, FontAwesome 6.3.0.
* **Dependencies**: Symfony VarDumper (debugging), Robmorgan Phinx (database migrations & seeding), PHPMailer (notifications), Vlucas Phpdotenv.

---

## 📦 Getting Started (Podman Setup)

On the `master` branch, the development environment runs containerized under **Podman** with rootless socket mapping.

### 1. Prerequisites
Ensure you have `podman` and `podman-compose` installed on your machine:
```bash
# Check installation
podman --version
podman-compose --version
```

### 2. Environment Configuration
Duplicate the example environment file and adjust configuration details:
```bash
cp .env.example .env
```
Default `.env` configuration template:
```ini
# Database Configuration
DB_HOST=db
DB_DATABASE=log_monitor
DB_USERNAME=user
DB_PASSWORD=password
DB_PORT=3306
MYSQL_ROOT_PASSWORD=rootpassword

# Application Settings
APP_ENV=development
APP_NAME=LogMonitor
TZ=Europe/Bucharest

# SMTP Configuration (Optional)
SMTP_HOST=localhost
SMTP_PORT=587
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_FROM=admin@logmonitor.local
```

### 3. Spin Up Containers
Launch the database and application containers in the background:
```bash
podman-compose -f docker/docker-compose.yml up -d
```
> [!NOTE]
> The application will be exposed locally at **`http://localhost:8080`**.

### 4. Database Migrations & Seeding
Prepare the database tables and populate the default seed data by running Phinx migrations inside the app container:
```bash
# Run migrations
podman exec -it log-monitor-app vendor/bin/phinx migrate -e development

# Load initial seeds (default admin user, sample app, settings, and mock logs)
podman exec -it log-monitor-app vendor/bin/phinx seed:run -e development
```

---

## 🔐 Seed Credentials

Once the database has been seeded, you can log in to the admin panel using the following defaults:

* **URL**: `http://localhost:8080/login.php`
* **Username**: `admin`
* **Password**: `admin123`

---

## 📡 API Logging Endpoint

LogMonitor provides a fast, server-to-server endpoint to submit logs. Frontend logging is restricted to protect your keys.

* **Endpoint**: `POST http://localhost:8080/log.php`
* **Headers**:
  * `X-API-KEY`: `<your_global_api_key>` (default seed: `e7c6b541234567890abcdef1234567890`)
  * `X-APP-KEY`: `<your_application_api_key>`
  * `Content-Type`: `application/json`

### Request Payload Example:
```json
{
  "level": "ERROR",
  "message": "Payment gateway timeout occurred.",
  "context": {
    "order_id": 49201,
    "amount": 129.99,
    "currency": "EUR",
    "gateway": "PayPal",
    "latency_ms": 5002
  }
}
```

### Response Formats:
* **Success (202 Accepted)**:
  ```json
  {"status": "queued"}
  ```
* **Invalid Input (400 Bad Request)**:
  - Missing key header:
    ```json
    {"error": "X-APP-KEY header is missing or empty."}
    ```
  - Empty request body:
    ```json
    {"error": "Empty request body."}
    ```
* **Invalid Authorization (403 Forbidden)**:
  ```json
  {"error": "Invalid or inactive client API Key."}
  ```
* **System Failure (500 Internal Server Error)**:
  ```json
  {"error": "Database connection or query failed."}
  ```

---

## 🔧 Container & Application Maintenance

### 1. Container Lifecycle Management (Podman)
* **Start all services**:
  ```bash
  podman-compose -f docker/docker-compose.yml up -d
  ```
* **Stop all services**:
  ```bash
  podman-compose -f docker/docker-compose.yml down
  ```
* **Restart all services**:
  ```bash
  podman-compose -f docker/docker-compose.yml restart
  ```
* **View container real-time log output**:
  ```bash
  podman-compose -f docker/docker-compose.yml logs -f
  ```
* **Check status of running containers**:
  ```bash
  podman ps -a
  ```
* **Access the application container terminal shell**:
  ```bash
  podman exec -it log-monitor-app sh
  ```

### 2. Database Administration (Phinx & MariaDB)
* **Run outstanding database migrations**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx migrate -e development
  ```
* **Rollback last migration step**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx rollback -e development
  ```
* **Rollback all migrations (Reset database schema)**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx rollback -e development -t 0
  ```
* **Run database seeders**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx seed:run -e development
  ```
* **Create a new database migration class**:
  ```bash
  podman exec -it log-monitor-app vendor/bin/phinx create MyNewMigration
  ```
* **Dump/Backup the database**:
  ```bash
  podman exec -it log-monitor-db mariadb-dump -u user -ppassword log_monitor > backup.sql
  ```
* **Restore database from dump file**:
  ```bash
  podman exec -i log-monitor-db mariadb -u user -ppassword log_monitor < backup.sql
  ```

### 3. Log Retention & Application Maintenance
* **Purge logs older than X days** (Automatically archives logs to `/var/www/html/storage/backups/` and deletes them from the active database):
  ```bash
  podman exec -it log-monitor-app php bin/purge-logs.php 30
  ```
* **Install vendor dependencies (production mode)**:
  ```bash
  podman exec -it log-monitor-app composer install --no-dev --optimize-autoloader
  ```

### 4. Asynchronous Queue & Worker Management
* **Start a new queue worker daemon (detached)**:
  ```bash
  podman exec -d log-monitor-app php bin/worker.php
  ```
* **Check active worker processes in the container**:
  ```bash
  podman exec log-monitor-app ps aux | grep worker.php
  ```
* **Run a realistic stress-test load simulation** (Generates 500,000 logs across 4 virtual applications with realistic traffic cycles, spikes, and jitter pacing):
  ```bash
  podman exec log-monitor-app php bin/simulate_logs.php
  ```

---

## 🧪 Testing

The project includes unit and integration (feature) tests using PHPUnit. Tests run against a separate test database (`log_monitor_test`) to ensure development/production data is untouched.

### 1. Install Testing Dependencies
Run Composer inside the application container to install PHPUnit and update autoload mappings:
```bash
podman exec -it log-monitor-app composer install
```

### 2. Create the Test Database
Ensure the testing database exists inside the MariaDB database container:
```bash
podman exec -it log-monitor-db mysql -u root -prootpassword -e "CREATE DATABASE IF NOT EXISTS log_monitor_test;"
```

### 3. Run the Test Suite
Execute PHPUnit inside the container to run all unit and feature tests:
```bash
podman exec -it log-monitor-app vendor/bin/phpunit
```

*Note: Phinx database migrations and setup rollbacks are programmatically handled before tests execute inside the base `TestCase` class.*

---

## 🔄 Transpilation & Multi-version Support (Rector)

The codebase is written in modern **PHP 8.4**. To deploy in environments running older versions of PHP (e.g., PHP 7.4.33 or PHP 8.0) without Docker/Podman access, we use **Rector** to automatically transpile and downgrade the syntax.

### 1. Build and Downgrade Releases Automatically
A custom automated build script handles the entire build pipeline:
1. Cleans up previous builds and copies the necessary project files recursively to a build folder (`dist/php74` or `dist/php80`).
2. Toggles Rector's configuration dynamically via environment variables.
3. Automatically runs Rector to downgrade modern PHP 8.4 features (like Constructor Property Promotion, `match` expressions, readonly properties, union types, etc.) down to the target PHP version.
4. Removes development and build-related scripts from the generated release folder.

Run the build script inside the application container:

* **Generate PHP 7.4 Release** (output in `dist/php74/`):
  ```bash
  podman exec log-monitor-app php bin/build-release.php 7.4
  ```

* **Generate PHP 8.0 Release** (output in `dist/php80/`):
  ```bash
  podman exec log-monitor-app php bin/build-release.php 8.0
  ```

### 2. Manual Commands & Dry-Run
You can also run Rector manually or simulate the changes before writing them to the disk:

* **Simulate changes (Dry-Run)**:
  ```bash
  podman exec log-monitor-app vendor/bin/rector process dist/php74 --dry-run
  ```

* **Apply Rector refactoring manually**:
  ```bash
  podman exec log-monitor-app vendor/bin/rector process dist/php74
  ```

---

## 🌍 Directory Structure

```text
log-monitor/
├── bin/                 # CLI Utilities (purge-logs.php, worker.php, simulate_logs.php)
├── db/                  # Phinx migrations and data seeds
├── docker/              # Container configuration (Dockerfile, Caddyfile, php.ini)
├── lang/                # Translation dictionary files (en.json, ro.json)
├── queue.php            # Queue Manager router/entrypoint
├── src/                 # Application Core
│   ├── Controllers/     # Logic handlers (Auth, App, Log, Settings, QueueController)
│   ├── Enums/           # LogLevel backed enum definitions
│   ├── Models/          # Database Active Record Models
│   └── Utilities/       # Helpers, MySQLWrapper, Mailers, Validations
├── storage/             # File storage for text log archives and system logs
└── views/               # PHP UI Templates (layouts, dashboard, auth, queue)
```

---

## 📄 License
Proprietary. All rights reserved.