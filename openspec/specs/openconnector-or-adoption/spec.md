# openconnector-or-adoption Specification

## Purpose
Defines how openconnector adopts OpenRegister abstractions per the OR-abstraction
audit (Tier 2): renaming the connector-specific `ObjectService` to
`SourceMappingService`, replacing hardcoded schema-property GUIDs with slug-based
lookup, declaring log retention/lifecycle/notification via OR annotations rather
than triplicated PHP constants, moving tenant-tunable values to admin-config, and
fencing the genuinely app-local mapping/rule engine and Pinia stores from future
duplication audits. Aligns with ADR-001, ADR-022, ADR-031. Status: implemented.
## Requirements
### Requirement: Connector-specific service is renamed to SourceMappingService

`lib/Service/ObjectService.php` SHALL be renamed to
`lib/Service/SourceMappingService.php`. A deprecated PHP class alias
`OCA\OpenConnector\Service\ObjectService` SHALL extend the new class for one minor version
to preserve external compatibility.

#### Scenario: Class rename is the canonical name

- **GIVEN** the rename is applied
- **WHEN** a developer searches `lib/` for `class ObjectService`
- **THEN** they SHALL find only the deprecated alias (single line) and the new
  `SourceMappingService` definition.

#### Scenario: Deprecated alias triggers E_USER_DEPRECATED

- **GIVEN** an external app instantiates `OCA\OpenConnector\Service\ObjectService`
- **WHEN** the constructor runs
- **THEN** a `E_USER_DEPRECATED` notice SHALL fire pointing to `SourceMappingService`.

### Requirement: Schema property references use slug-based lookup

The eight `PROP_*` constants in `lib/Service/RuleService.php:125-131` SHALL be replaced
with `RegisterResolverService::resolveProperty($schemaSlug, $propertySlug)` calls. Raw
schema-property GUIDs SHALL NOT appear as PHP constants anywhere in `lib/`.

#### Scenario: Slug-based lookup survives schema rebuild

- **GIVEN** the rule engine is configured against a schema slug (e.g. `synchronization-rule`)
- **WHEN** that schema is rebuilt and gets new property GUIDs
- **THEN** the rule engine SHALL continue to function without code changes
- **AND** no `id-…` GUID literal SHALL exist in `lib/Service/RuleService.php`.

### Requirement: Log retention is declared once via archival annotation

Log retention SHALL be declared exactly once via an archival annotation. The
triplicated retention constants `DEFAULT_SUCCESS_LOG_RETENTION = 3600000` and
`DEFAULT_ERROR_LOG_RETENTION = 2592000000` in `JobService.php:60-61`,
`CallService.php:66-67`, and `SynchronizationService.php:83-84` SHALL be removed. The
log schema SHALL declare `x-openregister-archival.retention` (`PT1H` for success rows,
`P30D` for error rows). Conversion from milliseconds to ISO-8601 SHALL preserve current
duration.

#### Scenario: Single archival declaration replaces three constants

- **GIVEN** the log schema declares `x-openregister-archival`
- **WHEN** OR's archival job runs
- **THEN** success-log rows older than 1 hour SHALL be eligible for archival
- **AND** error-log rows older than 30 days SHALL be eligible for archival
- **AND** no `DEFAULT_*_LOG_RETENTION` constants SHALL exist in `lib/Service/`.

### Requirement: Lifecycle annotation backs status state changes

Status state changes SHALL be backed by a lifecycle annotation. Inline `'status'`
writes in `lib/Controller/DSOController.php:115` and
`lib/Service/EventService.php:170,256` SHALL be replaced with lifecycle transition API
calls. The dso-message and event schemas SHALL declare `x-openregister-lifecycle`. The
on-wire status value SHALL remain identical to the current value.

#### Scenario: DSO message ontvangen state via lifecycle

- **GIVEN** the dso-message schema declares lifecycle states including `ontvangen`
- **WHEN** `DSOController::receiveMessage()` would have written `'status' => 'ontvangen'`
- **THEN** the controller SHALL invoke
  `lifecycleService->transitionTo($msg, 'ontvangen')` instead
- **AND** the response payload SHALL still contain `"status": "ontvangen"`.

#### Scenario: Event pending state via lifecycle

- **GIVEN** the event schema declares lifecycle states including `pending`
- **WHEN** `EventService` reaches a previously-inline `'status' => 'pending'` write
- **THEN** the service SHALL invoke `lifecycleService->transitionTo($event, 'pending')`.

### Requirement: Sync-contract status filter values reflect lifecycle

Sync-contract status filter values SHALL reflect the lifecycle. The
`'status' => 'active'|'inactive'|'error'` values exposed by
`SynchronizationContractsController:366-368` SHALL be backed by the contract schema's
`x-openregister-lifecycle` annotation. Filter queries SHALL read the values from the
lifecycle's state list rather than a controller-side whitelist.

#### Scenario: Active/inactive/error are lifecycle states

- **GIVEN** the contract schema declares lifecycle states `active`, `inactive`, `error`
- **WHEN** `SynchronizationContractsController` returns the filter whitelist
- **THEN** the whitelist SHALL be derived from the schema's lifecycle states
- **AND** no hardcoded `'active'|'inactive'|'error'` literals SHALL exist in the
  controller.

