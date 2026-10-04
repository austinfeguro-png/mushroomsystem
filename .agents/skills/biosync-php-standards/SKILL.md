---
name: biosync-php-standards
description: Review or edit Bio-Sync PHP handlers and pages for authentication, authorization, CSRF, SQL, escaping, and JSON behavior.
---

# Bio-Sync PHP standards

Use for PHP backend or page-handler changes; use `biosync-ui-responsive` for CSS-only work. Check the [project map](../biosync-workflow/references/project-map.md) for duplicated settings paths and contracts.

For each changed route, verify the applicable controls:

- Require a logged-in session; require the admin role for administrative actions. Device ingestion uses its device token rather than a user session.
- Protect state-changing POST/AJAX actions with the session CSRF token; validate action names, enum values, IDs, and numeric ranges server-side.
- Use prepared statements for values and allowlists for SQL identifiers. Keep using `config.php`/`getDB()` for DB access.
- Escape HTML output for its context. Return consistent JSON content types, status codes, and non-sensitive errors for AJAX requests.
- If changing shared settings, inspect all five settings pages listed in the project map and keep their stage/algorithm allowlists and persistence behavior aligned.

Do not edit schema, dependencies, or `config.php` credentials without separate approval. Shared PHP includes are a possible future refactor, not an automatic part of a focused fix. Never echo credentials or raw exception details to clients.
