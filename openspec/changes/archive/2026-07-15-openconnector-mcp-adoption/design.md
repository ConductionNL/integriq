# Design: openconnector-mcp-adoption

## Context

ADR-063 makes OpenRegister the single MCP registry: a leaf app declares
`configuration["x-openregister-mcp"]` on a schema and OR derives `{appId}.{schema}.{verb}`
tools. Exposure is default-OFF.

OpenConnector at HEAD: **27 schemas** (24 in `lib/Settings/openconnector_register.json`, 3
EUDI schemas in `lib/Settings/register.d/eudi-wallet-credential-issuance.json`), and **no MCP
code whatsoever**. So: `kind: config`, no provider surgery.

The defining property of this app is that **its objects are the integration control plane, not
business data**. The threat model is therefore inverted relative to every other leaf app: the
risk is not "the agent gives a wrong answer", it is "the agent (or anyone who can prompt it)
**reads a credential** or **redirects a data flow**".

## Goals / Non-Goals

**Goals**
- Give an agent enough to do **operational triage** of integrations (what is configured, what
  ran, what failed, why).
- Guarantee **no credential** can be reached through any openconnector tool.
- Guarantee **no data flow** can be created, redirected, or destroyed through any tool.

**Non-Goals**
- Any write path. Not deferred — **refused** (D3).
- Any curated `#[McpTool]` service tool (D5).
- Fixing the controller-side-only redaction of `event_subscription` (raised as an open question;
  it is a latent leak independent of MCP).

## Decisions

### D1: The curated ON list — 8 schemas, read-only

All 8 live in `openconnector_register.json`, so the dialect is added there (no fragment needed),
matching the pipelinq exemplar. Verbs: `search` + `get`. `scope: read`, `readOnlyHint: true`.
Every filter below is a **verified real property** of its schema.

| # | Schema | Verbs | `search.filters` (all verified real properties) | Why an agent is genuinely asked this |
|---|--------|-------|--------------------------------------------------|--------------------------------------|
| 1 | `endpoint` | search, get | `name`, `method`, `targetType`, `targetId`, `slug` | "What does this instance expose, and where does `/api/foo` route?" The inbound surface inventory. Holds no credential. |
| 2 | `mapping` | search, get | `name`, `slug`, `reference` | "What does mapping X actually transform?" Twig/property-path rules — the single most-asked *explain this* question in integration debugging. Holds no credential. |
| 3 | `synchronization` | search, get | `name`, `sourceId`, `targetId`, `sourceType`, `targetType`, `status` | The sync definition + its last-changed/checked/synced timestamps. "Is the sync configured, and when did it last run?" Auth lives on the `Source`, not here — `sourceConfig`/`targetConfig` are data-shaping keys (`resultsPosition`, `format`). |
| 4 | `synchronization_contract` | search, get | `synchronizationId`, `originId`, `targetId` | Per-object sync state. "Why is *this one record* not syncing?" — the drill-down from #3, and the only place origin↔target identity is recorded. |
| 5 | `call_log` | search, get | `sourceId`, `statusCode`, `direction`, `synchronizationId` | **The** outbound-call triage surface: status code, message, direction, timing. "Source X is failing — what is the upstream actually returning?" Safe because secrets are redacted **at write time** (see D4). |
| 6 | `job` | search, get | `name`, `isEnabled`, `status`, `jobClass`, `slug` | The scheduler inventory: `isEnabled`, `lastRun`, `nextRun`, `interval`. "Is the nightly sync job even turned on?" |
| 7 | `job_log` | search, get | `jobId`, `level`, `jobClass` | Job execution outcomes incl. `level`, `message`, `stackTrace`. "Which jobs are erroring, and with what?" |
| 8 | `synchronization_log` | search, get | `synchronizationId`, `userId` | Sync run outcomes (message, result summary, execution time). The run-level companion to #4. |

= **16 tools** (8 x 2 reads). **Zero write tools.**

### D2: Credential-bearing schemas are excluded OUTRIGHT — including `get`

**The load-bearing finding of this change:** OpenConnector's secret masking is implemented in
**controllers** — `EventsController::redactSubscription()` (verified: replaces
`protocolSettings.signingSecret` / `previousSigningSecret` with `**********` on every read),
plus redaction helpers in `LtiController` and `EudiIssuerKeyAdminController`. An OR-derived MCP
tool **does not pass through any openconnector controller**; it reads the stored object directly
from OpenRegister. **Controller redaction is therefore worth exactly nothing against an MCP
`get`.** A schema whose only protection is controller-side must be OFF, not "OFF for write".

