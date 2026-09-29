---
name: bio-sync-db
description: Bio-Sync MariaDB schema cheat-sheet — devices magic names, system_settings keys, sensor_logs, and the three-places threshold rule. Load when writing any query or touching biosync_db.sql.
metadata:
  category: database
---

# Bio-Sync Database (biosync_db)

## Knowledge

Schema source of truth: `biosync_db.sql` (phpMyAdmin dump, MariaDB 10.4).

| Table | Purpose |
|---|---|
| `devices` | Registered devices; `current_state` holds sensor readings AND on/off states (varchar) |
| `sensor_logs` | Time-series: `device_id`, `recorded_value` decimal(10,2), `recorded_at` datetime |
| `system_settings` | Key/value config: `max_temperature` (30.0), `min_humidity` (85.0), `max_humidity` (90.0), `temperature_unit` (C), `email_notifications`, `system_status` |
| `system_controls` | Manual pump/fan states (`pump`, `fan` → `ON`/`OFF`) |
| `notifications` | In-app alerts: title, description, message, type (`info`/`alert`), icon_class, is_read |
| `users` | full_name, username, password_hash (bcrypt), status (`Active`/`Inactive`), role (`user`/`admin`), phone |

## Instructions

1. **Magic device names — never rename, never hardcode ids.** Rows are looked up by name: `Temperature Node 1`, `Humidity Node 1`, `Ventilation Fan`, `Water Pump`, `System Alert Indicator`. Queries use `WHERE device_name = '...' LIMIT 1` (no foreign keys).
2. **Thresholds exist in three places that must stay in sync:** (a) `system_settings` table read by PHP, (b) constants in `sketch_may16a/sketch_may16a.ino` (MAX_TEMP 30, humidity 85–90), (c) constants in `biosync_ml/train_models.py`. If you change one, check the other two and say so.
3. Sensor readings are written twice: appended to `sensor_logs` and updated into `devices.current_state` (which `live_data.php` reads). A new ingest path must do both.
4. To reset data during testing, re-import `biosync_db.sql` via phpMyAdmin — it drops and recreates all tables (wipes users, settings, and logs).
