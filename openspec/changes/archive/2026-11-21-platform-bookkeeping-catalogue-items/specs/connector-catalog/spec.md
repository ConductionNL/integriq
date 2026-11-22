# connector-catalog Specification

## ADDED Requirements

### Requirement: Bookkeeping connectors are catalogue templates in bookkeeping categories (REQ-CAT-BK-001)

Integriq MUST list a dormant source template in its catalogue for the Peppol access point, the PSD2 bank aggregator, the corporate card feed and a Mollie payment account. Every bookkeeping connector template MUST carry one of the categories `Bank`, `Payments`, `E-invoicing`, `Tax filing`, `Commerce` or `Payroll`. A template MUST hold its credential by broker reference only.

#### Scenario: a bookkeeper looks for a bank feed
- GIVEN integriq upgraded with this change and shillinq's integrations page filtering on the bookkeeping categories
- WHEN the bookkeeper opens that page
- THEN cards for the PSD2 bank aggregator and the corporate card feed show under `Bank`, the Mollie template under `Payments` and the Peppol access point under `E-invoicing`, each dormant
- e2e: `tests/e2e/catalog-bookkeeping-templates.spec.ts`

#### Scenario: a new bookkeeping template has no bookkeeping category
- GIVEN a developer adds a bookkeeping template whose slug has no category override
- WHEN the unit tests run
- THEN the catalogue category test fails and names the slug
- @e2e exclude a unit test guard; covered by PHPUnit