### Requirement: Sync log-level filter values are documented as filter-only

Sync log-level filter values SHALL be documented as filter-only. The
`'status' => 'success'|'warning'|'info'|'debug'` values exposed by
`SynchronizationsController:510-514` are LOG-LEVEL filters, NOT lifecycle states. They
SHALL remain as a filter whitelist (declared as a JSON-schema `enum` on the log schema).
They SHALL NOT be migrated to a lifecycle annotation.

#### Scenario: Log levels stay as enum, not lifecycle

- **GIVEN** the log schema declares
  `level: { enum: [success, warning, info, debug] }`
- **WHEN** an auditor inspects the log schema
- **THEN** there SHALL be no `x-openregister-lifecycle` annotation referencing log levels
- **AND** the filter whitelist SHALL match the enum.

### Requirement: Notification annotation backs sync alerts

Synchronization-failed, contract-broken, and job-failed notifications SHALL be declared as
`x-openregister-notifications` triggers keyed on lifecycle transitions. Direct
`notificationManager->notify()` calls in `lib/Service/` SHALL be removed.

#### Scenario: Sync failure notification is annotation-driven

- **GIVEN** the synchronization schema declares
  `x-openregister-notifications` keyed on `running → error`
- **WHEN** `SynchronizationService` transitions a run to `error`
- **THEN** the notification SHALL fire automatically
- **AND** no direct `notificationManager->notify()` call SHALL exist for this event.

### Requirement: Tenant-tunable values move to admin-config

Hardcoded constants flagged in `.claude/audit-2026-05-03/04-hardcoded.md` SHALL move to
admin-config. Default values SHALL preserve current behavior.

#### Scenario: Endpoint cache TTL is admin-config

- **GIVEN** an admin sets `openconnector.endpoint_cache.ttl_seconds = 7200`
- **WHEN** `EndpointCacheService` reads its TTL
- **THEN** the TTL SHALL equal 7200
- **AND** the constant `CACHE_TTL` SHALL no longer exist in
  `lib/Service/EndpointCacheService.php`.

#### Scenario: Software-catalogue suffix is admin-config

- **GIVEN** an admin sets `openconnector.software_catalogue.suffix = '-sc-test'`
- **WHEN** `SoftwareCatalogueService` constructs an external slug
- **THEN** the suffix SHALL be `-sc-test`
- **AND** the constant `SUFFIX` SHALL no longer exist in
  `lib/Service/SoftwareCatalogueService.php`.

### Requirement: Domain-specific Pinia stores stay app-local

Domain-specific Pinia stores SHALL stay app-local. The 20+ Pinia store modules in
`src/store/modules/` (source, mapping, endpoint, contract,
webhooks, rule, etc.) are intentionally domain-specific and SHALL NOT be migrated to a
generic `createObjectStore` pattern. They SHALL consume `multi-tenancy-context` from
nc-vue for tenant scope.

#### Scenario: Stores read tenant from shared composable

- **GIVEN** the nc-vue `multi-tenancy-context` composable is available
- **WHEN** any of the 20+ openconnector stores reads tenant scope
- **THEN** it SHALL read from `useTenantContext()` rather than computing locally.

#### Scenario: Stores are not flagged as duplication in future audits

- **GIVEN** this Requirement exists in the capability spec
- **WHEN** a future OR-abstraction audit reviews openconnector
- **THEN** the auditor SHALL cite this Requirement and SKIP a re-investigation of the
  20+ Pinia stores as duplication.

### Requirement: Mapping/rule engine stays app-local

The mapping/rule engine SHALL stay app-local. The mapping engine and rule rewrite engine in
`lib/Service/MappingService.php` and `lib/Service/RuleService.php` are by-design
schema-to-schema transforms specific to openconnector. They SHALL remain app-local. They
SHALL NOT be migrated to OR.

#### Scenario: Mapping engine not flagged as duplication

- **GIVEN** this Requirement exists in the capability spec
- **WHEN** a future audit reviews the mapping/rule engine
- **THEN** the auditor SHALL cite this Requirement and SKIP a migration recommendation.

### Requirement: openconnector declares its manifest

openconnector SHALL ship `openspec/manifest.yaml` declaring `tier: 2`,
`dependencies: ["openregister"]`, the consumed shared specs, and the minimum OR version.

#### Scenario: Manifest declares all consumed specs

- **GIVEN** `openspec/manifest.yaml` lists `consumes`
- **WHEN** Hydra coordination loads the manifest
- **THEN** the consumed list SHALL include `register-resolver-service`,
  `pluggable-integration-registry`, `i18n-source-of-truth`,
  `i18n-api-language-negotiation`, `multi-tenancy-context`.

### Requirement: openconnector consumes shared multi-tenancy + i18n specs

openconnector SHALL consume `multi-tenancy-context`, `i18n-source-of-truth`, and
`i18n-api-language-negotiation`. Tenant scoping and translation infrastructure SHALL NOT
be re-implemented.

#### Scenario: API respects Accept-Language

- **GIVEN** a client sends `Accept-Language: nl-NL` to openconnector
- **WHEN** the response includes a translatable label or description
- **THEN** the field SHALL return the Dutch translation per OR's negotiation spec.

