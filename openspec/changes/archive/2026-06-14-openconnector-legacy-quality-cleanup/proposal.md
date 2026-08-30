# OpenConnector Legacy Quality Cleanup

## Why

The OR-abstraction audit (2026-05-03, stream 3 + the quality-gates
cleanup at session start) flagged that openconnector's quality gates
have a small amount of legacy debt absorbed via exclude patterns.
Burning these down keeps PR diffs honest — gates catch real
regressions rather than silently absorbing already-broken code.

OpenConnector has only 2 phpcs.xml exclude-patterns and no PHPMD or
PHPStan baseline. The bulk of the work here is running PHPMD/PHPStan
as a unified gate for the first time and confirming the codebase is
clean (or capturing a small baseline if not).

This is a tracking change so the burn-down can be picked up later.
It is spec-only; no code changes are proposed in this change.

## What Changes

- Inventory and clear the 2 phpcs.xml exclude-patterns. For each:
  add proper docblocks + named-parameter call audits, then drop
  the exclude.
- Run PHPMD for the first time as a unified gate (phpmd.xml is
  configured but no baseline exists). Capture surfacing violations
  as a baseline OR fix outright depending on volume.
- Run PHPStan for the first time as a unified gate. Same trade-off.
- Wire phpcs/phpmd/phpstan into CI as the unified quality gate.

## Problem

Exclude-patterns exist because the audit captured legacy files that
predated the current quality conventions. The 2-pattern count is
near-zero — the work in this change is mostly about hardening the
gate so it doesn't drift back into having larger exclude lists.

PHPMD/PHPStan baselines don't exist yet because the gates haven't
been run as a unified `check:strict` block. The audit recommended
running them and capturing the result before adoption work.

Now is the time because the per-app OR-abstraction adoption work
(Hydra ADR-022) is touching the same files. Cleanup amortises
across both efforts.

## Proposed Solution

File-by-file cleanup. Because the exclude-pattern count is 2,
Phase 2 is two checkboxes. Phases 3-4 are contingent on what
surfaces when PHPMD / PHPStan run unified.

Estimated effort: 1-2 PRs over 1 sprint.

## Out of scope

- Refactoring beyond what the sniff requires
- New features (separate adoption-spec changes own those)
- Test additions (separate test-coverage spec change if needed)

## See also

- The canonical audit lives in openregister at
  `.claude/audit-2026-05-03/03-repo-hygiene.md`. OpenConnector
  references it from there.
- `phpcs.xml` (the legacy-debt baseline section)
- Hydra ADR-022 (apps consume OR abstractions) — quality conventions
- `composer.json` `check:strict` script (the unified gate target)