| Schema | Credential material it stores | Verdict |
|---|---|---|
| `source` | `password`, `apikey`, `secret` (OAuth client secret), `jwt`, `authorizationHeader`, `authenticationConfig` — **each annotated "plaintext per ADR-007" in its own description** | **OFF entirely.** This is the single richest credential store in the app. A `get` returns live secrets for every integrated system. Not exposed even read-only. This is a painful loss (it is the most useful integration read there is) and it is not negotiable — see Open Question 1. |
| `consumer` | `authorizationConfiguration` — "plaintext per ADR-007" | **OFF entirely.** Event-sink auth config. |
| `event_subscription` | `protocolSettings.signingSecret` (`whsec_`-prefixed HMAC secret) + `previousSigningSecret` | **OFF entirely.** Redacted *only* in `EventsController` — the exact bypass described above. |
| `rule` | `configuration` (free-form, action-specific) where `type` ∈ {**authorization**, transformation, validation, audit} and `action` ∈ {`error`, `replace`, **`appendHeader`**} | **OFF entirely.** An `authorization`-type rule with an `appendHeader` action carries the **header value** — i.e. a live bearer token — inside `configuration`. Not merely a write hazard: reading it leaks the credential. |
| `lti_platform`, `lti_tool` | `signingKeys` | **OFF entirely.** JWT signing key material. |
| `eudi_credential_offer` | `callbackSigningSecret`, `preAuthorizedCodeHash`, `txCodeHash` | **OFF entirely.** Wallet issuance secrets. |
| `eudi_issuance_session` | `accessTokenHash`, `cNonce` | **OFF entirely.** |
| `eudi_status_list` | `currentToken`, `issuerKid` | **OFF entirely.** |

### D3: Every write verb is REFUSED — on every schema

No `create`, `update`, or `delete` anywhere. Stated per schema so a future change must argue
*against* the refusal rather than merely notice a gap:

| Verb x schema | Refused because |
|---|---|
| `create`/`update` on `source` | Changing a Source's `location` **redirects an integration to an attacker-chosen host**, and writing its `password`/`apikey`/`secret` **plants credentials**. This is textbook exfiltration + lateral movement, executable through a single tool call driven by prompt-injected text arriving from *an upstream system OpenConnector itself fetched*. Categorically refused. (Already OFF for reads too — D2.) |
| `create`/`update` on `endpoint` | Creating an Endpoint **opens a new door into this Nextcloud instance** and binds it to a `targetType`/`targetId`. An agent that can mint endpoints can construct its own unauthenticated data tap. |
| `create`/`update` on `mapping` | A Mapping decides **what data is sent where**. Silently altering one changes the payload of every synchronisation using it — a data-integrity and exfiltration primitive that leaves the topology looking untouched. |
| `create`/`update` on `rule` | Rules can `appendHeader` and are typed `authorization` — writing one **rewrites the auth on live traffic**. (Already OFF for reads too — D2.) |
| `create`/`update` on `consumer`, `event_subscription` | A Consumer/Subscription is a **delivery sink**: writing one points the event bus at an arbitrary URL. That is a one-call exfiltration channel for everything on the bus. (Already OFF for reads too — D2.) |
| `create`/`update` on `synchronization`, `synchronization_contract` | Writing a Synchronization re-points source→target. Writing a Contract **forges sync state** (hashes/timestamps), which can suppress a real sync or force a bogus overwrite of good data. |
| `create`/`update` on `job` | Jobs execute a `jobClass` with `arguments` on a schedule. An agent that can write a Job has **arbitrary scheduled code execution** within the app's job surface. Among the most dangerous verbs in the entire fleet. |
| `create`/`update` on the log schemas (`call_log`, `job_log`, `synchronization_log`, `synchronization_contract_log`) | Logs are **evidence**. An agent that can write them can fabricate or launder integration history. Logs are append-only-by-the-system, never by a caller. |
| **`delete` on all 27** | Categorically refused. Deleting a Source/Endpoint/Mapping/Job **takes integrations down** (availability), and deleting a log **destroys the audit evidence of whatever just happened** (anti-forensics). No agent may hold a delete on the control plane, at any confidence level. |

Reinforcing all rows: OpenConnector's job is to **ingest data from remote systems it does not
control**. That data reaches the agent's context. A write-capable control-plane tool therefore
turns any hostile upstream payload into a **prompt-injection → infrastructure-takeover** chain.
Read-only breaks that chain at the only place it can be cleanly broken.

**What would have to change to revisit this:** human-in-the-loop approval on every control-plane
write, plus agent-principal attribution in the audit trail. Both are platform capabilities
openconnector does not own.

### D4: `call_log` is safe to expose — verified, not assumed

`call_log.request` is described as "*Captured outbound request (method, URL, headers, body)*",
which reads like a guaranteed Authorization-header leak. It is not.
`CallService::buildResponseData()` redacts **before persisting**:

> "Security: never persist live secrets to the CallLog. Redact secret-bearing locations from
> the config copy that is written into 'request', from the URL (which may carry query-string
> secrets), and from the response body (which can echo the request URL with its query secrets).
> The actual outbound call has already been dispatched with the REAL secrets before this method
> runs."

