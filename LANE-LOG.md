# Lane log: r2-slo (integriq, clone iq-adapters-a)

Note: LANE-LOG.md is tracked on integriq `development` (earlier lanes committed theirs). This file is a
LOCAL modification and is never staged in this lane's commits.

## Change 1/1: slo-kerndoelen-import — DONE (PR open, not merged)
- Branch: `feat/integriq-adapter-slo-kerndoelen`, cut `--no-track` from `origin/development` at b6cdf26a.
- PR: https://github.com/ConductionNL/integriq/pull/2198 (base development). Head e02cce5d.
- Time: ~12:50 to ~14:55 (2026-09-27).
- OpenSpec: openspec/changes/slo-kerndoelen-import (proposal, discovery, contract, spec, design, test-plan,
  tasks; migration skipped: no schema/table change) + canonical openspec/specs/slo-curriculum-import/spec.md.
  `openspec validate` valid. tasks 16/16 ticked.
- Verification (exit codes): php -l 0; phpcs 0; phpstan 0; psalm 0 (no errors); phpmd 0 (both rule sets);
  SLO unit tests 114 green; spec anchors 0 findings (checker positive-controlled);
  `composer check:strict` 0 (ALL CHECKS PASSED, full suite 4130 tests, 14573 assertions);
  npm lint 0 (112 inherited warnings), format 0, test:l10n 0, check:schema-l10n 0, check:json-strict 0,
  check:register 0; hydra gates with HYDRA_GATES_HOME=vendor/... exit 0 (85/85 applicable ran).
  First gates run against shared apps-extra/.github/hydra-gates exited 1: gate-53 crash + gates 22/68/104/107
  not run (ES-module require error from workspace package.json "type":"module"): environment, not the diff.
- opsx-verify (headless): 3 gaps found and fixed (REQ-009 log wording, node-limit test, operator docs page).
- e2e: 20 scenarios excluded per requirement with the PHPUnit test named (gate-19 uncovered 52 -> 32).
- Left undone:
  - No live keyed SLO response: API needs a registered key (401 without). Fixture = real release data
    (curriculum-fo@2026.8, others @2026.7) in the server's documented shape. Register a key, re-record.
  - Learniq write step (Synchronization into register learniq) after learniq competency-year-scope merges.
  - CI read once at lane end (see below).
- CI read once at 12:09Z: 4 pass, 2 skipping, 30 pending, 0 failing. Not polled further (lane rules).
