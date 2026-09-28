# rule-editor-ui delta

## ADDED Requirements

### Requirement: An administrator can start a flow from an endpoint rule (REQ-AFT-001)

The rule editor MUST offer the `flow` action with a labelled picker of the instance's flows, MUST write the picked flow's id to `configuration.flow`, and MUST refuse to save a flow rule that names no flow.

#### Scenario: a partner's webhook starts the intake flow
- GIVEN an endpoint `/meldingen` and a flow "Melding intake"
- WHEN an administrator adds a rule with action Flow and picks "Melding intake", and a partner then posts to `/meldingen`
- THEN the flow runs with the partner's request as its input
- @e2e exclude needs a configured flow and an inbound call; covered by vitest on the form and PHPUnit on EndpointService processFlowRule
