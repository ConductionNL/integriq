# Design: gateway-api-design-rules-check

Kind: code. Size M. A new `ApiDesignRuleService`, one new schema for rule sets, one for findings, and a findings panel on `ApiProductDetail`.

## Context at development 92f282bc

- API products: `lib/Settings/register.d/api-product-gateway.json`, `api_product.status` `active` or `deprecated`; pages `ApiProducts` and `ApiProductDetail` (`src/manifest.json:1934-1979`).
- The OpenAPI document of a product comes from `OpenApiPublishService` in `gateway-openapi-import-and-publish`.
- No linter or rule engine exists in `lib/` (matrix row `acc-governance`).

## D1. Rules are data, the engine is small

A rule: `id`, `title`, `severity`, `given` (a JSONPath selector into the document), `then` (one of a fixed list of checks: `truthy`, `falsy`, `pattern`, `enum`, `casing`, `schema`, `length`), and `url` pointing at the rule text. This is the shape of Spectral's rule format, cut to the checks the Dutch core rules need. A JSONPath library already in the PHP ecosystem (for example `softcreatr/jsonpath`) evaluates selectors; the design picks one after a licence check under ADR-014.

Rejected: shelling out to Spectral. It needs Node on the Nextcloud host, which a municipality's Nextcloud does not have.

## D2. The Dutch rule set

Ship `lib/Settings/api-design-rules/nl-api-design-rules.json` with the rules that can be checked on a document alone. Each carries the Logius rule id in its `id` (for example `/core/no-trailing-slash`, `/core/http-methods`, `/core/doc-openapi`, `/core/uri-version`, `/core/semver`, `/core/version-header`, `/core/naming-collections`) and its `url` into https://gitdocumentatie.logius.nl/publicatie/api/adr/. The exact list and text are taken from the published version at build time and the version is recorded on the rule set; the task's test compares ids with that version.

Rules that need traffic or organisation facts (for example whether the API is registered in the API register) are listed in the rule set as `manual` with no check, so the findings say what still needs a person.

## D3. Rule sets and findings are objects

- `api_design_ruleset`: `name`, `version`, `source` (built-in or custom), `rules` (array), `enabled`.
- `api_design_finding`: `product`, `productVersion`, `ruleset`, `ruleId`, `severity`, `path` (JSON pointer into the document), `message`, `status` open or waived, `waivedBy`, `waiveReason`, `checkedAt`.

A check runs when an administrator presses check on the product, and before a status change to `active`. Each run replaces the open findings of that product version and keeps waived ones whose rule and path still match.

## D4. The publish guard

Moving a product to `active` runs the check. If an open error-level finding exists, the change is refused with the list of findings. An administrator may waive a finding with a reason; the waiver is recorded on the finding and shown on the product page. Warnings never block.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| finding counts per product | declarative: `x-openregister-aggregations` on `api_product` over `api_design_finding` by severity | a count is a derived value |
| the publish guard | imperative: a lifecycle guard in the product status change | ADR-031 lists a lifecycle guard as an allowed exception |

## Seed data

- The built-in rule set `nl-api-design-rules`, enabled.
- A custom rule set `gemeente-voorbeeld` with one rule: every operation has a `summary` in Dutch (warning).
- For the seeded product `besluiten-api`, two findings: one error (`/core/no-trailing-slash`) and one waived warning with a reason.

## Risks

- The Logius rules change. Mitigation: the rule set records the version it follows, and a newer version ships as a new built-in rule set rather than editing the old one.
- A false positive blocks a release. Mitigation: the waiver with reason.
