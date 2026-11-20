---
description: Start a new OpenSpec change
---

Start an OpenSpec change named $ARGUMENTS (kebab-case, describing the outcome).

1. Read `openspec/config.yaml` for the project context and the rules per artifact, and the
   ADRs in `openspec/architecture/`.
2. Check `openspec/specs/` for the capability this touches, and `openspec/changes/` for an
   open change that already covers it.
3. Create `openspec/changes/$ARGUMENTS/` with `proposal.md`, `design.md`, `tasks.md` and
   delta specs under `specs/<capability>/spec.md`. Use an existing change in
   `openspec/changes/` as the model for layout.
4. Do not write code until the proposal and tasks are in place. When the change is done, its
   requirements move to `openspec/specs/` and the change moves to `openspec/changes/archive/`.
