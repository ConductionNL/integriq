# Retrofit — organisation-bridge

Describes observed behavior of 6 methods under `organisation-bridge` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/OrganisationBridgeService.php::getOrganisationService()`
- `lib/Service/OrganisationBridgeService.php::isOrganisationServiceAvailable()`
- `lib/Service/OrganisationBridgeService.php::getUserOrganisationStats()`
- `lib/Service/OrganisationBridgeService.php::setActiveOrganisation()`
- `lib/Service/OrganisationBridgeService.php::getActiveOrganisation()`
- `lib/Service/OrganisationBridgeService.php::getUserOrganisations()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section + design.md surface the unsafe-auth-resolver `catch → return null` pattern that triggers the `hydra-gate-unsafe-auth-resolver` gate — this is an authorization-side surface (OWASP A01:2021 / CWE-863) and is documented as observed-but-suspicious, not fixed in this change

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
