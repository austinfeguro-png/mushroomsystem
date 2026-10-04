---
name: biosync-stage-lifecycle
description: Change or review Bio-Sync growth-stage timing, reminders, manual advancement, extensions, and stage threshold synchronization.
---

# Bio-Sync stage lifecycle

Use only for stage state, reminders, Extend/Advance actions, or stage-specific threshold changes. Consult the [project map](../biosync-workflow/references/project-map.md) for duplicated writers/readers and keys.

Preserve the intended progression: colonization → pinning → fruiting. Reaching the expected day prompts inspection; it does not advance automatically. An authorized user may confirm readiness to advance or extend the current stage. Fruiting is the final stage: show cycle completion rather than silently wrapping to colonization.

When changing a transition, keep `current_stage`, `stage_started_at`, and `stage_expected_days` consistent; use that stage’s four temperature/humidity limits and default duration; clear or resolve the pending reminder appropriately; and retain an auditable notification. Check the five settings pages and all threshold consumers in the project map before editing duplicated logic.

Cover the affected path with checks for each forward transition, extension/restart timing, reminder behavior after either decision, final-stage completion, and threshold synchronization to PHP, firmware, and ML consumers. Do not alter schema or database seed data without separate approval.
