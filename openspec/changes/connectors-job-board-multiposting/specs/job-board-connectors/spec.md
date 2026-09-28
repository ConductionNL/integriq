# job-board-connectors Specification

## ADDED Requirements

### Requirement: A flow step can pick its source and mapping per item (REQ-JBC-001)

The `openconnector.source-call` and `openconnector.apply-mapping` nodes MUST accept a `source` or `mapping` reference that is a template over the item, MUST render and resolve it per item, and MUST resolve each distinct value only once per run. An item whose reference does not resolve MUST fail on its own with the unresolved name under the step's error policy. A plain reference MUST behave as before.

#### Scenario: one vacancy goes to two boards
- GIVEN humaniq's flow iterating a vacancy over the channels `werk-nl` and `linkedin`
- WHEN the source call step runs with source `jobboard-{{ json.channel }}`
- THEN the werk-nl item is sent through `jobboard-werk-nl` and the LinkedIn item through `jobboard-linkedin`
- @e2e exclude flow node behaviour; covered by PHPUnit

#### Scenario: a board without a source
- GIVEN a vacancy whose channels include a board with no configured source
- WHEN the step runs with `onError: continue`
- THEN that item is an error item saying "no source configured" with the name, and the other boards are posted
- @e2e exclude flow node behaviour; covered by PHPUnit

### Requirement: werk.nl, LinkedIn and Indeed are job board templates (REQ-JBC-002)

Integriq MUST ship dormant templates for werk.nl, LinkedIn and Indeed with credentials by broker reference, and a mapping per board from humaniq's `Vacancy`. For a board that pulls vacancies, integriq MUST publish a feed listing only published vacancies whose channels include that board, without applicant data.

#### Scenario: Indeed reads the feed
- GIVEN two published vacancies, one listing `indeed` in its channels
- WHEN Indeed requests `/api/endpoint/jobfeeds/indeed.xml`
- THEN the feed lists that one vacancy and nothing else
- @e2e exclude public feed without a screen; covered by Newman
