# Repository Guidelines

## Project Structure & Module Organization

The PHP application lives in the repository root because XAMPP and the ESP device endpoint expect those paths. Pages such as `dashboard.php`, `login.php`, and `historicaldatapage.php` handle the web interface; `api_update.php` accepts hardware readings and `live_data.php` supplies dashboard data. `config.php` centralizes the PDO database connection. `biosync_db.sql` defines the MariaDB schema and seed data. Project notes, diagrams, and the overview manuscript are in `docs/`; the Python prediction service and trainer are in `biosync_ml/`; Arduino sketches are organized by board in `firmware/uno/` and `firmware/esp12e/`. Static cross-file checks are in `tests/`.

## Build, Test, and Development Commands

There is no separate build step for the PHP application. Run it locally through XAMPP/Apache and MariaDB after importing `biosync_db.sql`.

- `bash tests/run_tests.sh` runs PHP syntax checks, Python compile checks, and static contract tests. Set `PHP_BIN` or `PY_BIN` if the tools are not on the expected paths.
- `python tests/contract_tests.py` runs the static contract suite directly.
- `bash tests/run_tests.sh --live` adds read-only endpoint smoke checks and expects Apache, MariaDB, and optionally Flask to be running.
- `cd biosync_ml && pip install -r requirements.txt` installs the ML service dependencies; run `python train_models.py` to train and `python prediction_api.py` to serve predictions.

## Coding Style & Naming Conventions

Follow the surrounding style: PHP files use four-space indentation and descriptive snake_case names for database fields and request actions. Reuse `config.php` and `getDB()` for database access. Keep browser-facing JSON and hardware command formats compatible with their consumers. Python uses four-space indentation and `snake_case`; Arduino changes belong in the existing `.ino` sketch. No formatter or linter configuration is present, so keep edits focused and consistent with adjacent code.

## Testing Guidelines

Add or update checks in `tests/contract_tests.py` when changing cross-file contracts, schema expectations, or endpoint behavior. Use descriptive check names and keep static tests safe to run without live services. Run `bash tests/run_tests.sh` before submitting. Use `--live` only when the local services are available; it sends read-only smoke-test requests.

## Commit & Pull Request Guidelines

Recent commits use short summaries (for example, `UI fixes` and `bug fixes`) without a strict prefix convention. Prefer a concise imperative subject that identifies the change, such as `Fix dashboard stage controls`. Pull requests should explain user-visible behavior and affected components, note database or configuration changes, link related issues when available, and include screenshots for interface changes. Report which checks were run and any service-dependent checks not run.

## Security & Configuration Tips

Do not commit real WiFi credentials, SMS API keys, or production database secrets. Keep local configuration private, and preserve input validation and safe error handling on hardware and web endpoints.
