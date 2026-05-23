# 🪵 LogMonitor

A secure, high-performance centralized logging system designed for PHP applications. 

This repository is optimized for **PHP 8.4** and runs containerized in a secure **Podman** environment utilizing **FrankenPHP** (with JIT compilation enabled) and **MariaDB**.

---

## 🚀 Key Features

* **Centralized API Logging**: Securely collect logs from multiple external applications via server-to-server HTTP POST requests.
* **Modern Administration Dashboard**:
  * Real-time metrics overview (Total Logs, Active Applications, Critical Alerts, Warnings).
  * Auto-refreshing timeline (Live tailing powered by **Alpine.js**).
  * Advanced full-text search across log messages and JSON context metadata.
  * Filters for severe log levels and specific applications.
* **Granular Alerts**: Instant automatic alerts dispatched via **SMTP Email** or **Microsoft Teams** webhook channels for critical errors.
* **CLI Maintenance Utility**: Automatic log archiving to `.txt` files and database cleanup (`bin/purge-logs.php`) for retention management.
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
podman exec -it log-monitor-app vendor/bin/phinx migrate

# Load initial seeds (default admin user, sample app, settings, and mock logs)
podman exec -it log-monitor-app vendor/bin/phinx seed:run
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
  * `X-API-KEY`: `<your_application_api_key>`
  * `Content-Type`: `application/json`
  * `User-Agent`: `YourBackendServer/1.0` (must not resemble a browser agent)

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
* **Success (200 OK)**:
  ```json
  {"status": true, "message": "Log recorded"}
  ```
* **Invalid Input (422 Unprocessable Entity)**:
  ```json
  {"errors": ["The message field is required."]}
  ```
* **Invalid Authorization (403 Forbidden)**:
  ```json
  {"error": "Invalid or inactive API Key"}
  ```

---

## 🧹 Maintenance CLI Utility

Manage disk space and database size by setting up a cron job or running the CLI maintenance command.

The script archives old database entries to `/var/www/html/storage/backups/` in raw `.txt` format and purges them from the database.

```bash
# Executed inside the container (purges logs older than 30 days by default)
podman exec -it log-monitor-app php bin/purge-logs.php 30
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

## 🌍 Directory Structure

```text
log-monitor/
├── bin/                 # CLI Maintenance utilities (purge-logs.php)
├── db/                  # Phinx migrations and data seeds
├── docker/              # Container configuration (Dockerfile, Caddyfile, php.ini)
├── lang/                # Translation dictionary files (en.json, ro.json)
├── src/                 # Application Core
│   ├── Controllers/     # Logic handlers (Auth, App, Log, Settings, etc.)
│   ├── Enums/           # LogLevel backed enum definitions
│   ├── Models/          # Database Active Record Models
│   └── Utilities/       # Helpers, MySQLWrapper, Mailers, Validations
├── storage/             # File storage for text log archives and system logs
└── views/               # PHP UI Templates (layouts, dashboard, auth)
```

---

## 📄 License
Proprietary. All rights reserved.