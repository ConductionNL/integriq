# Spec: data-infra-connectors

**Status:** proposed
**Scope:** openconnector
**Tier:** connector-categories
**Depends on:** prometheus-metrics (openconnector, for connector-level operational metrics), hydra ADR-019 (integration-registry), hydra ADR-022 (apps consume OR abstractions), hydra ADR-024 (app manifest), hydra ADR-031 (schema-declarative business logic)

## ADDED Requirements

### REQ-DIC-001: Data-infrastructure connector adapters SHALL register through the integration registry per ADR-019, not as bespoke per-app HTTP clients

Every adapter for an infrastructure or domain data source —
relational databases (PostgreSQL, MySQL, MariaDB, Oracle, MSSQL),
NoSQL stores (MongoDB, Redis, Cassandra, Elasticsearch,
InfluxDB), data warehouses (Snowflake, BigQuery, Redshift,
Synapse), event streams (Kafka, RabbitMQ, NATS, Pulsar,
AWS SQS, Azure Service Bus), file/object systems (S3, GCS,
Azure Blob, SFTP, FTPS, WebDAV, MinIO) — MUST be implemented
as an `IntegrationProvider` registered through OR's integration
registry per ADR-019 and surfaced to consuming apps by a stable
slot slug. Adapter classes MUST live under
`lib/Service/Adapter/DataInfra/` and MUST NOT be embedded in any
sibling app (decidesk, opencatalogi, mydash, etc.). Per ADR-022,
sibling apps consume these adapters by slot slug, not by
importing openconnector PHP.

#### Scenario: Reviewer confirms no per-app data-infra HTTP client

- **GIVEN** any sibling app's `lib/Service/` tree
- **WHEN** scanned for direct imports of `MongoDB\Client`,
  `Predis\Client`, `Aws\S3\S3Client`, `Google\Cloud\BigQuery\BigQueryClient`,
  `Snowflake\\*`, `RdKafka\Producer`, `PhpAmqpLib\\*`, or
  any other infrastructure client library
- **THEN** no such imports SHALL exist in sibling apps; the
  capability MUST be consumed from openconnector by integration
  slot slug.

#### Scenario: Adapter registers via DI tag, not via runtime hack

- **GIVEN** a newly added adapter (e.g. `SnowflakeAdapter`)
- **WHEN** the container is built
- **THEN** the class MUST be tagged with `IntegrationProvider`
  in `lib/AppInfo/Application.php` and its registry record MUST
  include `id`, `category: data-infra`, `subCategory`
  (`rdbms` / `nosql` / `warehouse` / `stream` / `objectstore`),
  `label`, `icon`, `authModes[]`, `capabilities[]`, and
  `rateLimits`.

### REQ-DIC-002: Each registered adapter SHALL declare its connector manifest entry per ADR-024 with a fixed minimum field set

Openconnector MUST publish a `connectors[]` block in
`src/manifest.json` per ADR-024. Each entry MUST carry the
following manifest shape so consuming-app authors can discover
the adapter without reading PHP:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `id` | string (kebab-case) | Yes | Stable slot slug — sibling apps reference this by literal value |
| `category` | enum | Yes | Fixed `data-infra` for this spec |
| `subCategory` | enum | Yes | One of `rdbms`, `nosql`, `warehouse`, `stream`, `objectstore` |
| `adapterClass` | string (FQCN) | Yes | PHP class implementing `IntegrationProvider` |
| `label` | string (i18n key) | Yes | Display name; per ADR-024 §6 a translation key, not a literal |
| `icon` | string | Yes | MDI glyph or asset path |
| `authModes` | string[] | Yes | One or more of `apiKey`, `basicAuth`, `bearerToken`, `serviceAccountJwt`, `oauth2`, `tls-client-cert`, `aws-sigv4`, `gcp-adc`, `azure-msi`, `none` (last only for read-only public sources) |
| `capabilities` | string[] | Yes | One or more of `read`, `write`, `schema-discover`, `subscribe-cdc`, `subscribe-events`, `bulk-export`, `bulk-import`, `query` |
| `rateLimits` | object | Yes | `{requestsPerSecond, burst, dailyQuota}` shape; integer or `null` for `unlimited` |
| `pollingMode` | enum | Yes | One of `pull`, `push`, `hybrid` |
| `schemaDiscovery` | enum | Yes | One of `introspection`, `manifest`, `none` |
| `documentationUrl` | string (URL) | Yes | Link to the upstream vendor docs the adapter targets |

