# api-design-rules Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-api-design-rules-check

## Purpose

An API product's description is checked against the Dutch API design rules and the organisation's own rules before it is published. Rows `integriq:acc-governance` and `integriq:nl-api-design-rules`.

## ADDED Requirements

### Requirement: An API description is checked against rule sets (REQ-ADRC-001)

Integriq MUST check an API product's OpenAPI description against every enabled rule set when an administrator asks for it and before the product becomes active. Each failed rule MUST become a finding with its severity, the path in the document and a link to the rule's text. An administrator MUST be able to add a custom rule set in the same format.

#### Scenario: an administrator checks a product before publishing
- GIVEN an administrator on the product page of "Besluiten API"
- WHEN they press check
- THEN the findings are listed by severity, each with the path in the document and a link to the rule
- e2e: `tests/e2e/api-design-rules.spec.ts`

### Requirement: The Dutch API design rules are built in (REQ-ADRC-002)

Integriq MUST ship the automatable core rules of the Dutch API design rules as a built-in rule set, each with its Logius rule id, a link to its text and the version of the rules it follows. Rules that cannot be checked on a document MUST be listed as manual, so the findings show what still needs a person.

#### Scenario: a trailing slash is caught
- GIVEN a product whose description has the path `/besluiten/`
- WHEN it is checked
- THEN a finding for `/core/no-trailing-slash` points at that path and links to the rule at Logius
- @e2e exclude rule evaluation; covered by PHPUnit with fixture documents

### Requirement: A product with an open error is not published (REQ-ADRC-003)

Integriq MUST refuse to make a product active while an error-level finding is open. An administrator MUST be able to waive a finding with a reason, and the waiver MUST be recorded and shown. Warnings MUST NOT block.

#### Scenario: a waiver lets a known exception through
- GIVEN a product with one open error-level finding
- WHEN the administrator tries to set it active, then waives the finding with the reason "partner requires this path until 2027"
- THEN the first attempt is refused listing the finding, and after the waiver the product becomes active and shows the waiver
- e2e: `tests/e2e/api-design-rules.spec.ts`
