# Bio-Sync Project Notes

## Components

- The root PHP pages provide login, dashboard controls, live data, user administration, notifications, profile, and history views. `config.php` provides the shared PDO connection.
- `api_update.php` accepts authenticated form-encoded sensor readings from the ESP8266, writes sensor history and device state, and returns actuator commands and stage thresholds.
- `sketch_may16a/sketch_may16a.ino` reads the DHT22, sends the authenticated POST, and keeps local threshold overrides aligned with the active server stage. Local WiFi and API token values belong in the ignored `sketch_may16a/secrets.h` file, copied from its example.
- `biosync_ml/train_models.py` trains the classifiers from paired sensor readings. `prediction_api.py` serves predictions and metrics on loopback port 5000; `ml_widget.php` calls that service server-side.

## Database and Thresholds

Import `biosync_db.sql` into MariaDB. `devices`, `sensor_logs`, `notifications`, `system_settings`, and `users` are the main tables. Device names are shared identifiers used by PHP and firmware. Thresholds are stage-specific keys in `system_settings`; update PHP and firmware behavior together if those rules change. Notification read state is global because the table has no per-user owner column.

## Local Setup and Checks

Run Apache and MariaDB through XAMPP, import the schema, and open `login.php`. Database settings are in `config.php`. Configure Apache's `BIOSYNC_DEVICE_TOKEN` environment variable and the same token in local `secrets.h` before expecting device ingest to work. Keep WiFi and SMS credentials out of tracked files; rotate any credential that was committed previously.

Run `bash tests/run_tests.sh` for PHP syntax checks, in-memory Python syntax checks, and static contract checks. `bash tests/run_tests.sh --live` makes read-only checks against running services, including verifying that unauthenticated sensor writes are rejected. For model training, run `python train_models.py` from `biosync_ml/` after collecting at least 50 paired readings, then start `python prediction_api.py` from that directory.

The static contract suite is `tests/contract_tests.py`. The Arduino sketch requires the DHT library and a local `secrets.h`; verify firmware changes with the Arduino IDE and the target board before deployment.
