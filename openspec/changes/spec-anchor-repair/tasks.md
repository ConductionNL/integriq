# Tasks — spec-anchor-repair (openconnector)

- [x] task-1: Measure broken `@spec` anchors repo-wide with gate-46 resolution logic (1,809 on base `origin/development`).
- [x] task-2: Categorise broken anchors by cause (archived → canonical-recoverable vs genuinely-dangling).
- [x] task-3: Reuse the deterministic resolver/repointer proven on openregister (#457) and pipelinq (#404) — no rebuild.
- [x] task-4: Fix tool defect 1 — CRLF files were normalised LF on write (found on procest, 2,462 non-`@spec` lines); open with `newline=''`, skip files that cannot be decoded losslessly.
- [x] task-5: Fix tool defect 2 — raw fragment with trailing punctuation was emitted, producing an anchor gate-46 still rejects (found on decidesk); emit `slugify(frag)` and align the tool's brokenness test byte-for-byte with gate-46.
- [x] task-6: Add regression tests for both defects; verified they FAIL against the old tool and PASS against the fixed one.
- [x] task-7: Apply the repointer — 1,337 anchors repointed across 222 files (403 anchor-level, 934 file-level).
- [x] task-8: Verify 0 repoint candidates rejected by the gate-46 post-condition check.
- [x] task-9: Comment-only proof — 0 non-`@spec` changed lines out of 2,674; 0 files with asymmetric insertions/deletions; 0 files touched outside `lib/`/`src/`.
- [x] task-10: Reconcile the tool's count against gate-46 exactly (`before − repointed == after`).
- [x] task-11: Gate-46 re-verify — broken **1,809 → 472**.
- [x] task-12: File the 472 residual-dangling anchors for human triage (`residual-dangling.md` + umbrella issue).
- [x] task-13: STALE-BASE GUARD before push — `git log HEAD..origin/development` empty; diff is `@spec`-lines-only.
- [x] task-14: PR to `development`, admin-merge, archive change.
