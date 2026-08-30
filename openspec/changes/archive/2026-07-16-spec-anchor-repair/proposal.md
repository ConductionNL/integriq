---
kind: config
---

## Why

`@spec openspec/...` docblock tags link code to the requirement it implements
(ADR-020); hydra gate-46 `spec-anchor-existence` enforces that the target file
and `#requirement` heading actually resolve. A fleet audit found broken anchors
on a mass scale — **1,809 in openconnector** on base `origin/development`, out of
2,519 total `@spec` tags (72%). A broken anchor is code *claiming* to
implement a requirement whose target does not resolve: the traceability layer is
fiction, and gate-46 cannot tell a genuine regression from the noise.

The dominant cause is mechanical, not conceptual. The `/opsx-annotate` retrofit
tagged methods with `@spec openspec/changes/<slug>/tasks.md#task-N` — pointing at
the **change directory** rather than canonical `openspec/specs/`. When each change
was archived (`changes/ → changes/archive/<date>-<slug>/`) the target evaporated.
This is exactly the failure the team already knows about: *`@spec` must target
canonical `openspec/specs/`, NEVER a change dir — they evaporate on archive.*
The intended requirement is usually still recoverable, because the archived
`tasks.md` line encodes the capability and requirement-heading text verbatim
(`- [x] task-7: widget-registry#REQ-001 — The system MUST …`).

## What Changes

A **deterministic, comment-only** repointer rewrites every *unambiguously*
resolvable broken `@spec` anchor to its canonical
`openspec/specs/<cap>/spec.md[#requirement-<slug>]` target, and **flags
everything else for human triage rather than guessing**.

- **1,337 anchors repointed** across 222 files
  (403 to an exact requirement heading, 934 downgraded to
  capability-level file granularity).
- **472 anchors left untouched** and filed for human review
  (`residual-dangling.md` + umbrella issue) — never guessed.
- **gate-46 (`spec-anchor-existence`): 1,809 → 472 broken.**
- The diff is **`@spec`-comment-lines only**: 2,674 changed lines, **0**
  non-`@spec` lines, **0** files with asymmetric insertions/deletions.

No behaviour, logic, or public API changes. This is a traceability repair.

## Impact

- Affected specs: none (no requirement text changes; anchors point *at* specs).
- Affected code: `lib/`, `src/` — docblock `@spec` comment tags only.
- Gate-46 noise drops by 74%, so a *newly* broken anchor in a PR is now
  visible instead of drowning in 1,809 pre-existing failures.
