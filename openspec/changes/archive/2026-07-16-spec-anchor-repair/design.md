# Design — spec-anchor-repair (openconnector)

## Category breakdown (base `origin/development`)

1,809 broken `@spec` anchors, classified by recoverability:

| Category | Count | Disposition |
|---|---|---|
| **(c) archived change → canonical spec recoverable** | **1,337** | **auto-repointed** |
| &nbsp;&nbsp;↳ exact requirement-heading match | 403 | `specs/<cap>/spec.md#requirement-<slug>` |
| &nbsp;&nbsp;↳ capability proven, heading not matched | 934 | `specs/<cap>/spec.md` (file-level) |
| **(d)**  change uses non-annotate tasks.md (no `task-N: cap#REQ` line) — needs spec-delta read | 227 | flag |
| **(d)**  non-tasks.md ref (decimal task / design.md / proposal.md / re-headed specs anchor) | 200 | flag |
| **(c)**  capability archived/deleted — requirement genuinely gone | 41 | flag |
| **(d)**  archived change dir not located | 3 | flag |
| **(d)**  no-fragment tasks.md ref, slug not a canonical capability | 1 | flag |
| **Total flagged for human triage** | **472** | `residual-dangling.md` |

Auto-repointed **74%**; the residual 472 stay broken *on purpose*
— see "What we deliberately did NOT do".

## The repointer's conservatism rules

The tool repoints **only** on unambiguous, verifiable signals:

1. **`changes/<slug>/tasks.md#task-N`** — locate the archived `tasks.md`
   (deterministic paths first, then the exact date-prefixed
   `changes/archive/YYYY-MM-DD-<slug>/` convention, and only on a *unique*
   match). Parse the `task-N` line for a `<cap>#<REQ>` token, else fall back to
   the enclosing `## <cap>` section heading. The capability is taken
   **verbatim** — never inferred.
2. **Anchor granularity only on an exact requirement-heading text match**
   (`requirement-` + `slugify(title)` equals a real heading slug in the canonical
   spec). Otherwise the fragment is dropped and the anchor becomes
   capability-level — an honest downgrade, never a positional or fuzzy guess.
3. **`changes/<slug>/specs/<cap>/spec.md#anchor`** → canonical
   `specs/<cap>/spec.md`, keeping the anchor only if it still resolves.
4. **Post-condition gate** — every proposed target is re-checked with gate-46
   logic *before* it is written; a candidate that would not resolve is rejected
   and the anchor stays dangling. **Observed on openconnector: 0 rejects.**
5. Anything not covered by 1–3 → **DANGLING**, never guessed.

## Comment-only proof (why scripting is acceptable here)

The "no scripting for code changes" rule guards against a script mangling
*logic*. This edit touches only docblock `@spec` comment tags, hand-editing
1,337 anchors is infeasible and more error-prone, and the safety property
is **mechanically verifiable** rather than asserted:

- The rewrite runs through the gate-46 `@spec` tag regex — only a tag's *target
  substring* can change.
- **Assertion 1** — every `+`/`-` line in `git diff` contains `@spec`:
  **0 non-`@spec` lines out of 2,674**.
- **Assertion 2** — `git diff --numstat`: every file has
  `insertions == deletions` (**0 asymmetric files**). No line added or removed
  ⇒ no statement added or removed.
- **Assertion 3** — nothing changed outside `lib/` and `src/`.
- **Assertion 4** (unit test) — on a synthetic fixture, logic lines are
  byte-identical and a genuinely-dangling anchor is left untouched.

## Two tool defects found and fixed during this round

The tool shipped in the earlier openregister/pipelinq/shillinq rounds. Running it
against a wider app set exposed two defects; both are now fixed **and covered by
regression tests that fail against the old tool**:

1. **CRLF normalisation (found on procest).** Python's universal-newline read +
   default write silently rewrote CRLF files to LF. Five procest files carry CRLF
   endings, producing **2,462 non-`@spec` diff lines** — a whitespace reformat
   wearing an anchor-repair hat. The comment-only assertion caught it; it would
   have sailed through a "looks like comments" review. Fixed by opening with
   `newline=''` on both read and write. Files that cannot be decoded losslessly
   are now skipped rather than rewritten with `errors='replace'` (which would
   burn U+FFFD into source).
2. **Raw-fragment leak (found on decidesk).** The `@spec` regex swallows a
   sentence-ending `.` into the fragment. The resolver matched on
   `slugify(frag)` but emitted the **raw** fragment, writing
   `…#requirement-foo-setting.` — which gate-46 (verbatim compare) still counts
   as broken. The tool "repaired" an anchor into another broken anchor, and its
   own post-condition check was blind because it used the same lenient
   comparison. Fixed by emitting `slugify(frag)` and by making the tool's
   brokenness test **byte-identical to gate-46** (verbatim fragment compare).

Defect 2 means the tool's count and gate-46's count now **reconcile exactly** on
every app: `before − repointed == after`. That identity is the real check — it
is why defect 2 was visible at all (a 2-anchor drift on decidesk, 12 on
openconnector).

## What we deliberately did NOT do

The residual 472 anchors are **not** force-removed or re-pointed on a
best guess. Removing the tag would delete the only evidence that the code was
meant to implement *something*; guessing the target would make the traceability
layer confidently wrong instead of visibly broken — strictly worse, because
gate-46 would then go green over a lie. They are enumerated in
`residual-dangling.md` and filed as an umbrella issue for human triage.
