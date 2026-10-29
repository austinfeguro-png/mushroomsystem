# 🌱 Bio-Sync -MAIN — Smart Greenhouse Monitoring & Automation

Bio-Sync is an IoT system that monitors temperature and humidity in a growing environment, automatically controls a ventilation fan and water pump, sends SMS alerts to staff, and visualizes everything on a live web dashboard — with a machine-learning service that classifies growing conditions.

## ✨ Features

- **Live monitoring** — local temperature/humidity checks and control every 3 seconds; sensor history is saved every 10 minutes, with the dashboard refreshing every 5 seconds
- **Automatic control** — fan and pump switch on/off based on configurable thresholds (with hardware-side overrides)
- **SMS alerts** — notifications via the Semaphore API with a 15-minute cooldown
- **User management** — admin/user roles, bcrypt-hashed passwords, account activation/deactivation
- **Notifications center** — in-app alerts with read/unread state
- **Historical data** — sensor logs stored in MariaDB with history charts
- **ML predictions** — trains Decision Tree / Random Forest / KNN models on collected data and serves predictions via a Flask API. Training labels are generated from stage thresholds encoded in the trainer, so reported scores measure rule reproduction rather than independent biological validation.

## 🏗️ Architecture

```
┌─────────────────┐  HTTPS (api_update.php)   ┌──────────────────┐
│  Arduino + ESP8266│ ───────────────────────▶ │  PHP web app      │
│  DHT22 sensor     │ ◀─────────────────────── │  (XAMPP/Apache)   │
│  Fan / Pump / LED │   CMD:FAN=n,PUMP=n       │  MariaDB          │
└─────────────────┘                           └────────┬─────────┘
                                                       │ HTTP :5000
                                              ┌────────▼─────────┐
                                              │  Flask ML API     │
                                              │  /predict /metrics│
                                              └──────────────────┘
```

Three components work together:

1. **Uno firmware** (`firmware/uno/`) — reads the DHT22, controls relays and LEDs, and sends readings over serial to the ESP-12E. **ESP bridge** (`firmware/esp12e/`) — forwards readings to the PHP API and passes validated commands back to the Uno.
2. **PHP web app** (repo root) — dashboard, authentication, user management, notifications, SMS dispatch, and the hardware ingest endpoint.
3. **Python ML service** (`biosync_ml/`) — trains classifiers from logged sensor data and exposes a prediction API the dashboard queries.

## 📁 Repository Layout

```
├── api_update.php            # Hardware ingest endpoint (Arduino → server)
├── live_data.php             # JSON feed polled by the dashboard every 5 s
├── dashboard.php             # Main dashboard + AJAX handlers + SMS
├── login.php                 # Authentication
├── profile.php               # User profile management
├── usermanagement.php        # Admin-only user CRUD
├── notificationspage.php     # Notification center
├── historicaldatapage.php    # Historical sensor data
├── biosync_db.sql            # Full database schema + seed data
├── docs/
│   ├── knowledge.md          # Project behavior and component notes
│   ├── SYSTEM_DIAGRAMS.md    # Architecture and data-flow diagrams
│   └── reference/
│       ├── Bio-Sync-Chapters-1-and-2.pdf # Capstone Chapters 1 and 2 (April 2026)
│       └── Bio-Sync Project Overview.docx # Project manuscript / overview
├── biosync_ml/
│   ├── train_models.py       # One-shot model trainer
│   ├── prediction_api.py     # Flask prediction API (port 5000)
│   ├── biosync_models.pkl    # Trained model set (generated; preferred)
│   ├── biosync_model.pkl     # Legacy single-model fallback
│   └── ml_results.json       # Model comparison metrics (generated)
└── firmware/
    ├── uno/                 # Arduino Uno sketch + shared protocol parser
    ├── esp12e/              # ESP-12E sketch + shared protocol and secrets example
    ├── wiring_uno_esp12e_bridge.svg
    └── README.md            # Setup, power, upload, and bench checks
```

## 🛠️ Setup

### Prerequisites

