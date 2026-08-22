# openconnector-mcp-tool-surface Specification

## Purpose
TBD - created by archiving change openconnector-mcp-adoption. Update Purpose after archive.
## Requirements
### Requirement: REQ-MCP-101 — Integriq's MCP tool surface MUST be declared on the schema via the `x-openregister-mcp` dialect and MUST NOT be hand-written in PHP

Integriq MUST NOT ship an MCP tool provider, an `IMcpToolProvider` implementation, or any
hand-coded tool descriptor (ADR-063). The entire integriq tool surface MUST be **derived**
by OpenRegister from a per-schema `configuration["x-openregister-mcp"]` block, producing tool
ids of the form `integriq.{schema}.{verb}`. The declaration MUST live in
`lib/Settings/integriq_register.json` alongside the schema it describes. Exposure MUST
remain default-OFF: a schema without the dialect MUST produce no tools.

#### Scenario: the surface is schema-declared, not coded
- **WHEN** the change is complete
- **THEN** `lib/` contains no MCP provider, no tool descriptor, and no `#[McpTool]` attribute
- **AND** the only artifact changed is `lib/Settings/integriq_register.json`

#### Scenario: undeclared schemas produce no tools
- **GIVEN** a schema with no `x-openregister-mcp` block
- **WHEN** the MCP tool list is enumerated
- **THEN** no `integriq.{that-schema}.{verb}` tool exists

### Requirement: REQ-MCP-102 — Exactly 8 operational schemas MUST be exposed, read-only, and every declared `search` filter MUST be a real property of its schema

Exactly **8** of integriq's 27 schemas MUST carry the dialect: `endpoint`, `mapping`,
`synchronization`, `synchronization_contract`, `call_log`, `job`, `job_log`,
`synchronization_log`. Each MUST declare `enabled: true` and a `tools` map containing **only**
`search` and `get`. Each verb MUST carry agent-facing `description` prose that states what the
tool returns and when to reach for it, plus `scope: "read"` and `readOnlyHint: true`. Each
`search` MUST declare a `filters` list in which **every entry is a real property of that
schema** — OpenRegister's `McpAnnotationValidator` rejects an unknown filter and a single bad
filter fails the whole register import. The exposed set MUST be limited to **operational triage**
(what is configured, what ran, what failed); no schema may be added without an explicit spec
change that argues against the exclusion rationale in `design.md`.

#### Scenario: the derived surface is 16 read tools and nothing else
- **GIVEN** OpenRegister's derived-tool engine is deployed
- **WHEN** the MCP tool list is enumerated
- **THEN** exactly 16 integriq tools exist (8 schemas x `search` + `get`)
- **AND** every one of them is `readOnlyHint: true` and `scope: "read"`

#### Scenario: an unknown search filter fails the import rather than shipping
- **GIVEN** a `search.filters` entry that is not a property of its schema
- **WHEN** the register is imported
- **THEN** `McpAnnotationValidator` rejects the schema and the import fails loudly

### Requirement: REQ-MCP-103 — Integriq MUST NOT expose any MCP write verb on any schema, because its objects are the integration control plane

No `create`, `update`, or `delete` verb MUST be declared on any integriq schema. These
objects are not business records — they are the data flows themselves. Writing a `source`
redirects an integration to an attacker-chosen host and can plant credentials; writing an
`endpoint` opens a new door into the instance; writing a `mapping` silently alters what data is
sent where; writing a `rule` rewrites authorization on live traffic; writing a `consumer` or
`event_subscription` re-points the event bus at an arbitrary sink; writing a `synchronization`
or `synchronization_contract` forges sync state; writing a `job` amounts to scheduled code
execution; writing any log fabricates evidence; and deleting any of them takes integrations down
or destroys forensic history. Because Integriq ingests data from remote systems it does not
control, and that data reaches the agent's context, a write-capable control-plane tool would turn
any hostile upstream payload into a prompt-injection-to-infrastructure-takeover chain. Re-enabling
any write verb MUST require human-in-the-loop approval on the write path **and** agent-principal
attribution in the audit trail.

#### Scenario: no integriq write tool is derivable
- **WHEN** the MCP tool list is enumerated
- **THEN** no `integriq.*.create`, `integriq.*.update`, or `integriq.*.delete` tool exists
- **AND** no integriq tool declares `destructiveHint: true` or a write `scope`

#### Scenario: an agent cannot redirect, create, or destroy a data flow
- **GIVEN** an agent instructed (or prompt-injected via ingested upstream data) to repoint a source, add an endpoint, or delete a mapping
- **WHEN** it searches the tool registry for an integriq write tool
- **THEN** none exists, and the action cannot be taken through MCP at all

### Requirement: REQ-MCP-104 — Credential-bearing schemas MUST be excluded from the tool surface entirely, including `get`, because controller-side redaction does not protect a derived tool

A schema that stores live credential material in the persisted object MUST NOT carry the
`x-openregister-mcp` dialect at all — not even a read verb. This MUST apply to `source`
(`password`, `apikey`, `secret`, `jwt`, `authenticationConfig` — all plaintext per local
ADR-007), `consumer` (`authorizationConfiguration`, plaintext), `event_subscription`
(`protocolSettings.signingSecret`), `rule` (an `authorization`-typed rule's `configuration`
carries the injected header value), `lti_platform` and `lti_tool` (`signingKeys`), and the three
`eudi_*` schemas (`callbackSigningSecret`, `accessTokenHash`, `currentToken`). The reason is
structural: integriq's secret masking is implemented in its **controllers** (e.g.
`EventsController::redactSubscription()`), and an OpenRegister-derived MCP tool reads the stored
object **without passing through any integriq controller** — so controller-side redaction
offers no protection whatsoever against an MCP `get`. Redaction performed at **write** time (as
`CallService::buildResponseData()` does for `call_log`, scrubbing Authorization headers,
basic-auth, query-string secrets and echoed secret values before persisting) DOES protect the
stored object, and a schema so protected MAY be exposed read-only.

#### Scenario: no tool exists for any credential-bearing schema
- **WHEN** the MCP tool list is enumerated
- **THEN** no `integriq.source.*`, `integriq.consumer.*`, `integriq.rule.*`, `integriq.event_subscription.*`, `integriq.lti_platform.*`, `integriq.lti_tool.*`, or `integriq.eudi_*.*` tool exists
- **AND** no plaintext password, API key, OAuth secret, JWT, signing key, or webhook signing secret is reachable through any integriq tool

#### Scenario: call_log is exposed because its secrets are stripped before storage
- **GIVEN** `CallService::buildResponseData()` redacts secret-bearing locations before the CallLog is persisted
- **WHEN** an agent calls `integriq.call_log.get`
- **THEN** the returned request/response carries no live secret
- **AND** the agent can still see the status code, status message, direction, and timing needed to triage the failure
</content>

