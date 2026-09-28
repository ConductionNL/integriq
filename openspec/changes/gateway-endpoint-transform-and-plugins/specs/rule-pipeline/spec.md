# rule-pipeline delta

## ADDED Requirements

### Requirement: A custom rule runs a registered plug-in (REQ-GTP-002)

A rule of type `custom` MUST run the plug-in registered under the id in its `configuration.plugin`. A sibling app MUST be able to register a plug-in without changing integriq. An id no plug-in answers to MUST be refused with a message naming the id.

#### Scenario: a sibling app adds its own step to an endpoint
- GIVEN a sibling app that registered the rule plug-in `bsn-mask`
- WHEN a request passes an endpoint with a custom rule naming `bsn-mask`
- THEN the plug-in's output is what the next rule and the consumer receive
- @e2e exclude extension contract for sibling apps; covered by PHPUnit

### Requirement: A JavaScript rule is refused (REQ-GTP-003)

Integriq MUST NOT offer a JavaScript rule in the rule editor, MUST refuse to store a rule of type `javascript`, and MUST fail a `javascript` rule that still exists when it runs, with a message that integriq runs no scripts.

#### Scenario: an administrator looks for a script rule
- GIVEN the rule editor
- WHEN an administrator opens the rule type list
- THEN JavaScript is not in it
- @e2e exclude editor option list; covered by vitest on ruleDraft ACTION_TYPES
