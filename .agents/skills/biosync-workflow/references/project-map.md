# Bio-Sync project map

Snapshot checked 2026-10-29. Recheck before relying on branch, line-ending, or interface details.

## Layout and ownership

- Root PHP pages: `dashboard.php` (controls, stage actions, SMS, live UI), `login.php` / `logout.php` (auth), `live_data.php` (authenticated dashboard JSON), `api_update.php` (device ingest, thresholds, notifications), `historicaldatapage.php` (history, SMS, settings), `notificationspage.php` (notification actions/settings), `profile.php` (profile/password/settings), `usermanagement.php` (admin user CRUD/settings), `config.php` (PDO connection; credentials stay private).
- `biosync_db.sql`: MariaDB schema and seed data. `biosync_ml/train_models.py` trains; `prediction_api.py` serves; `ml_widget.php` calls the API. Firmware sketches are grouped by board in `firmware/uno/` and `firmware/esp12e/`; local `firmware/esp12e/secrets.h` must remain ignored. The wiring diagram is `firmware/wiring_uno_esp12e_bridge.svg`. `tests/contract_tests.py` holds cross-file checks; `tests/run_tests.sh` runs static checks and optional live smoke checks.
- `AGENTS.md` is the contributor guide; `README.md` has setup and runtime documentation.

## Interfaces

- Seeded `devices.device_name` values: `Temperature Node 1`, `Humidity Node 1`, `Ventilation Fan`, `Water Pump`.
- Device POST: firmware sends form-encoded temperature/humidity and fresh `fan_actual`/`pump_actual` output-pin feedback to `/mushroomsystem/api_update.php` every 10 seconds, authenticated with `X-Device-Token`. Sensor-fault state-only POSTs report safe output states without sensor data. Successful replies use `CMD:FAN=n,PUMP=n,FAN_MODE=AUTO|MANUAL,PUMP_MODE=AUTO|MANUAL` plus active `MIN_TEMP`, `MAX_TEMP`, `MIN_HUM`, `MAX_HUM` thresholds. Actuator UI state comes from Uno feedback; it is marked stale after 15 seconds without a report.
- PHP to Flask: `biosync_ml/ml_widget.php` calls `http://127.0.0.1:5000/predict` with `temp`, `hum`, `algo`, and `stage`. Flask also exposes `/health` and `/metrics`.
- `live_data.php` and dashboard contract keys include `temperature`, `humidity`, `fanState`, `pumpState`, `fanMode`, `pumpMode`, `fanRequested`, `pumpRequested`, `actuatorFeedbackFresh`, `actuatorFeedbackAge`, `tempUnit`, `tempAbove`, `tempBelow`, `humAbove`, `humBelow`, `stage`, `daysPassed`, `expectedDays`, `pendingReminder`, `activeAlgo`, `chartLabels`, `tempHistory`, `humHistory`, `notifications`, `notifiedString`, `dispatchStatus`.
- Session keys in use: `user_id`, `user_name`, `user_role`, `csrf_token`. `user_role` is compared case-insensitively for authorization.
- `ajax_action` names: `toggle_device`, `set_device_auto`, `save_settings`, `extend_stage`, `mark_read`, `mark_all_read`, `delete_notif`, `delete_all_read`, `update_profile`, `change_password`, `save_user`, `delete_user`. Login uses `action=login`.

## Settings and duplicated behavior

- `system_settings` stage keys: `current_stage`, `stage_started_at`, `stage_expected_days`, `active_ml_algorithm`; for each of `colonization`, `pinning`, `fruiting`: `<stage>_min_temp`, `<stage>_max_temp`, `<stage>_min_hum`, `<stage>_max_hum`, `<stage>_default_days`. Other seeded keys include `email_notifications`, `max_humidity`, `max_temperature`, `min_humidity`, `system_status`, `temperature_unit`; `last_sms_sent` is also read/written at runtime.
- Settings forms/update logic are repeated across `dashboard.php`, `historicaldatapage.php`, `notificationspage.php`, `profile.php`, and `usermanagement.php`. Stage thresholds are repeated in the database seed, PHP handlers/readers, firmware, trainer, and prediction API. SMS sending/cooldown logic is duplicated between dashboard and historical page. Update every relevant copy or deliberately centralize only when that refactor is authorized.
- Intended lifecycle: stages run colonization → pinning → fruiting; a reminder prompts human inspection; an admin confirms advancement or extends days. Fruiting is the final stage and reports cycle completion rather than silently wrapping. Thresholds should follow the selected stage.

## Checkout and file endings

- Snapshot branch: `development-branch`, tracking `origin/development-branch`; inspect read-only status before work and preserve existing changes. Never switch branches or alter Git state.
- `core.autocrlf=true`. Several legacy PHP pages and test files are CRLF; some changed PHP/Python/Arduino/Markdown files have mixed LF and CRLF. Inspect bytes before editing and preserve that file’s current endings; Git may warn that LF will become CRLF.
