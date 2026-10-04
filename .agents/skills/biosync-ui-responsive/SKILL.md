---
name: biosync-ui-responsive
description: Change or review responsive layout, touch usability, modals, and dark-mode styling in Bio-Sync pages.
---

# Bio-Sync responsive UI

Use for visual layout, CSS, and mobile interaction changes. Consult the [project map](../biosync-workflow/references/project-map.md) when UI controls call shared PHP/AJAX contracts; keep those contracts unchanged unless separately authorized.

- Preserve the existing responsive breakpoints around 700px, 640px, and 480px; some pages also use page-specific breakpoints such as 1100px, 900px, or 420px. Follow the target page’s existing pattern.
- On mobile, prevent horizontal overflow, respect safe-area insets, keep form inputs at least 16px where iOS zoom is a concern, and keep coarse-pointer targets about 44px.
- Follow existing modal behavior: bounded viewport height, scrollable body, clear close controls, and appropriate keyboard/Escape handling. Preserve semantic labels and focus visibility.
- Use the established CSS variables and `body.dark` theme rules. Check shared nav/settings/modal patterns on every affected page because styles are duplicated across PHP templates.
- Verify at representative widths near 700px, 640px, and 480px, plus desktop and dark mode. Include screenshots for material visual changes when available; do not claim visual verification without viewing the rendered page.