Per ADR-031, the manifest entry IS the contract — there MUST
NOT be a duplicate PHP constant repeating the same shape. The
manifest validator MUST reject entries missing any required
field.

#### Scenario: Reviewer confirms manifest entry is the single source of truth

- **GIVEN** an adapter `SnowflakeAdapter`
- **WHEN** `lib/Service/Adapter/DataInfra/SnowflakeAdapter.php` is
  inspected
- **THEN** no `const CAPABILITIES = [...]` / `const AUTH_MODES = [...]`
  / `const RATE_LIMITS = [...]` class constant SHALL exist;
  every such value MUST come from the manifest entry at runtime.

#### Scenario: `npm run check:manifest` rejects an entry missing required fields

- **GIVEN** a `connectors[]` entry omitting `authModes`
- **WHEN** `npm run check:manifest` runs
- **THEN** it MUST exit non-zero, naming the offending entry's
  `id` and the missing field.

### REQ-DIC-003: Adapter credentials SHALL live in openconnector `Source` records — never on consuming-app records

Per ADR-019 §"External integrations route through OpenConnector",
all adapter credentials (API keys, OAuth tokens, service-account
JWTs, TLS client certs, AWS/GCP/Azure cloud identities) MUST be
stored in openconnector's existing `Source` registry. Consuming
apps reference the source by slug only and MUST NOT mirror the
credential anywhere — not in `IAppConfig`, not in an OR object,
not in an environment variable owned by the sibling app.

The adapter MUST resolve credentials by calling
`SourceService::getCredentials(string $sourceSlug)` at runtime;
credentials MUST NOT be passed in via constructor injection,
PHP `__construct` parameters, or DI configuration. This keeps
credentials in one place (openconnector) and lets operators
rotate them without redeploying any sibling app.

#### Scenario: Reviewer confirms no consuming-app credential mirror

- **GIVEN** any sibling app's `lib/` tree
- **WHEN** scanned for field/property names matching
  `*ApiKey*` / `*Secret*` / `*Token*` / `*ServiceAccountJson*`
  in contexts that reference a data-infra integration
- **THEN** no such fields SHALL exist; the sibling MUST hold only
  the openconnector source slug.

### REQ-DIC-004: Each adapter SHALL declare its polling-vs-push posture and its schema-discovery contract

Adapter `pollingMode` MUST be one of:

- `pull` — the adapter is invoked by a sibling app or by an OR
  `ScheduledWorkflow` and returns data on demand. The adapter
  MUST NOT open long-lived background sockets.
- `push` — the adapter exposes a webhook endpoint via
  openconnector's existing webhook layer; the upstream system
  POSTs change events; the adapter normalises them into
  CloudEvents per ADR-022.
- `hybrid` — both modes supported; the manifest entry MUST
  document which capabilities ride each mode.

Adapter `schemaDiscovery` MUST be one of:

- `introspection` — the adapter MUST expose
  `discoverSchemas(): array` returning a list of `{name, fields[]}`
  records the consuming app can materialise as OR `Schema`
  objects via the existing `Mapping` abstraction (per ADR-022).
- `manifest` — schemas are pinned in the adapter's manifest
  entry (e.g. fixed Kafka topic schemas).
- `none` — no schema concept (e.g. an SFTP file-list adapter).

#### Scenario: A `pull` adapter does not open a background socket

- **GIVEN** a `pull`-mode adapter
- **WHEN** the openconnector container starts
- **THEN** no persistent connection to the upstream system
  SHALL be opened until a sibling app explicitly invokes
  the adapter; the adapter's idle resource footprint MUST be
  zero.

