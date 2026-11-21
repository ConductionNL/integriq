# lvs-result-import Specification

## ADDED Requirements

### Requirement: A live UWLR client reads results from the pupil tracking system (REQ-004)

Integriq MUST ship a live `UwlrResultImportClient` and bind it when the supplier source's live flag is on. The client MUST fetch a UWLR result batch from the supplier's address with a brokered credential. With the flag off, the mock MUST stay bound.

#### Scenario: Cito results arrive for a group
- GIVEN a Cito source with the live flag on
- WHEN the import runs for a group
- THEN the batch Cito returns is mapped onto the lvs-import-contract payload

#### Scenario: a refused credential stops the batch
- GIVEN a live source whose credential the supplier refuses
- WHEN the import runs
- THEN no results are written and the source shows the refusal
