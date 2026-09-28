# Bio-Sync — Smart Greenhouse Monitoring & Automation

IoT greenhouse system with three parts that talk to each other:

1. **Arduino firmware** (`sketch_may16a/sketch_may16a.ino`) — DHT22 sensor, ESP8266 WiFi (AT commands over SoftwareSerial). Reads temp/humidity every 3s, POSTs to the PHP API, and drives fan (pin 5), pump (pin 6), alert LED (pin 7). Applies hardware-side threshold overrides.
2. **PHP web app** (flat `.php` files at repo root) — XAMPP-style app: login, dashboard, live polling, user management, profile, notifications, historical data. All AJAX is inline in the page files (POST `ajax_action=...` → JSON response).
3. **Python ML service** (`biosync_ml/`) — Flask API on port 5000 classifying conditions (Normal/Warning/Critical) from temp+humidity. Dashboard calls it via `http://localhost:5000/predict`.

## Key files

| File | Purpose |
|---|---|
| `api_update.php` | Hardware ingest endpoint: Arduino POSTs `?temp=&hum=` here; logs to `sensor_logs`, updates `devices.current_state`, computes fan/pump states, returns `CMD:FAN=n,PUMP=n` |
| `live_data.php` | JSON polled by dashboard every 5s: live values, device states, 7-point chart history, SMS dispatch status, recent notifications |
| `dashboard.php` (~1.6k lines) | Main UI + AJAX handlers + `sendSMS()` (Semaphore, PH) |
| `login.php` / `profile.php` / `usermanagement.php` | Auth (username + `password_verify`), profile CRUD, admin-only user CRUD |
| `historicaldatapage.php` / `notificationspage.php` | History view, notification center (own live-poll GET handler) |
| `biosync_db.sql` | phpMyAdmin dump of full schema + seed data |
| `biosync_ml/train_models.py` | Run once (needs ≥50 paired readings in DB) → writes `biosync_model.pkl` + `ml_results.json` |
| `biosync_ml/prediction_api.py` | Flask: `GET /predict?temp=&hum=`, `/metrics`, `/health` on :5000 |
| `test.php` | Quick harness that dumps the raw `/predict` response for temp=31, hum=87 |

## Database: `biosync_db` (MariaDB, imported from `biosync_db.sql`)

Tables: `devices`, `sensor_logs`, `notifications`, `system_controls`, `system_settings`, `users`.

- **`devices`** rows are magic strings relied on everywhere: `Temperature Node 1`, `Humidity Node 1`, `Ventilation Fan`, `Water Pump`, `System Alert Indicator`. Sensor readings are stored in `current_state` (a varchar) plus appended to `sensor_logs`.
- **`system_settings`** keys: `max_temperature` (30), `min_humidity` (85), `max_humidity` (90), `temperature_unit` (C), `email_notifications`, `system_status`. PHP reads these; defaults hardcoded as fallback.
- **`users`**: `password_hash` via bcrypt (`password_hash`/`password_verify`), `role` (`user`/`admin`), `status` (`Active`/`Inactive`), `phone` for SMS alerts.

## Running (XAMPP stack)

```bash
# 1. Import biosync_db.sql via phpMyAdmin (MariaDB 10.4 / PHP 8.2 era)
# 2. Serve the repo root from htdocs, then open:
http://localhost/<folder>/login.php

# ML service:
pip install -r biosync_ml/requirements.txt   # scikit-learn, pandas, mysql-connector-python, flask
python train_models.py                       # once, after ≥50 readings exist
python prediction_api.py                     # keep running while dashboard is open (:5000)
```

No composer/npm; no test suite, no linter config. Verify PHP changes by loading pages in the browser; verify ML changes via `test.php` or `curl http://localhost:5000/health`.

## Gotchas & conventions

- **DB credentials are duplicated in every PHP file** (localhost / root / empty password, XAMPP defaults). Changing them means editing each file — no shared config include exists.
- **Same for Arduino**: WiFi SSID/password and server host IP (`192.168.43.178`) are hardcoded in the sketch.
- **Threshold logic exists in three places** that must stay in sync: Arduino sketch constants, `system_settings` table (used by PHP), and `train_models.py` constants (MAX_TEMP=30, humidity 85–90).
- Dashboard/logout redirect goes to `index.php` but the login page is `login.php` — `index.php` is not in the repo (rename or create it).
- `login.php` header comment warns: nothing (not even whitespace) may precede the `<?php` tag (header() safety).
- Auth pattern: `session_start()` + `strtolower($_SESSION['user_role']) === 'admin'` for admin gating (`usermanagement.php`).
- SMS uses Semaphore (PH) with a placeholder key `YOUR_SEMAPHORE_API_KEY_HERE` in `dashboard.php` and `historicaldatapage.php`; session-based 15-min cooldown on alert SMS.
- ML pairing query joins temp/humidity readings on minute-precision timestamps — both readings must land in the same minute to be paired for training.
- Sketch comment says "DHT11" but `DHTTYPE` is `DHT22` — trust the define, not the comment.
- Files are CRLF; responses are plain PHP+HTML+inline JS/Chart.js — no build step, no framework.
