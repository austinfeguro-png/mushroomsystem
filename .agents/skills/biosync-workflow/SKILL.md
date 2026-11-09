---
name: biosync-workflow
description: Coordinate repository scans, proposed fixes, approvals, and safe completion for the Bio-Sync project.
---

# Bio-Sync workflow

Use this for repository reviews and implementation requests. Read [the project map](references/project-map.md) when a task depends on file roles, shared settings, interfaces, or branch and line-ending conventions. Let the narrower Bio-Sync domain skills handle implementation details when they apply.

## Work routine

1. For a scan or review, restate the task and classification in 1–2 lines. Ask no more than three questions, and only when an answer would change the work.
2. Search first with `rg`; inspect only relevant line ranges. Read a whole file only when most of it needs editing. List files read and related files ruled out.
3. Report findings before proposing changes. Small fixes need root cause and a short proposal. Larger changes need numbered items, requirement status, file/line, concise diff, risks, and a manual check.
4. For review-first requests, ask `Apply which? (all / numbers / required only / none)` and wait. A direct request to implement a specific change authorizes that change; don’t ask again for already-approved work.
5. Apply only authorized changes, keep diffs small, preserve each file’s existing line endings, and do not commit.

## Senior-developer decision review

For recommendations or changes that cross the device, server, database, and UI path:

- Trace the real flow in source before choosing timing or behavior: device sampling, upload/poll cadence, server persistence, actuator command delivery, feedback reporting, and browser polling.
- Distinguish a saved request from a command received by firmware, an output applied by hardware, and feedback reported to the UI. Do not describe one as proof of another.
- Estimate best-case and worst-case synchronization delay from the actual intervals, request timeouts, and UI polling cadence. Include added server/database load when proposing more frequent requests.
- Preserve firmware fail-safes, interlocks, run limits, and cooldowns. Do not change actuator safety timing just to make the UI appear faster; require hardware-specific reasoning and verification for such changes.
- Check for stale or overlapping feedback, rapid conflicting user actions, and cases where the requested state differs from the physical state. Prefer clear pending/confirmed/timeout UI states; use command identifiers or versioned acknowledgements when state matching alone cannot confirm a particular command.
- Reassess earlier advice against the code and correct it plainly when new evidence changes the recommendation. For ambiguous requests, explain the relevant distinction and ask only for a decision that materially changes the implementation.

## Repository safeguards

- Never run state-changing Git commands, including add, commit, push, stash, reset, checkout, merge, rebase, or tag. Read-only Git is allowed.
- Schema, dependency, and `config.php` credential changes require separate approval.
- Run `tests/run_tests.sh` only when the user asks for tests/verification or the task explicitly requires it. Otherwise report that tests were not run.
- At closure, report files changed, checks run and skipped, relevant documentation drift, a redacted secret/personal-data scan result, and a suggested commit message. Never print secret values or personal data discovered during a scan.
- Do not fix unrelated findings. End implementation reports with: “Changes are uncommitted. Please review the diff and commit manually.”
