---
kind: config
depends_on: [portal-idp-broker]
---

# Proposal: portal-idp-broker-config

**Status:** blocked — do not start design/tasks artifacts until Open Decisions
D1–D5 are recorded (see `openspec/changes/portal-idp-broker/proposal.md`
§"Open Questions — decisions for the human"). Tracking issue:
[Conduction/openconnector#189](https://codeberg.org/Conduction/openconnector/issues/189).

**Chain position:** link 1 of the `digid-eherkenning-auth-adapter`
implementation chain (proposal §Chaining narrative in `portal-idp-broker`,
tracking [Conduction/openconnector#99](https://codeberg.org/Conduction/openconnector/issues/99)).

## Summary

Scaffold placeholder for the chain link that adds the *Beheer >
Authenticatie* broker-provider config schema and the *Adapters* catalogue
entry `digid-eherkenning-auth-adapter` (ADR-017 Rules 1/3/7), the
`portal_idp.feature_flag` gate, and the certificate/secret storage seams
(ADR-007/ADR-016 encrypted store) that `portal-idp-broker-runtime` will read.

## Why this is a stub, not a full proposal

`portal-idp-broker`'s design.md (D1–D3, D7) fixes the *shape* of this
capability but deliberately leaves the following unresolved, and this change
cannot be designed until they are answered:

- **D1** — broker vendor/contract (Signicat vs OneWelcome vs direct Logius),
  which determines the concrete config fields (entrypoint URLs, metadata
  format, whether polymorphic pseudonymisation is contracted).
- **D2** — certificate custody (doriath vs ADR-016 vault vs HSM), which
  determines where this change's secret-storage seam points.
- **D3** — per-organisation vs shared SP metadata, which determines whether
  the config schema is single-tenant or multi-tenant-parametric.
- **D5** — Berichtenbox deferral confirmation, which determines whether this
  change's catalogue entry scope includes `berichtenbox-mijnoverheid-adapter`
  wiring or stays out of scope.

Writing design.md/tasks.md against guesses for D1–D3/D5 risks the same
"mixed-spec burns budget without a shippable PR" failure mode `portal-idp-broker`
design.md explicitly calls out (§D3 alternatives). This stub exists so the
chain link has a tracked home and issue; `/opsx-continue` should be run on
this change only after Ruben records the D1–D5 outcomes.

## Affected Projects

- [ ] Project: `integriq` — not yet started (blocked, see above).

## Scope

TBD once D1–D5 are recorded — see `portal-idp-broker` design.md §D7 for the
fixed part of the scope (ADR-017 configuration placement) that will not
change regardless of the D1–D3/D5 outcomes.
