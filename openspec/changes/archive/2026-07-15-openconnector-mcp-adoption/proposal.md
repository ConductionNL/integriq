---
kind: config
depends_on: []
---

# Change: openconnector-mcp-adoption

## Why

ADR-063 ("MCP as Platform Abstraction", 2026-07-12, hydra #102) rules that apps MUST NOT
hand-write MCP tool code. A leaf app declares a per-schema `x-openregister-mcp` block in its
register JSON and OpenRegister derives `{appId}.{schema}.{verb}` tools automatically. Exposure
is **default-OFF**: a schema without the dialect produces no tools.

OpenConnector ships **no MCP provider** (`grep -ril mcp lib/` at HEAD returns nothing), so
there is no hand-written CRUD to retire and no provider surgery. This is a pure `kind: config`
dialect declaration over its 27 schemas (24 in `openconnector_register.json` + 3 EUDI schemas
in `register.d/`).

What makes OpenConnector different from every other leaf app is **what its objects are**.
These are not business records — they are the **integration control plane**. A `Source` is a
remote system plus its credentials. An `Endpoint` is a route into this instance. A `Mapping`
is a transformation. A `Rule` can rewrite headers. A `Consumer` is an event sink with its auth
config. Together they *are* the data flows. An agent that can write them can **redirect or
exfiltrate every integration on the instance** — change a Source's `location` and the data
goes somewhere else; add an Endpoint and you have opened a door; edit a Mapping and you have
silently altered what gets sent.

Worse, several of these schemas **store live credentials in plaintext**, and they say so in
their own descriptions. `Source` declares `password`, `apikey`, `secret`, `jwt`, and
`authenticationConfig` — each annotated "*plaintext per ADR-007*". `Consumer` declares
`authorizationConfiguration` — "*plaintext per ADR-007*". Whatever masking exists for these is
implemented in **controllers** (`EventsController::redactSubscription()`,
`LtiController`, `EudiIssuerKeyAdminController`), and a derived MCP tool does not go through a
controller — it reads the stored object straight out of OpenRegister. **Controller-layer
redaction is therefore no protection at all against an MCP `get`.**

This change consequently declares a **strictly read-only** surface over **8** carefully-chosen
operational schemas, enables **zero write verbs**, and leaves every credential-bearing schema
**OFF entirely — including `get`**.

## Motivation

- The genuine, high-value agent question here is **operational triage**: "why did the sync to
  source X fail?", "which jobs are erroring?", "what does mapping Y do?". Those are answerable
  from logs and definitions, and they are exactly what an on-call engineer asks.
- The genuine, high-severity hazard is that the same tool surface, extended one verb further,
  becomes a **data-exfiltration and lateral-movement primitive**. That has to be refused in the
  spec, in writing, not merely omitted.
- Credential-bearing schemas must be named and excluded now, while the surface is small. Once
  Hermiq prompts depend on a tool, removing it is a breaking change.

## Affected Projects

- [ ] Project: openconnector — declare `x-openregister-mcp` (read verbs only) on 8 of 27
      schemas in `lib/Settings/openconnector_register.json`; no PHP, no write verbs.

## Scope

### In Scope

- Declare `x-openregister-mcp` with `enabled: true` and **`search` + `get` only** on **8**
  schemas: `endpoint`, `mapping`, `synchronization`, `synchronization_contract`, `call_log`,
  `job`, `job_log`, `synchronization_log`.
- Per-verb agent-facing `description` prose, `scope: read`, `readOnlyHint: true`, and for
  `search` a `filters` list whose every entry is a **verified real property** of that schema.
- Record the credential-bearing exclusions and the grouped exclusion rationale (design.md).

### Out of Scope

- **Every write verb on every schema** — refused, see design.md Decision D3.
- **`source`, `consumer`, `rule`, `event_subscription`, `lti_platform`, `lti_tool`, and the
  three `eudi_*` schemas** — excluded outright, **including `get`**, because they hold live
  credential material (design.md Decision D2).
- Any `#[McpTool]` service attribute (design.md Decision D5).
- Any change to schema properties, existing controller redaction, or seed data.

## Approach

Add a `configuration["x-openregister-mcp"]` block to the 8 curated schemas in
`lib/Settings/openconnector_register.json`, exactly as the pipelinq exemplar does for `client`
and `lead`. All 8 live in that one file, so no `register.d` fragment is needed.

## New Dependencies

None. The dialect is inert until OpenRegister's derived-tool engine is deployed.

## Impact

- **Config:** `lib/Settings/openconnector_register.json` only (8 schemas gain a `configuration`
  key). No other file changes.
- **Register version:** the descriptor change re-triggers the version-gated `importFromApp`
  in `lib/Repair/InitializeRegister.php`.
- **Consumers:** Hermiq gains 16 openconnector tools (8 schemas x 2 read verbs), zero write tools.
- **No PHP, no frontend, no migration.**

## Cross-Project Dependencies

- **openregister** (blocking for runtime, not for this change): derived tools only materialise
  once OR's schema-derived tool provider is deployed. The dialect is a no-op before that.
  `depends_on` is empty because the predecessor is a cross-repo slug.
- **hermiq**: consumes the derived tool ids; no whitelist migration needed (no pre-existing
  openconnector tool to re-point).

## Risks

### Risk 1: An agent rewrites the integration control plane (redirect / exfiltrate)

- **Severity**: High
- **Mitigation**: **Refused outright.** No `create`/`update`/`delete` is declared on any
  schema. An undeclared verb is not derived, so the tool does not exist to be called. See
  design.md D3, which enumerates the refusal per schema.

### Risk 2: A derived `get` leaks a live credential out of a Source, Consumer, or Rule

- **Severity**: High
- **Mitigation**: Every credential-bearing schema is **OFF entirely**, `get` included
  (design.md D2). This is the correct posture precisely because the app's existing redaction is
  controller-side and an OR-derived tool bypasses it — verified in
  `EventsController::redactSubscription()`.

### Risk 3: `call_log` exposes secrets captured from outbound requests

- **Severity**: Low (mitigated at source, verified)
- **Mitigation**: **Verified non-issue.** `CallService::buildResponseData()` redacts
  secret-bearing locations *before persisting* — "Security: never persist live secrets to the
  CallLog" — via `collectSecretValues()` / `redactSecretsFromConfig()` /
  `redactSecretsFromUrl()` / `redactSecretValuesFromString()`, covering the Authorization
  header, basic-auth, query-string secrets, and secret values echoed in the body. The **stored**
  object carries no live secret, so a derived read is safe. Residual: response bodies still hold
  upstream business payloads — accepted, bounded by OpenRegister RBAC, documented in design.md.

### Risk 4: `job.arguments` is a free-form blob that could hold a credential

- **Severity**: Medium
- **Mitigation**: `job` declares no credential property and openconnector defines no convention
  for putting secrets in `arguments` (unlike `event_subscription.protocolSettings.signingSecret`,
  which does and is therefore excluded). Exposed with the residual risk documented; if a
  secret-in-arguments convention ever appears, `job` must move to the OFF list.

## Rollback Strategy

Remove the 8 `configuration["x-openregister-mcp"]` blocks from
`lib/Settings/openconnector_register.json` and re-import. Purely additive — no property,
route, or stored object is touched, so removal restores the prior (no-tools) state exactly.

## Open Questions

1. Should OpenRegister support **field-level projection/redaction** in the dialect? It would let
   `source` be exposed for `get` minus its credential fields — today the dialect is all-or-
   nothing per schema, so exclusion is the only lever and OpenConnector loses its single most
   useful read ("what is source X configured to call?").
2. `event_subscription`'s signing secret is redacted only in `EventsController`. Should that
   redaction move into the stored object (as `CallService` already does at write time)? If it
   did, `event_subscription` could be safely exposed. This looks like a latent leak independent
   of MCP — worth raising on its own.
</content>
