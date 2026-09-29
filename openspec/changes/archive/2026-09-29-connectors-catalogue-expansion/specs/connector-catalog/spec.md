# connector-catalog Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connector-catalog-ui
- connectors-catalogue-expansion

## Purpose

The Store lists a template library it does not install, with checked
municipal back-office templates and generated SaaS templates, and counts
only real connectors. Rows `integriq:con-backoffice`,
`integriq:con-library-size` and `buildiq:int-saas-connectors`.

## ADDED Requirements

### Requirement: The Store lists templates it does not install (REQ-CCX-001)

The catalogue registry MUST list every template in
`lib/Settings/connector-templates/` as a Store card, and the register import
MUST NOT create a `source` object for any of them. Instantiating a template
from the Store MUST create one `source` from that template's payload.

#### Scenario: a fresh install has no template sources
- GIVEN a fresh install with the template library present
- WHEN an administrator opens the Sources page
- THEN no source from the library is listed, and the Store shows the library's cards
- e2e: `tests/e2e/connector-catalogue.spec.ts`

#### Scenario: an administrator instantiates a Salesforce template
- GIVEN the Salesforce card in the Store
- WHEN an administrator chooses Instantiate
- THEN one Salesforce source exists with the template's base URL and auth scheme, and no secret
- e2e: `tests/e2e/connector-catalogue.spec.ts`

### Requirement: A back-office template names its standard and where it was checked (REQ-CCX-002)

Every curated template MUST carry `vendor`, `system`, `standard` and
`verifiedAgainst`, and MUST configure an interface integriq already speaks.
Every system named in the Stein tender requirements MUST end with either a
curated template or a recorded reason in the back-office README.

#### Scenario: a buyer finds the GWS connector and its standard
- GIVEN a municipality evaluating integriq against the Stein requirements
- WHEN an administrator searches the Store for GWS
- THEN either a card names Centric GWS with the standard it is reached over, or the back-office README states why no template exists
- e2e: `tests/e2e/connector-catalogue.spec.ts`

#### Scenario: a template without a checked source is rejected
- GIVEN a curated template with no `verifiedAgainst`
- WHEN the library is validated
- THEN validation fails naming the file
- @e2e exclude a build-time validation; covered by `tests/validate-connector-templates.js`

### Requirement: Generated SaaS templates come from a pinned directory and a reviewed allow-list (REQ-CCX-003)

Generated templates MUST be produced from a committed snapshot of the
APIs.guru OpenAPI directory, only for entries on
`lib/Settings/connector-templates/saas/allow-list.json`. Each MUST carry
`tier: generated` and the snapshot date, MUST name the auth scheme and MUST
NOT carry a credential. The Store card MUST say the template is generated.

#### Scenario: Google Sheets, Salesforce and Slack are in the Store
- GIVEN the first allow-list
- WHEN an administrator opens the Store
- THEN Google Sheets and Slack are listed, each marked generated with its snapshot date, and Salesforce is listed as a checked template, because the directory's only Salesforce entry is Einstein Vision and Language, not the CRM API
- e2e: `tests/e2e/connector-catalogue.spec.ts`

#### Scenario: an entry off the allow-list never appears
- GIVEN an API in the snapshot that is not on the allow-list
- WHEN the generator runs
- THEN no template is written for it
- @e2e exclude a build-time script; covered by PHPUnit on the generator

### Requirement: The Store counts only real connectors, once each (REQ-CCX-004)

The Store MUST NOT list a source whose slug starts with `environment-`, and
MUST list a system that has both an adapter and a template once, as the
adapter. Every card MUST carry its tier, and the Store MUST offer a filter per
template tier, so the count per tier is the filtered count.

#### Scenario: placeholders are gone from the count
- GIVEN a fresh install
- WHEN an administrator opens the Store
- THEN no card names `environment-local-source` or `environment-acceptance-source`, and SmartDocuments and Xential appear once each
- e2e: `tests/e2e/connector-catalogue.spec.ts`
