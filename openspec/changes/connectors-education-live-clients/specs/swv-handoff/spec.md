# swv-handoff Specification

## ADDED Requirements

### Requirement: A live client hands a support request to the SWV system (REQ-005)

Integriq MUST ship a live `SwvHandoffClient` and bind it when the receiver source's live flag is on. The client MUST send the composed dossier to the receiver's address with a brokered credential and MUST record the receiver's acknowledgement. With the flag off, the mock MUST stay bound.

#### Scenario: a dossier reaches the samenwerkingsverband
- GIVEN a receiver source with the live flag on
- WHEN a school hands over a support request
- THEN the receiver acknowledges it and the acknowledgement is recorded

#### Scenario: a failed hand-off is kept
- GIVEN the receiver is unreachable
- WHEN the hand-off runs
- THEN the dossier stays queued and the failure is recorded
