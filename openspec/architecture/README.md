# OpenConnector — Architectural Decision Records (repo-local)

These ADRs capture decisions specific to OpenConnector. For company-wide
decisions that apply to every Conduction app, see
[hydra/openspec/architecture/](../../../hydra/openspec/architecture/).

## Status legend

- **Accepted** — decision is live in the codebase and should be followed
- **Proposed** — newly identified pattern, or specifies a planned (not-yet-implemented) class; awaits human ratification
- **Partially superseded** — still applies in parts; one or more requirements have been replaced by a later ADR or change
- **Superseded** — fully replaced by a later ADR

## Active ADRs

| ADR | Title | Status | One-line summary |
|-----|-------|--------|-----------------|
| [ADR-001](adr-001-domain-pinia-stores-app-local.md) | Domain-specific Pinia stores stay app-local | Accepted | 16+ per-resource Pinia stores are intentionally NOT replaced by `createObjectStore`; each encodes connector-domain UX (test mode, logs, retry). |
| [ADR-002](adr-002-mapping-rule-engine-stays-app-local.md) | Mapping and Rule engine stays app-local | Accepted | Twig mapping and endpoint Rule processing stay in openconnector; only the execution kernel delegates to OR's MappingService. |
| [ADR-003](adr-003-calllog-primary-observability-surface.md) | CallLog is the primary observability surface for outbound HTTP | Accepted | Every outbound HTTP call MUST go through `CallService` and produce a write-once `CallLog` row. |
| [ADR-004](adr-004-retention-constants-migrating-to-or-archival.md) | Log retention lives in PHP constants today; migrating to OR archival annotations | Accepted | Existing `DEFAULT_*_RETENTION` constants are kept until the storage migration lands; new retention rules go on the schema declaration. |
| [ADR-005](adr-005-source-synchronization-contract-triad.md) | Source / Synchronization / SynchronizationContract is the core data triad | Accepted | Every sync integration must use the Source→Synchronization→SynchronizationContract chain; per-object hash comparison is the change-detection primitive. |
| [ADR-006](adr-006-src-controller-mapper-are-orphaned-php-snippets.md) | `src/Controller/` and `src/Mapper/` are orphaned PHP snippets, not a frontend layer | Accepted | The `.php` files in `src/Controller/` and `src/Mapper/` are dead code; the real HTTP-adapter layer is the Pinia store modules. |
| [ADR-007](adr-007-source-credentials-stored-plaintext-pending-encryption.md) | Source credential fields are stored as plaintext today; EncryptionService layer is planned but not yet wired | Accepted (existing state) | `secret`, `password`, `apikey`, `jwt`, `jwtId`, `username` on `Source` are persisted verbatim; an `EncryptionService` layer is documented in specs but not yet present in code. |
| [ADR-008](adr-008-endpoint-polymorphic-targettype-targetid.md) | Endpoint (and Synchronization) resolve their target via a polymorphic `targetType` / `targetId` pair | Accepted | `targetType` (`register/schema`, `api`, `job`, `synchronization`) + `targetId` is the dispatch key at request time; `targetId` is cross-table-polymorphic and MUST NOT be a typed FK. |
| [ADR-009](adr-009-multi-platform-db-mysql-postgres-via-nc-abstraction.md) | All DB queries target MySQL and PostgreSQL via Nextcloud's QueryBuilder, with known MySQL-only leaks | Accepted | All new DB code uses `IQueryBuilder`/`QBMapper`; known MySQL-only leaks (`DATE_ADD`, `SHOW COLUMNS`, backtick quoting) in `SettingsService` must be fixed before Postgres is supported. |
| [ADR-010](adr-010-per-resource-ui-triad-index-modal-sidebar.md) | Every resource has a per-resource UI triad — index view, modals, and a detail sidebar | Partially superseded by D2 | Each domain resource ships an `{Resource}sIndex.vue`, per-action modals wired through `Modals.vue`, and a detail sidebar; this pattern is replaced by `CnIndexPage` in chain D2. |
| [ADR-011](adr-011-flow-token-request-response-mutation-context.md) | FlowToken is the request/response mutation context for endpoint and synchronization processing | Accepted | All rule-driven request/response modifications in the endpoint and sync pipelines MUST go through the `FlowToken` container, not raw array parameters. |
| [ADR-012](adr-012-strangler-fig-migration-pattern.md) | Strangler-fig migration pattern (B → C phased facade approach) | Accepted | Chain B introduces `ObjectMapperFacade` as a transitional shim; chain C cuts over to direct `ObjectService` use. Each phase is independently shippable + reversible. |
| [ADR-013](adr-013-event-bus-model.md) | Event-bus model — Event / EventSubscription / EventMessage / Consumer | Accepted | The 5 event-bus schemas are a separate surface from the Source/Sync/Contract triad; Consumer = subscriber identity, EventSubscription = filter, EventMessage = delivery record. |
| [ADR-014](adr-014-cron-job-execution-model.md) | Cron / job execution model (`JobService` + `JobTask` + `LogCleanUpTask`) | Accepted | Jobs are persisted as `Job` objects discovered by `\OCP\BackgroundJob\TimedJob` subclasses; each run emits a `JobLog`; retention sweeping is per-resource per ADR-004. |
| [ADR-015](adr-015-configuration-export-import-slug-translation.md) | Configuration export / import portability via slug translation | Accepted | Integer PKs in cross-entity references (`targetId`, `sourceId`, etc.) are translated to slugs on export and resolved back on import; chain B's uuid migration is in-environment identity, slugs are cross-environment identity. |
| [ADR-016](adr-016-encryption-service-design.md) | EncryptionService design (specification for the planned class) | Proposed | The not-yet-existing `EncryptionService` MUST be `final`, depend on `\OCP\Security\ICrypto`, apply column-level encryption at controller boundaries (not entity setters or storage layer), and ship with a one-shot OCC migration command. |
