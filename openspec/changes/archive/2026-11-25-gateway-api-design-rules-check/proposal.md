---
kind: code
depends_on: [gateway-openapi-import-and-publish]
---

# Proposal: gateway-api-design-rules-check

## Summary

A municipality publishing an API is expected to follow the Dutch API design rules (the API Design Rules of the Kennisplatform API's, maintained by Logius), and nothing in integriq checks that before a product goes live. This change checks an API product's OpenAPI description against rule sets, the Dutch API design rules first and an organisation's own rules next to it, shows the findings on the product page, and holds back publication while a rule marked as an error fails.

## Why

Two rows of integriq's capability matrix decided in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `integriq:acc-governance` | no | build: two competitors yes |
| `integriq:nl-api-design-rules` | no | build, riding with `acc-governance`: its whole missing half is the Dutch rule set in the same checker |

Competitor cells, quoted from the matrix:

- `acc-governance`: MuleSoft https://docs.mulesoft.com/api-governance/create-profiles.md "the set of APIs that meet the filter criteria in the profile are validated against the set of rulesets selected in the profile". WSO2 v4.7.0 `governance-api.yaml:44` `/rulesets` with policies that block a lifecycle step.
- `nl-api-design-rules`: no competitor rates yes. MuleSoft (partial) https://docs.mulesoft.com/api-governance/create-custom-rulesets.md "If you need a ruleset other than those provided, you can create your own custom ruleset". WSO2 (partial) the same governance API. Neither ships the Dutch rules; integriq would be the only one.

## What integriq already has

- Nothing that checks an API design. The matrix: a search for "design rule", "spectral", "NL API Design Rules" and "API-strategie" across `lib/`, `src/` and `openspec/specs` returns nothing.
- `gateway-openapi-import-and-publish` produces an OpenAPI 3.1 document per API product. This change reads that document.
- API products carry a `status` (`lib/Settings/register.d/api-product-gateway.json`).

## What this change builds

1. A rule engine in PHP: a rule is a selector over the OpenAPI document plus a check, with a severity (error, warning, info) and a link to the rule's text.
2. The Dutch API design rules as the first built-in rule set: the automatable core rules, each linked to its rule id at Logius.
3. Custom rule sets an administrator adds, in the same format.
4. Findings on the product page, and a publish guard: a product cannot move to `active` while an error-level finding is open, unless an administrator waives that finding with a reason.

## Out of scope

- Running the Node-based Spectral linter. The rule format is Spectral-like so a rule set can be ported by hand, but no Node runtime is added.
- Checking live traffic against the rules. Only the published description is checked.
