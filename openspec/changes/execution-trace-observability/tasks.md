# Tasks: execution-trace-observability (superseded)

The original 15-task / 41-checkbox list was removed with the 2026-09-02
retirement (see proposal.md for the disposition; the list survives in
`archive/2026-07-16-execution-trace-observability/tasks.md`, where 27/42
boxes are checked with per-task evidence, and in git history). The residual
verification (job replay's missing no-write mode, the suspend→resume trace
round trip, rendering the Traces UI, scraping the metric, the docs page) is
listed there with per-box reasons and deserves its own
verification-pack-style successor when the observability track resumes.

`design.md` stays in this directory as the reference target for
`lib/Settings/register.d/execution-trace-observability.json`. There is
nothing to implement from this change directly.
