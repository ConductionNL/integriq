---
kind: code
---

# Proposal: integriq-adapter-lvs-imports

## Summary
Build the integriq wire adapter that pulls normed test results (referentieniveaus) from the four Dutch PO leerlingvolgsysteem (LVS) test suites — Cito (Leerling in Beeld, via DULT), IEP (Bureau ICE), Boom (Boom Testcentrum) and Dia (Diataal) — and hands them to learniq's `lvs-import-contract` `DataExchangeJob` type as an UWLR-shaped result payload. learniq already declares the job type and payload contract (`m1#6.5`, `L-new-1`); today there is no wire implementation for any of the four suppliers (change-plan.md row `integriq-adapter-lvs-imports`, wave 9).

## Motivation
Every PO incumbent surveyed in market-intelligence round 1 documents a live koppeling from at least one of these four suppliers into its LAS: Cito via DULT-verwerking (ParnasSys, Esis), IEP via UWLR-shaped "resultatenkoppeling" (ParnasSys FAQ: "De resultatenkoppeling met ParnasSys is nog niet compleet" as of 2020, now shipped), Boom via UWLR to ParnasSys/Esis/Focus PO/Volglijn/Schoolkr8/Magister (Boom testcentrum manual p33), and Dia to ParnasSys/Esis (PO)/Somtoday/Magister (VO) (M3-integrations.md row I8; lvs-report/round1/sources.md). Without this adapter learniq schools must key toets results in by hand, the single largest total-absence finding for I8 in the whole corpus (`no Cito/LVS-specific results schema exists at all` — M3-integrations.md line 67).

## Affected Projects
- [x] Project: `integriq` — new UWLR-shaped result-import client (mock-first) + source adapter + mapping to the `lvs-import-contract` payload, one Source row per supplier.

## Scope

### In Scope
- An abstract `UwlrResultImportClient` (mirroring the `BerichtenboxClient`/`PdokWmsClient` dormant-adapter shape already in `lib/Adapters/`) with a deterministic `UwlrResultImportClientMock` default and a `flavour()` self-identifier.
- A `UwlrResultImportSourceAdapter` under `lib/Sources/Lvs/` that maps a fetched UWLR-shaped result batch (leerling reference, toetscode, referentieniveau, vaardigheidsscore, afnamedatum, groep) onto the field names learniq's `lvs-import-contract` payload expects.
- Four dormant Source seed rows in `lib/sources.seed.json` — `lvs-cito-dult`, `lvs-iep`, `lvs-boom`, `lvs-dia` — sharing the one adapter class, distinguished by `subCategory` and `documentation` link, each gated behind `lvs.import.feature_flag`.
- Contract tests against representative UWLR-shaped fixtures (field names drawn from the Boom Testcentrum manual's documented UWLR/EdeXML export and the koppeling FAQs cited above; **not** captured from a live supplier feed — no supplier publishes an open UWLR XSD, and no live credentials exist for this round).

### Out of Scope
- A live HTTPS/SFTP transport to any of the four suppliers. Each requires its own commercial koppelpartner onboarding (Boom: "eenmalige opstartkosten"; Cito DULT step-3 documentation page 404s as of 2026-09-25) that is not something Conduction can complete inside a code change — deferred to a follow-up per-supplier change once a design partner school signs up with a real koppeling.
- The learniq-side `lvs-import-contract` job type and payload builder themselves — those are declared on learniq's side per D3 and are this change's one dependency (already shipping per change-plan.md wave 9, same wave as this change).
- The Doorstroomtoets voorlopig-advies exchange (`I9`) — a different, PO-only coupling to the schooladvies flow, not the LVS result import this change covers.

## Approach
Follow the abstract-integration pattern already established for `lib/Adapters/Berichtenbox` and `lib/Adapters/Pdok`: an abstract client class with one deterministic mock subclass shipped enabled-by-default-dormant (config flag off), a thin Source-pattern facade that never leaks PII into the debug logger (pupil name/BSN are never logged, only counts and a redacted `leerlingReference` hash), and Source rows seeded but disabled until an operator supplies a real UWLR endpoint. Because Cito/IEP/Boom/Dia all converge on the same wire format (UWLR, confirmed for Boom directly and implied by "resultatenkoppeling"/"koppeling" language for the other three), one client + one mapping service serves all four, avoiding four near-duplicate adapters.

## New Dependencies
None. No new Composer or npm packages.

## Impact
- New files only under `lib/Adapters/Lvs/`, `lib/Sources/Lvs/`, `tests/Unit/Adapters/Lvs/`, `tests/fixtures/lvs/`.
- One addition to `lib/sources.seed.json` (four new rows).
- No changes to existing controllers, routes, or the OpenRegister schema (the LVS/toets-results schema itself is an `openregister` request per M3-integrations.md line 67, not this change).

## Cross-Project Dependencies
Depends on learniq's `lvs-import-contract` `DataExchangeJob` type + payload mapping (learniq round-1 change, same wave). This change's payload mapping targets that contract's documented field names; if learniq's contract shape changes before archive, the mapping in `UwlrResultImportSourceAdapter::toLvsImportPayload()` is the single place to update.

## Risks

### Risk 1: UWLR fixture shape is inferred, not captured from a live feed
**Severity:** Medium — **Mitigation:** the fixture and mapping are built from the one primary-source UWLR reference this round found (Boom Testcentrum manual, local PDF extraction, p33/p45) plus the shared "UWLR" vocabulary used by the ParnasSys/Esis-side koppeling pages for IEP and Dia. The mapping is isolated in one method so a real captured payload from a design-partner school can correct field names without touching the client or Source-row shape.

### Risk 2: Four suppliers, one client, risks masking supplier-specific quirks
**Severity:** Low — **Mitigation:** each Source row carries its own `subCategory` and `documentation` link so a future supplier-specific override (e.g. Cito's DULT-verwerking step) can subclass the shared client without changing the other three rows.

## Rollback Strategy
Revert the merge commit. The four Source seed rows ship `isEnabled: false`; removing them is a pure deletion with no data migration (no OpenRegister objects reference these Source ids until an operator instantiates one).

## Open Questions
Who holds the commercial koppelpartner relationship with Cito/IEP/Boom/Dia for a self-hosted, multi-tenant open-source LAS is a governance question the same shape as the DUO/Edu-V/Privacyconvenant gates in M3-integrations.md (c) — not resolved here, and not a code blocker per the lane brief.
