## ADDED Requirements

### Requirement: Data-infrastructure adapters MUST share a common registration base (REQ-DIC-002)

Every adapter under `lib/Service/Adapter/DataInfra/` MUST extend the shared
`AbstractCategoryAdapterProvider` base (`lib/Service/Adapter/AbstractCategoryAdapterProvider.php`)
rather than implementing the `IntegrationProvider` contract from scratch, so
capability-vocabulary declaration, health-check surfacing, and credential
resolution are consistent across every category adapter (endpoint/workspace,
document/CMS, SaaS, and data-infra).

#### Scenario: Reference S3 adapter proves the registration pattern

- **GIVEN** `lib/Service/Adapter/DataInfra/S3Adapter.php` extends
  `AbstractCategoryAdapterProvider` and is registered as an `IntegrationProvider`
  in `lib/AppInfo/Application.php`
- **WHEN** a sibling app resolves the `data-infra-s3` integration slot slug via
  OR's integration registry
- **THEN** it receives a working read/write/list adapter without importing any
  integriq PHP class directly, and without hardcoding S3 credentials —
  credentials resolve through the shared OR credential broker

#### Scenario: Adapter credential resolution never hardcodes secrets

- **GIVEN** any adapter extending `AbstractCategoryAdapterProvider`
- **WHEN** the adapter needs a credential (API key, access key, OAuth token)
  to reach its external system
- **THEN** it MUST resolve that credential through the OR credential-broker
  contract, and MUST NOT read the credential from a hardcoded value, a
  plaintext config file, or an app-config key outside the broker's vault
