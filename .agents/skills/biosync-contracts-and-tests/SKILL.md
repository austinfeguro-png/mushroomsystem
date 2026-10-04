---
name: biosync-contracts-and-tests
description: Maintain and verify Bio-Sync interfaces across firmware, PHP, Flask, dashboard JSON, and AJAX actions.
---

# Bio-Sync contracts and tests

Use when a change crosses a component boundary or updates `tests/contract_tests.py`. Consult the [project map](../biosync-workflow/references/project-map.md) for current interface names.

- Preserve firmware form POST authentication and response parsing; PHP-to-Flask query parameters; `live_data.php` JSON keys; and each page’s `ajax_action` request/response behavior.
- For every behavior-changing contract fix, add or update a relevant check in `tests/contract_tests.py`. Prefer behavior-level assertions or isolated request/response tests over checking that a source string exists. Don’t broaden the suite into unrelated refactoring.
- `KNOWN_BUGS` is currently empty. Add an entry only for an explicitly deferred failing behavior, with a clear reason; remove it when fixed.
- Run `bash tests/run_tests.sh` when testing is requested or required. `--live` requires local services; its sensor-write check verifies unauthenticated rejection. Report unavailable services separately from failures.
- Keep database and device credentials out of test fixtures and output. Do not use live credentials or mutate live project data in tests.
