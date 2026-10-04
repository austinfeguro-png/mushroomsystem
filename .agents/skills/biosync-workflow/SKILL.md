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

## Repository safeguards

- Never run state-changing Git commands, including add, commit, push, stash, reset, checkout, merge, rebase, or tag. Read-only Git is allowed.
- Schema, dependency, and `config.php` credential changes require separate approval.
- Run `tests/run_tests.sh` only when the user asks for tests/verification or the task explicitly requires it. Otherwise report that tests were not run.
- At closure, report files changed, checks run and skipped, relevant documentation drift, a redacted secret/personal-data scan result, and a suggested commit message. Never print secret values or personal data discovered during a scan.
- Do not fix unrelated findings. End implementation reports with: “Changes are uncommitted. Please review the diff and commit manually.”