via `collectSecretValues()`, `redactSecretsFromConfig()` (Authorization / Proxy-Authorization /
Cookie headers, pattern-matched secret-looking header values, the basic-auth `auth` array,
secret-named query/form params, TLS `cert`/`ssl_key` paths), `redactSecretsFromUrl()` and
`redactSecretValuesFromString()`. Redaction happens **at write time, in the storage layer's
input** — unlike `event_subscription`, whose redaction is controller-side (D2). So the **stored**
`call_log` object is already scrubbed, and a derived MCP read cannot resurrect a secret.

*Accepted residual:* the persisted `response.body` still contains whatever the upstream returned
— business data, possibly PII. That is inherent to a call log's purpose, it is bounded by
OpenRegister RBAC (the agent sees only what the session user may see), and it is the price of
having any integration-triage capability at all. Documented, not hidden.

### D5: No `#[McpTool]` service tool

Scanned `lib/Service/` for non-CRUD behaviour worth curating (`CallService`,
`SynchronizationService`, `ConfigurationService`, `BankfeedSyncService`, …). Every genuinely
interesting method **executes** something — fire a call, run a sync, test a source, replay an
event. Each is a control-plane *action*, refused on precisely the same grounds as D3 (a "test
this source" tool is an SSRF primitive: it makes the server issue an HTTP request to a location
the caller influences). Nothing qualifies. **No PHP in this change.**

## The OFF list — 19 of 27 schemas, grouped

| Category | Schemas | Why OFF |
|---|---|---|
| **Credential stores** (the hard exclusion) | `source`, `consumer`, `rule`, `event_subscription`, `lti_platform`, `lti_tool`, `eudi_credential_offer`, `eudi_issuance_session`, `eudi_status_list` | Hold live secrets in the stored object; their masking is controller-side and an OR-derived tool bypasses it (D2). OFF including `get`. **9 schemas.** |
| **Full data payloads** (exfiltration surface, no triage value) | `event`, `event_message`, `synchronization_contract_log`, `bankfeed_batch` | Carry the complete synced/eventing payload (`data`, `payload`, `source`/`target` object bodies, bank `transactions`). Everything they offer for triage is already available from `synchronization_log` + `synchronization_contract` + `call_log` **without** re-serving the full record bodies through a second channel. |
| **Niche transport records** (tool-budget discipline) | `peppol_transmission`, `sms_message`, `ris_sync_record`, `bankfeed_connection`, `lti_deployment`, `lti_identity_link` | Per-integration transport rows for one vertical each. Real, but nobody asks an assistant about them; each one costs tool budget shared with the entire fleet. `bankfeed_connection` additionally holds consent handles (`consentReference`, `redirectUrl`). **6 schemas.** |

9 + 4 + 6 = **19 OFF**, 8 ON, 27 total.

## Risks / Trade-offs

- **[Losing `source` from the read surface guts the most useful question]** → Accepted, and it
  hurts: "what is source X configured to call, and is it enabled?" is now unanswerable through
  MCP. There is no safe middle ground while the dialect is all-or-nothing per schema — see Open
  Question 1. Partial relief: `synchronization` exposes `sourceId` and last-sync timestamps, and
  `call_log` exposes what actually happened on the wire.
- **[`job` exposure with a free-form `arguments` blob]** → Residual, documented (proposal Risk 4).
  If a secret-in-arguments convention ever lands, `job` moves to OFF.
- **[`call_log` response bodies contain upstream business data]** → Accepted, bounded by OR RBAC
  (D4).
- **[A future change adds a write verb "just for endpoints"]** → REQ-MCP-103 makes read-only
  normative, so that is a spec violation rather than a judgement call.

## Migration Plan

1. Add the dialect to the 8 schemas. **Inert** until OR's derived-tool engine deploys — no
   behaviour change on any existing instance.
2. On deploy, the descriptor change re-triggers the version-gated `importFromApp`
   (`lib/Repair/InitializeRegister.php`); `McpAnnotationValidator` validates the dialect and a
   bad filter **fails the import** — the intended hard gate.
3. Verify 16 derived read tools appear, **zero** write tools, and **no** tool for any schema in
   the credential-store row.
4. **Rollback:** remove the 8 `configuration` blocks, re-import.

## Open Questions

1. **Field-level projection in the dialect.** If `x-openregister-mcp` could declare an allow-list
   of returned properties, `source` could be exposed minus `password`/`apikey`/`secret`/`jwt` and
   OpenConnector would get its most valuable read back. Today it cannot. This is the single
   highest-value platform improvement for this app.
2. **`event_subscription.signingSecret` is redacted only in the controller.** That is a latent
   leak on *any* path that reads the object directly (not just MCP). `CallService` already shows
   the right pattern — redact at write. Worth raising independently of this change.
3. Does any deployed openconnector Job store a credential in `arguments`? If yes, `job` must move
   to the OFF list.
</content>
