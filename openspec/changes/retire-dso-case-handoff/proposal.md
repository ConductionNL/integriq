# Proposal: retire-dso-case-handoff

## Summary

Integriq no longer hands a DSO verzoek off to a case. The case system makes the one case: dossiq's listener reads the mapped `dso_verzoek` and files it on the case type in `mappedCaseTypes`. The `verzoek-to-case` handoff, its trigger endpoint and its 502 path are retired.

## Why

Measured live on 2026-10-08 (integriq ad8a2af0, dossiq after #3412):

- `POST /api/dso/verzoeken/{id}/handoff` answered **502 "The required property (caseType) is missing"**. The handoff sent OpenRegister's `ns#Case` contract (title, summary, channel, source, priority), and that contract has no case type.
- Dossiq's listener already makes one case per mapped verzoek, deduplicated on `permitApplicationRef`. A working handoff would have made a second case for the same verzoek.
- No screen calls the handoff. `src/` has no call to it, and no shared component renders OpenRegister handoffs. Handlers reach the case in dossiq (its DSO dashboard), so "open the existing case" through integriq has no caller either.

## What changes

- Remove the `x-openregister-handoff` block from `dso_verzoek` in both shipped registers. Schema version 1.6.0. The same block also fed OpenRegister's generic `/api/objects/{register}/{schema}/{id}/handoffs` route, which now lists nothing for a verzoek.
- Remove the route `dSO#handoff`, `DSOController::handoff()`, `DsoIngestService::handoff()` and its success and failure writers, and their tests.
- The `handed_off` status value stays readable on records written before this change.

## Impact

- A caller of the retired endpoint gets 404. None is known.
- Paired with dossiq change `dso-single-intake-path`.
