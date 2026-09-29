---
kind: code
depends_on: []
---

# Proposal: exchange-read-defaults

## Summary

The `exchange.read` action now defaults to `admin`, `coordinators` and `compliance-officers`.
Those are the groups that read learniq's exchange gates and its status panel. A fresh install
gets the new default from `lib/actions.seed.json`. An installed instance gets it from a new
repair step, but only while its stored value is still the untouched old default `["admin"]` or
the action is missing from the stored matrix. A value an administrator changed stays as it is.

## Motivation

Decision D33 (Ruben, 2026-09-28): "integriq exchange.read defaults to admin, coordinators and
compliance officers, the groups that read learniq's exchange gates." Today the seed is
`["admin"]`, so learniq's coordinators see an empty status panel until an administrator edits
the action matrix by hand (`docs/administrators/exchange-jobs.md`).

The seed alone does not reach an installed instance. `InitializeActions` seeds only an empty
matrix and preserves any non-empty one, so an upgrade needs its own step.

## Affected Projects

- [x] Project: `integriq`: seed value, one repair step, docs.

## Scope

### In Scope

- `lib/actions.seed.json`: `exchange.read` becomes `["admin", "coordinators", "compliance-officers"]`.
- `lib/Repair/BroadenExchangeReadDefault.php`: a post-migration repair step that writes the new
  default only when the stored entry equals `["admin"]` or is absent from a non-empty matrix.
- Unit tests for both branches (untouched default is broadened; customised value is kept).
- `docs/administrators/exchange-jobs.md`: the new default.

### Out of Scope

- `exchange.resubmit` and `exchange.waive` stay admin only (D33 names only reading).
- Creating the groups. Learniq's groups exist where learniq is installed; a group that does not
  exist simply matches nobody.

## Approach

A dedicated repair step reads the matrix through `ActionAuthService`, compares the one entry
with the old default, and writes it back through `setMatrix()`. It runs after
`InitializeActions` so a fresh install is seeded first and the step then finds nothing to do.

## New Dependencies

None.

## Impact

Users in `coordinators` or `compliance-officers` can call the four read endpoints of
`ExchangeController` on instances that kept the default. Nothing else changes.

## Cross-Project Dependencies

Learniq's status panel reads these endpoints. The group ids match learniq's
`components.securitySchemes.oauth2` scopes.

## Risks

### Risk 1: an administrator deliberately chose admin only
**Severity:** Low. **Mitigation:** an explicit `["admin"]` cannot be told apart from the untouched
default. D33 accepts the broadening; the step logs what it changed, and the administrator can
restore admin only in Admin settings. Docs name the change.

## Rollback Strategy

Revert the commit. Instances already broadened keep the three groups until an administrator
edits the matrix; the step never runs in reverse.