#### Scenario: A `subscribe-cdc` adapter exposes a webhook surface

- **GIVEN** a Kafka adapter declaring
  `pollingMode: hybrid`, `capabilities: [read, subscribe-cdc]`
- **WHEN** a sibling app subscribes via the registry
- **THEN** openconnector MUST register a webhook receiver
  endpoint, normalise inbound payloads to CloudEvents per
  ADR-022, and dispatch to the sibling via the standard
  CloudEvent dispatcher — no per-app Kafka consumer.

### REQ-DIC-005: Scheduled pulls SHALL run as OpenRegister `ScheduledWorkflow` records, not as openconnector `TimedJob` classes

Per ADR-031 §"Background jobs" path 2, every periodic pull
configured by an operator MUST be a `ScheduledWorkflow` record
referencing the adapter slug. Openconnector MUST NOT author a
`BackgroundJob` / `TimedJob` PHP class for any scheduled
data-infra pull. The workflow MUST call the adapter by slug
through the standard `IntegrationProvider::invoke()` contract
and persist results either into an OR object (when the consuming
app has declared a target schema) or into the existing
openconnector `Synchronization` row (when the operator is using
the legacy sync UI).

#### Scenario: Reviewer confirms no `TimedJob` for scheduled adapter pulls

- **GIVEN** the openconnector codebase
- **WHEN** scanned for classes extending `OCP\BackgroundJob\TimedJob`
  in `lib/BackgroundJob/` whose name matches
  `*Adapter*` / `*Connector*` / `*Pull*` / `*Sync*Schedul*`
- **THEN** no such classes SHALL exist; periodic adapter pulls
  MUST be driven by `ScheduledWorkflow` records.

### REQ-DIC-006: Adapter operational health SHALL surface through the existing prometheus-metrics endpoint

Per the existing `prometheus-metrics` spec (REQ-PROM-001 ..
REQ-PROM-004), the metrics endpoint already exposes counters
on sources by type. This spec extends that contract: every
data-infra adapter invocation MUST increment
`openconnector_adapter_invocations_total{category="data-infra",
sub_category=<sub>, adapter_id=<id>, outcome=<success|failure|throttled>}`
and observe latency as
`openconnector_adapter_latency_seconds{category="data-infra",
adapter_id=<id>}`. No new endpoint, no separate metrics surface
— the existing `/api/metrics` is extended additively.

#### Scenario: A successful Snowflake query increments the right counter

- **GIVEN** a configured Snowflake adapter
- **WHEN** a sibling app invokes `read` and the call succeeds
- **THEN** `/api/metrics` MUST report
  `openconnector_adapter_invocations_total{category="data-infra",
  sub_category="warehouse",adapter_id="snowflake",outcome="success"}`
  incremented by exactly 1.

### REQ-DIC-007: Individual per-adapter implementations are explicitly out of scope for this spec — each adapter ships in its own `add-openconnector-{slug}-adapter` change

This spec defines the **category-level registration contract**.
Individual adapter implementations (e.g. Snowflake, BigQuery,
S3, Kafka) MUST land as separate openspec changes named
`add-openconnector-{slug}-adapter`, each consuming this spec
by REQ reference. Per-adapter changes MUST cite REQ-DIC-001
through REQ-DIC-006 in their proposal and MUST NOT re-derive the
category-level contract.

This mirrors the existing openconnector convention
(`add-pdok-adapter`, `stuf-adapter`, `dso-omgevingsloket`,
`ibabs-notubiz-connector`) — each per-system change owns its
adapter slice; the category spec owns the shared contract.

#### Scenario: A new per-adapter change references this spec

- **GIVEN** a new change folder
  `openspec/changes/add-openconnector-snowflake-adapter/`
- **WHEN** its proposal.md is inspected
- **THEN** the `Depends on` line MUST include
  `data-infra-connectors (openconnector)`; the proposal MUST
  cite REQ-DIC-001 (registration) and REQ-DIC-002 (manifest
  entry) by REQ id; the proposal MUST NOT redefine the
  category-level shape.
