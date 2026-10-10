---
kind: code
depends_on: []
---

# Proposal: open-formulieren-submits-into-its-case

integriq's half of decision 179 (Ruben, 10 October 2026): "We dont intake to an intake, we intake into a case, or ticket or something else." Cross-app change: `hydra/openspec/changes/form-submits-into-its-destination-object`, architecture in hydra ADR-117. Needs `openregister/form-destination-validator`. Built on the recommended answer to question Q5 (Open Formulieren and DSO in scope; mail, chat and post out of scope) until Ruben answers.

## Why

An Open Formulieren submit arrives at `OpenFormulierenController::inbound` and is stored as an `openformulieren_submission` (received, then mapped or failed). A later authenticated `handoff()` runs OpenRegister's `HandoffService` to create the case. The per-form `openformulieren_form_mapping` is never checked against the case schema, so a mapping that misses a required property fails only when a person tries the handoff.

## What changes

1. **The mapping is checked when it is saved.** Saving an `openformulieren_form_mapping` runs OpenRegister's form destination validator against the target case schema. A mapping with findings is not activated.
2. **Inbound creates the case.** `inbound()` maps the payload and calls `FormSubmitService` in the same request, with the Open Formulieren submission id as idempotency key and `externalReference`. It answers 201 with the case reference, or 422 with findings so Open Formulieren reports the failure on its side.
3. **A raw-message log, not a staging object.** integriq keeps the received payload hash, the time and the outcome for audit, never as something to process later.
4. **Drain, then remove.** `occ integriq:openformulieren:drain` pushes every `received` and `failed` submission through the new path and reports; the staging schema goes at zero pending.
5. **DSO.** `dso_verzoek` already maps into a case in the same request through dossiq's listener. This change adds the mapping check at save time and records it as compliant. It does not move the DSO path.

## Rollback

The staging schema stays until its drain reports zero.
