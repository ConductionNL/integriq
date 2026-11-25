## MODIFIED Requirements

### Requirement: A product with an open error is not published (REQ-ADRC-003)

Integriq MUST refuse to make a product active while an error-level finding is open on what the product controls. A finding of a built-in rule marked as gateway-scoped (one that judges what the gateway writes into every product's description, such as the server URL, the contact block or the response headers) MUST be shown but MUST NOT refuse activation. An administrator MUST be able to waive a finding with a reason, and the waiver MUST be recorded and shown. Warnings MUST NOT block.

#### Scenario: a waiver lets a known exception through
- GIVEN a product with one open error-level finding
- WHEN the administrator tries to set it active, then waives the finding with the reason "partner requires this path until 2027"
- THEN the first attempt is refused listing the finding, and after the waiver the product becomes active and shows the waiver
- e2e: `tests/e2e/api-design-rules.spec.ts`

#### Scenario: a gateway-wide finding does not hold a product back
- GIVEN a product whose only open errors are on the gateway's server URL and contact block
- WHEN it is set active
- THEN it becomes active, and its page still lists those findings
- @e2e exclude covered by PHPUnit (`NlApiDesignRuleSetTest::testGatewayScopedFindingsDoNotBlock`); a product created active through OpenRegister's object API on a live instance is recorded in the PR
