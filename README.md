# 🌱 Bio-Sync - demo — Smart Greenhouse Monitoring & Automation

Bio-Sync is an IoT system that monitors temperature and humidity in a growing environment, automatically controls a ventilation fan and water pump, sends SMS alerts to staff, and visualizes everything on a live web dashboard — with a machine-learning service that classifies growing conditions.

## ✨ Features

- **Live monitoring** — temperature and humidity readings every 3 seconds, auto-refreshing dashboard with Chart.js history graphs
- **Automatic control** — fan and pump switch on/off based on configurable thresholds (with hardware-side overrides)
- **SMS alerts** — notifications via the Semaphore API with a 15-minute cooldown
- **User management** — admin/user roles, bcrypt-hashed passwords, account activation/deactivation
- **Notifications center** — in-app alerts with read/unread state
- **Historical data** — sensor logs stored in MariaDB with history charts
- **ML predictions** — trains Decision Tree / Random Forest / KNN models on collected data and serves predictions via a Flask API

## 🏗️ Architecture

```
┌─────────────────┐   HTTP (api_update.php)   ┌──────────────────┐
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

1. **Arduino firmware** (`sketch_may16a/`) — reads the DHT22 sensor every 3 s, posts readings to the PHP API, receives control commands, and applies safety overrides (e.g., forces the fan on above the max temperature regardless of dashboard state).
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
├── biosync_ml/
│   ├── train_models.py       # One-shot model trainer
│   ├── prediction_api.py     # Flask prediction API (port 5000)
│   ├── biosync_model.pkl     # Trained model (generated)
│   └── ml_results.json       # Model comparison metrics (generated)
└── sketch_may16a/
    └── sketch_may16a.ino     # Arduino firmware
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

> Default DB credentials in the code are XAMPP defaults (`root` with empty password). Edit them in each PHP file if your setup differs.

### 2. Machine learning service

```bash
cd biosync_ml
pip install -r requirements.txt

python train_models.py     # requires ≥50 paired sensor readings in the DB
python prediction_api.py   # keep running while the dashboard is open (port 5000)
```

### 3. Arduino hardware

| Component                    | Pin                      |
| ---------------------------- | ------------------------ |
| DHT22 data                   | D2                       |
| Ventilation fan (relay)      | D5                       |
| Water pump (relay)           | D6                       |
| Alert indicator (LED/buzzer) | D7                       |
| ESP8266 TX/RX                | D10/D11 (SoftwareSerial) |

Open `sketch_may16a/sketch_may16a.ino` and set your WiFi credentials and the server IP (the machine running XAMPP) before uploading.

## ⚙️ Configuration

Thresholds live in the `system_settings` database table and can be tuned from the dashboard:

| Key                | Default | Meaning                     |
| ------------------ | ------- | --------------------------- |
| `max_temperature`  | 30.0 °C | Above this → fan on, alert  |
| `min_humidity`     | 85.0 %  | Below this → pump on, alert |
| `max_humidity`     | 90.0 %  | Above this → fan on, alert  |
| `temperature_unit` | C       | Display unit                |

- **SMS**: paste your Semaphore API key into `sendSMS()` in `dashboard.php` / `historicaldatapage.php`. All active users with a phone number on file receive alerts.
- **Arduino overrides**: the firmware enforces the same thresholds locally, so safety automation continues even if the server is unreachable.

## 🔌 API Reference

**PHP endpoints**

| Endpoint                    | Method | Purpose                                   |
| --------------------------- | ------ | ----------------------------------------- |
| `api_update.php?temp=&hum=` | GET    | Sensor ingest; returns `CMD:FAN=n,PUMP=n` |
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

- Replace the hardcoded database credentials (duplicated across PHP files) with a shared config and a dedicated DB user.
- Move the WiFi credentials and SMS API key out of source code (environment variables or a config file).
- Serve over HTTPS and add CSRF protection to the AJAX endpoints.
- Restrict `api_update.php` (e.g., shared secret or IP allow-list) so only the hardware can post readings.

## 📄 License

All rights reserved © Bio-Sync project team.
