# Tasks: tables-bridge (superseded)

The original 12-task / 35-checkbox list was removed with the 2026-09-02
retirement (see proposal.md for the disposition; the list survives in
`archive/2026-07-15-tables-bridge/tasks.md`, where 30/35 boxes are checked
with per-task evidence, and in git history). There is nothing to implement
from this change directly.

The three headings below are live `@spec` anchor targets (tags in
`lib/Service/Tables/` and `tests/`); they keep their original text so the
tags resolve.

### Task 2: `TablesSyncAdapter` — column cache, title→columnId resolution, coercion

Shipped: `lib/Service/Tables/TablesSyncAdapter.php` at HEAD; evidence in the
archived twin.

### Task 11: Unit tests — coercion, contract mapping, adapter (stubbed client)

Shipped: `tests/Unit/Service/Tables/` at HEAD; evidence in the archived twin.

### Task 12: Integration coverage against a real Tables app, with CI-image fallback

Shipped: `tests/Integration/Tables/TablesBridgeIntegrationTest.php` at HEAD;
evidence in the archived twin.
