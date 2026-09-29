---
name: project-conventions
description: Bio-Sync PHP coding conventions — inline AJAX pattern, session auth checks, DB connection style, and file-level gotchas. Load before editing any .php file in this repo.
metadata:
  category: development
---

# Bio-Sync PHP Conventions

## Instructions

1. Follow the existing per-file structure: session/auth check → DB connection → AJAX handler block → HTML/UI. Do not restructure pages.
2. All AJAX endpoints are inline in the same page file: POST with `ajax_action` (or GET `?ajax=` in `notificationspage.php`), respond with `header('Content-Type: application/json')` + `json_encode`, then `exit()`.
3. Auth gating pattern: `session_start()`, then `if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }`. Admin-only pages additionally check `strtolower($_SESSION['user_role']) === 'admin'`.
4. DB connections use PDO with `utf8` charset, `ERRMODE_EXCEPTION`, `FETCH_ASSOC` defaults. Credentials are duplicated per-file (XAMPP defaults: localhost / root / empty). Match the existing block; don't invent a new connection method.
5. NOTHING may be echoed or emitted before the `<?php` tag in files that send headers (login/dashboard redirects). No leading whitespace or BOM.
6. Escape user-facing strings with `htmlspecialchars()`. Use prepared statements for all queries — never interpolate `$_POST`/`$_GET` into SQL.
7. Files use CRLF line endings and tabs/4-space mix — don't run repo-wide formatters that would reformat everything.
8. User-facing roles/status values: role is `user`/`admin` (compare lowercase), status is `Active`/`Inactive` (capitalized in DB, compared case-insensitively).