- [XAMPP](https://www.apachefriends.org/) (Apache + MariaDB, PHP 8.x) — or any equivalent LAMP stack
- Python 3.x (for the ML service)
- Arduino IDE (for the firmware)
- Arduino libraries: `DHT sensor library`, `SoftwareSerial` (bundled)

### 1. Web app + database

1. Clone/copy this folder into your XAMPP `htdocs` directory.
2. Start Apache and MySQL from the XAMPP control panel.
3. Import `biosync_db.sql` via phpMyAdmin (`http://localhost/phpmyadmin`).
4. Open `http://localhost/<folder-name>/login.php` and log in.

### Shared hosting setup

For shared PHP/MySQL hosting, create a database in the provider panel and
import `biosync_db.sql` using its database tool. Copy `config.local.php.example`
to `config.local.php`, then enter the database host, database name, username,
password, and a strong device token from the provider panel. Upload the PHP
application files and `config.local.php` into the site's document root; do not
upload development-only files such as tests, local firmware secrets, or model
training data. `config.local.php` is ignored by Git and blocked from direct web
requests by `.htaccess`. Keep an independent copy of the database export.

The free InfinityFree plan supports PHP and MySQL but not the Flask prediction
service. The dashboard's ML prediction requires a separately hosted Flask API
or a hosting plan that confirms support for the project's Python dependencies.

> XAMPP uses local `root` defaults unless `config.local.php` overrides them. Never reuse those defaults on a public host.

### 2. Machine learning service

```bash
cd biosync_ml
pip install -r requirements.txt

python train_models.py     # requires ≥50 paired sensor readings in the DB
python prediction_api.py   # keep running while the dashboard is open (port 5000)
```

Before training, select `BIOSYNC_DB_PROFILE=local` or `online` and set that profile's
`BIOSYNC_<PROFILE>_DB_HOST`, `_DB_NAME`, `_DB_USER`, and `_DB_PASSWORD` environment
variables. Use a database account with read-only access to the trainer's source tables;
do not put database credentials in the repository.

### 3. Arduino Uno + NodeMCU hardware

Upload `firmware/uno/uno.ino` to the **Uno** and
`firmware/esp12e/esp12e.ino` to the **ESP-12E** (use the
NodeMCU 1.0 ESP-12E board profile). The Uno reads
DHT22 on D2, drives active-LOW fan/pump relays on D4/D5, and LEDs on D8/D9.
Uno D10 RX connects to NodeMCU D7 TX; Uno D11 TX connects through a 1k/2k
voltage divider to NodeMCU D6 RX. Share ground. The serial link is 9600 baud;
both USB Serial Monitors use 115200 baud.

See [firmware setup, power, upload and bench checks](firmware/README.md).
See [architecture diagrams](docs/SYSTEM_DIAGRAMS.md), [project notes](docs/knowledge.md),
the [project overview manuscript](docs/reference/Bio-Sync%20Project%20Overview.docx),
and [Bio-Sync Chapters 1 and 2](docs/reference/Bio-Sync-Chapters-1-and-2.pdf).
Disconnect the Uno 5V-to-NodeMCU VIN jumper when powering both boards by USB.
Keep Wi-Fi credentials, the matching server `BIOSYNC_DEVICE_TOKEN`, the HTTPS
API URL, and its trusted CA certificate in the ignored
`firmware/esp12e/secrets.h`. See [the wiring diagram](firmware/wiring_uno_esp12e_bridge.svg).
Both relays remain off until a valid server command arrives and turn off on a
sensor fault or when the last valid command is 30 seconds old.
## ⚙️ Configuration

Thresholds live in the `system_settings` database table and can be tuned from the dashboard:

| Stage       | Temperature | Relative humidity |
| ----------- | ----------- | ----------------- |
| Colonization | 24–28 °C   | 70–85%            |
| Pinning      | 18–24 °C   | 85–95%            |
| Fruiting     | 24–28 °C   | 80–95%            |

The dashboard stores the selected stage and its thresholds in `system_settings`; the hardware API returns the active thresholds to the firmware so its local safety overrides stay in sync.

- **SMS**: paste your Semaphore API key into `sendSMS()` in `dashboard.php` / `historicaldatapage.php`. All active users with a phone number on file receive alerts.
- **Arduino overrides**: the firmware enforces the same thresholds locally, so automatic overrides use the most recent valid thresholds; both relays turn off after 30 seconds without a valid server command.

## 🔌 API Reference

**PHP endpoints**

| Endpoint                    | Method | Purpose                                   |
| --------------------------- | ------ | ----------------------------------------- |
| `api_update.php`            | HTTPS POST | Form fields `temp`, `hum`; requires `X-Device-Token`; returns actuator state and active thresholds |
| `live_data.php`             | GET    | Live JSON feed (auth required)            |
| `*.php` AJAX actions        | POST   | `ajax_action=` handlers per page          |

**Flask ML API**

| Endpoint                        | Purpose                |
| ------------------------------- | ---------------------- |
| `GET /predict?temp=27.6&hum=85` | Condition + confidence |
| `GET /metrics`                  | Model comparison table |
| `GET /health`                   | Service status         |

## 📊 Database Schema

| Table             | Purpose                                    |
| ----------------- | ------------------------------------------ |
| `devices`         | Registered devices and their current state |
| `sensor_logs`     | Time-series of sensor readings             |
| `system_settings` | Thresholds and system configuration        |
| `system_controls` | Manual fan/pump control states             |
| `notifications`   | In-app alerts                              |
| `users`           | Accounts, roles, contact numbers           |

## ⚠️ Security Notes

This project was built as an academic/prototype system. Before any real deployment:

- Keep deployment database credentials in the ignored `config.local.php`; the tracked `config.php` contains only local defaults.
- Keep Wi-Fi credentials in the ignored `firmware/esp12e/secrets.h` file. Configure a real Semaphore API key outside tracked source files; the PHP pages currently contain a placeholder.
- Use HTTPS in deployment. Login and the page AJAX handlers currently validate session CSRF tokens, but HTTPS remains necessary to protect sessions and data in transit.
- `api_update.php` validates the device token from the `BIOSYNC_DEVICE_TOKEN` environment variable or the ignored `config.local.php`; configure the same strong token in the firmware's ignored `secrets.h`.

## 📄 License

All rights reserved © Bio-Sync project team.
