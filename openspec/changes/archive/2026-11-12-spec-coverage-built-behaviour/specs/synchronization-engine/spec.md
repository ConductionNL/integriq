# synchronization-engine Specification

## ADDED Requirements

### Requirement: Synchronization conditions skip records (REQ-SCB-009)

A synchronization MAY carry conditions, written as JsonLogic in the conditions builder. Integriq MUST test each source record against them. A record that does not meet them MUST be skipped and not written to the target.

#### Scenario: only open cases are copied
- GIVEN a synchronization with the condition `status == "open"` and a source with three open and two closed cases
- WHEN the synchronization runs
- THEN three objects are written and the two closed cases are skipped
- @e2e exclude synchronization run; covered by PHPUnit on SynchronizationService
