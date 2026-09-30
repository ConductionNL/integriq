---
kind: code
---

# Proposal: integriq-adapter-swv

## Summary
Builds the integriq wire adapter for the SWV (samenwerkingsverband) hand-off — support requests and TLV (toelaatbaarheidsverklaring) — to Kindkans-shaped and LDOS-shaped receiving systems, feeding learniq's existing `swv` `DataExchangeJob` type (M3-integrations.md row **I10**). Per D3 (decisions.md) and change-plan.md row `integriq-adapter-swv`, learniq already declares the job type and composes the dossier (`DataExchangePayloadBuilder`, `SupportRequestDetail`/TLV pages already exist per change-plan.md); this change is purely integriq's wire-adapter half: an abstract `SwvHandoffClient` with a deterministic mock default, a `SwvHandoffSourceAdapter` mapping the already-composed dossier onto the receiver's envelope, and two dormant Source rows (`swv-kindkans`, `swv-ldos`).

## Motivation
Every SWV-connected PO/VO incumbent in market-intelligence round 1 hands off via the OSO SWV protocol to one of a small number of SWV-side systems: Kindkans (43 SWVs, 28 PO + 15 VO, "meer dan 120.000 TLV's", ParnasSys/ESIS/Magister/Somtoday all documented sending via "OSO SWV") and LDOS (Triple W ICT, "OSO import and export" per its own PO manual). Both are named explicitly in this change's scope. learniq's `swv` job type exists but has no wire implementation to either receiver (M3-integrations.md line 56: "job type `swv` exists, no adapter").

## Affected Projects
- [x] Project: `integriq` — new dormant SWV hand-off client (mock-first) + source adapter + mapping, two dormant Source rows.

## Scope

### In Scope
- An abstract `SwvHandoffClient` (mirroring the dormant-adapter shape from `integriq-adapter-lvs-imports` and `integriq-adapter-rostering-imports`) with a deterministic `SwvHandoffClientMock` default.
- A `SwvHandoffSourceAdapter` under `lib/Sources/Swv/` mapping an already-composed SWV dossier (support-request fields, TLV fields) onto the field names the receiver expects, and reading back a dormant "referral accepted/received" acknowledgement.
- Two dormant Source rows sharing the adapter class: `swv-kindkans`, `swv-ldos`, each gated behind `swv.handoff.feature_flag`.
- An operational, non-blocking record of the Privacyconvenant-holder open question: a `swv.privacyconvenant.holder` app-config key that the source adapter reads and logs (empty by default), documented as an operator/governance action, never a code gate — `isActive()` does not depend on it being set.
- Contract tests against a representative fixture.

### Out of Scope
- A live HTTP/OSO transport to Kindkans or LDOS — both require the same governance chain as the other three changes in this wave (Privacyconvenant verwerkersovereenkomst, per-SWV aansluiting) that this change cannot complete.
- The `swv` job type and `DataExchangePayloadBuilder`/`SupportRequestDetail`/TLV composition themselves — already shipped on learniq's side per change-plan.md.
- Onderwijs Transparant and TOP dossier (also OSO-connected SWV systems per care-swv/round1/sources.md) — the lane brief scopes this change to "Kindkans and LDOS shaped" only; a third/fourth SWV shape is a follow-up, not silently added here.
- The generic OSO transport itself (`integriq-adapter-oso`, wave 15 per change-plan.md) — not yet built in this repo; this change does not depend on it and does not duplicate it (see design.md).

## Approach
Same dormant-adapter shape as `integriq-adapter-lvs-imports`: one client family covers both receivers in mock mode (behaviourally identical until a live OSO-shaped binding exists), keeping the two Source rows independently overridable later.

## New Dependencies
None.

## Impact
- New files under `lib/Adapters/Swv/`, `lib/Sources/Swv/`, `tests/Unit/Adapters/Swv/`, `tests/Unit/Sources/Swv/`, `tests/fixtures/swv/`.
- One addition to `lib/sources.seed.json` (two new rows).

## Cross-Project Dependencies
Depends on learniq's `swv` job type and dossier composition (already shipped, per change-plan.md). The mapping in `SwvHandoffSourceAdapter::toHandoffEnvelope()` is the single seam to update if that dossier shape changes.

## Risks

### Risk 1: Dossier field names are inferred from public SWV-system documentation, not a live dossier sample
**Severity:** Medium — **Mitigation:** same mitigation pattern as `integriq-adapter-lvs-imports` — the fixture and mapping are isolated in one method, correctable against a real dossier once a design-partner SWV supplies one.

## Rollback Strategy
Revert the merge commit. The two Source rows ship `isEnabled: false`; removal is a pure deletion.

## Open Questions
Who holds the Privacyconvenant verwerkersovereenkomst and each SWV's own aansluiting centrally for a self-hosted, multi-tenant open-source LAS is recorded as an operational gate per this change (`swv.privacyconvenant.holder` app-config key, logged, never blocking) — the lane brief's explicit instruction, not resolved here.
