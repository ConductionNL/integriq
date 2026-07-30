# Design: environments-and-promotion

## Architecture Overview
Promotion is orchestrated entirely from the SOURCE instance. There is no new
inbound surface on the target beyond the two endpoints
`configuration-export-import` already routes (`POST
/api/configurations/import/preview`, `POST /api/configurations/import`).

```
[Operator on instance A]
        |
        v
Environments & Promotion UI (manifest-v2 page, instance A)
        |
        v
PromotionController (instance A)
        |
        v
PromotionService (instance A)
   |-- 1. ConfigurationService::exportConfiguration()      [unchanged, local]
   |-- 2. scan export for credentialRef placeholders        [new, in-process]
   |-- 3. apply operator-supplied credentialBindings         [new, in-process]
   |-- 4. CallService::call($environmentB.sourceRef, 'POST',
   |        '/api/configurations/import/preview', ...)       [reused dispatch]
   |-- 5. merge target's preview + local credentialRef bucket -> UI
   |-- 6. (on confirm) CallService::call($environmentB.sourceRef, 'POST',
   |        '/api/configurations/import', {confirmed:true, ...})
   |-- 7. write promotion_audit object                       [new, OR object]
        |
        v
   [instance B's own ConfigurationController/ConfigurationService,
    unchanged, receives the call exactly like any other API client]
```

`environment` objects never grant instance A code access to instance B's
database, filesystem, or OpenRegister directly — every cross-instance
interaction is an ordinary authenticated HTTP call through the SAME pipeline
a Source-to-external-API call already uses.

## Decisions

### Decision 1: Environment metadata is an OpenRegister object, not IAppConfig
**Chosen: new `environment` schema in the existing `openconnector` register
(OR object).**

Rationale:
- ADR-008 / `openconnector-direct-or-usage` establish OpenRegister as the
  required persistence layer for every OpenConnector entity; app-local
  reimplementation of storage (which `IAppConfig` effectively is here — a
  flat key/value store) is the pattern this change must NOT introduce.
- `IAppConfig` stores scalar key→value pairs per app; it has no native
  concept of "many named rows with structured fields," no per-row RBAC, no
  audit trail, and nothing analogous to OR's slug/relation `$ref` wiring
  that the promotion UI needs (`environment.sourceRef` → `source` object).
  Representing N named environments would require hand-rolled JSON-blob
  serialisation into a single config key — exactly the kind of
  app-local-reimplementation ADR-008 forbids.
- OR objects give environments a slug (consistent with every other
  OpenConnector entity — sources, endpoints, jobs — for free), standard
  CRUD via `ObjectService`, and RBAC through the same schema-lock mechanism
  already used for `source` (admin-only writes, `99-source-lockdown.json`
  precedent).
- Alternative considered and rejected: `IAppConfig` for a single
  "environments list" JSON blob. Rejected because it duplicates OR's own
  object storage, cannot be individually RBAC'd per environment, and breaks
  the "every entity is persisted as an OpenRegister object" rule with no
  compensating benefit — there is no performance or simplicity win over an
  OR object for what is, structurally, a small list of named records.

`environment` fields: `name`, `slug`, `role` (`source` | `target` | `both`),
`sourceRef` (uuid of a `source`-schema object describing how to reach that
environment's API), `description`. No credential material is stored on the
`environment` object itself — it lives exactly where every other Source
credential lives, behind the referenced Source's
`configuration.authentication.credentialRef`.

### Decision 2: An environment's connectivity is a `source` object, not new plumbing
**Chosen: `environment.sourceRef` points at an ordinary `source`-schema
object (`type: "api"`), dispatched via the existing `CallService::call()`.**

Rationale (see discovery.md): `CallService::call()` is the only outbound
HTTP path in the codebase and is hard-bound to a `source`-schema
`ObjectEntity`. `BrokeredCallService` already layers `credentialRef` proxy
resolution on top of exactly that shape. Wrapping connectivity in a `source`
object means promotion dispatch is a completely ordinary Source call:
CallLog auditing, retry policy, rate limiting, and REQ-005-style redaction
on any promotion CallLog all apply unchanged. The alternative — a new
`EnvironmentClientService` with its own Guzzle client and its own
credential-broker integration — would duplicate `BrokeredCallService`
end-to-end for no behavioural gain, violating "build ON the existing
services, do NOT fork them."

Trade-off accepted: promotion calls appear in the Logs UI as ordinary Source
calls against a Source most operators won't otherwise interact with
directly. `promotion_audit` stores the dispatched CallLog id(s) as a
cross-reference so an operator can pivot from the audit entry to the raw
CallLog when diagnosing a failed promotion (see Decision 4).

### Decision 3: `credentialRef` re-binding is explicit, client-computed, and never resolves a secret
**Chosen: `PromotionService` scans the exported OAS document for
`{"credentialRef": {...}}` placeholders (same shape `BrokeredCallService::
isPlaceholder()` detects) and returns them as a new
`credentialRefsNeedingRebind` preview bucket, alongside the target's own
REQ-007 preview response. The document sent to the target is REWRITTEN
in-process to substitute each flagged `credentialRef` with an
operator-supplied replacement (`{"credentialId": "..."}` or
`{"credentialName": "..."}` valid in the TARGET environment) before the
confirmed import call — never resolved to plaintext at any point.**

Rationale:
- `credentialRef` is, by design (`BrokeredCallService`), never resolved to a
  plaintext secret anywhere except inside the broker's own constrained
  proxy/injection call at actual dispatch time. Promotion must preserve that
  invariant: `PromotionService` only ever reads/writes the reference SHAPE
  (`credentialId`/`credentialName` strings), never a secret value. This is
  the literal meaning of "re-binding, not copying."
- Rewriting happens BEFORE the document leaves instance A and is sent to
  instance B — not as a post-import fixup on B — so the diff preview
  (Decision 4) reflects exactly what will be written, and an unconfirmed
  promotion never leaves a Source on B with a dangling reference.
- Validating that an operator-supplied replacement resolves on B happens by
  delegating to B: the rewritten document is what gets sent to B's own
  `/api/configurations/import/preview`; if the referenced credential doesn't
  exist on B, that surfaces as a normal Source-auth failure the first time
  B calls that Source — the SAME failure mode as any other misconfigured
  Source, deliberately not re-invented as a new validation path. (B's
  `CredentialBrokerService` is the only component that can authoritatively
  answer "does this credentialId/Name exist and resolve for this owner" —
  `PromotionService` on A has no visibility into B's broker state and must
  not guess.)
- Alternative considered: auto-resolve by `credentialName` match only (skip
  operator involvement when names match across environments). Rejected as
  the default because a same-named credential on B is not guaranteed to be
  the operator's intent (naming collisions, different owners) — silently
  auto-binding cross-environment credentials is a lateral-movement risk.
  Left as an OPT-IN convenience: the UI may pre-fill a rebind suggestion
  when a `credentialName` (not `credentialId`) reference already matches a
  visible name, but the operator must explicitly confirm it — never
  automatic.
- Alternative considered: resolve the secret on A and re-inject it as an
  embedded auth field on B. Rejected outright — this is exactly the
  "copied secrets" anti-pattern the brief and `BrokeredCallService`'s "NO
  fallback to embedded authentication under any circumstance" rule forbid.

### Decision 4: Diff preview reuses the target's existing REQ-007 endpoint verbatim
**Chosen: `PromotionService` calls the target's unmodified `POST
/api/configurations/import/preview` remotely (via `CallService::call()`,
Decision 2) and merges its response with the local
`credentialRefsNeedingRebind` bucket (Decision 3). No new diff/classification
algorithm is written.**

Rationale: `ConfigurationImportPreviewService` already computes creates/
updates/collisions/unresolvedReferences/credentialsNeedingReentry against
whatever environment it runs in. Since promotion's target IS a different
environment, the only correct place to run that classification is ON the
target — a locally-computed diff on A would be comparing against A's own
data, not B's. Invoking B's already-tested, already-routed endpoint over
HTTP is both simpler and correct; re-implementing the same classification
logic locally (as if it read B's data over some new API) would duplicate
`ConfigurationImportPreviewService` for zero benefit and risk drift between
the two copies.

## API Design

### `POST /api/environments`
Create an `environment` object. Admin-only (ADR-023 `environment.manage`,
seeded `["admin"]`), plus OR data-layer authorization on the `environment`
schema.

**Request:**
```json
{ "name": "Production", "slug": "production", "role": "target", "sourceRef": "<source-uuid>", "description": "..." }
```
**Response:**
```json
{ "id": "...", "uuid": "...", "slug": "production", "name": "Production", "role": "target", "sourceRef": "<source-uuid>" }
```

### `GET /api/environments`
List registered environments. `environment.manage` action gate.

### `POST /api/promotions/preview`
Non-mutating. Computes the merged diff preview (Decision 4) without writing
anything on A or B. `environment.promote` action gate.

**Request:**
```json
{
  "configurationId": "cfg-1",
  "targetEnvironmentSlug": "production",
  "credentialBindings": [
    { "sourceSlug": "my-api-source", "field": "configuration.authentication.credentialRef", "credentialName": "prod-api-key" }
  ]
}
```
**Response:**
```json
{
  "creates": [], "updates": [], "collisions": [],
  "unresolvedReferences": [],
  "credentialsNeedingReentry": [],
  "credentialRefsNeedingRebind": [
    { "type": "source", "slug": "my-api-source", "field": "configuration.authentication.credentialRef", "rebound": true }
  ]
}
```

### `POST /api/promotions`
Confirmed promotion. Requires `confirmed: true` (mirrors REQ-008); rejects
with 400 otherwise. `environment.promote` action gate. Delegates the actual
write to the target's own `/api/configurations/import` (unchanged), then
writes a `promotion_audit` object.

**Request:** same shape as `/api/promotions/preview` plus `"confirmed": true`.
**Response:**
```json
{
  "auditId": "...",
  "written": { "sources": ["my-api-source"], "endpoints": ["..."] },
  "callLogId": "..."
}
```

## Database Changes
Two new OpenRegister schemas added to `lib/Settings/openconnector_register.json`
(REQ-A-001/REQ-A-005 conventions from `openconnector-register-schema`):
- `environment` (mutable config schema — `appendOnly: false`, `immutable: false`):
  `name`, `slug`, `role` (enum `source`|`target`|`both`), `sourceRef` (UUID,
  `$ref` to `source`), `description`.
- `promotion_audit` (log schema — `appendOnly: true`, `immutable: true`,
  carries `x-openregister-archival` retention matching the existing log
  schemas' convention): `actorUid`, `configurationId`, `fromEnvironmentSlug`,
  `toEnvironmentSlug`, `startedAt`, `completedAt`, `outcome`
  (`success`|`failed`|`rejected`), `previewSummary` (counts only — no
  entity payloads, no credential values), `credentialRebindCount`,
  `callLogId` (cross-reference to the `CallLog` created by the underlying
  `CallService::call()` dispatch, per Decision 2).

## Nextcloud Integration
- Controllers: `PromotionController` (Controller → Service → Mapper, ADR-008).
- Services: `PromotionService` (new), reusing `ConfigurationService`,
  `ConfigurationImportPreviewService`'s response SHAPE (not its code — the
  actual preview call happens on the target), `CallService`,
  `BrokeredCallService` (transitively, via `CallService::call()`),
  `ActionAuthService`.
- Mappers/Entities: none new — `environment` and `promotion_audit` are plain
  OR objects via `ObjectService`, consistent with every other OpenConnector
  schema (no bespoke Doctrine mapper).
- Events/Hooks: none new.

## Security Considerations
- Both new action keys (`environment.manage`, `environment.promote`) are
  seeded `["admin"]` in `lib/actions.seed.json`, following the existing
  `<domain>.<verb>` convention (`configuration.export`, `catalog.instantiate`).
- No secret ever crosses the promotion call: exported Sources are
  REQ-005-redacted exactly as today, and `credentialRef` values are
  references only (Decision 3) — `PromotionService` cannot read a plaintext
  secret because it never calls the broker's resolution methods, only
  `ConfigurationService`/`CallService`/`BrokeredCallService`'s existing,
  unmodified entry points.
- `promotion_audit.previewSummary` stores counts and slugs only, never
  entity payloads or credential values, mirroring
  `BrokeredCallService::logOwnerRefusal()`'s "guard name + identity only,
  never secret material" logging discipline.
- The underlying entity writes on the TARGET still pass through that
  target's own OpenRegister data-layer authorization unchanged (e.g.
  Source writes remain admin-only there too) — promotion does not grant any
  new authority on B beyond what the environment Source's credential
  already carries.
- CSRF: promotion is triggered from the UI via the standard Nextcloud
  session + CSRF token flow; `#[NoCSRFRequired]` is NOT used on
  `PromotionController` (unlike `ConfigurationController`'s export/import,
  which accept file uploads/API-style calls) since promotion is
  UI-initiated only in this change's scope.

## NL Design System
Environments & Promotion page is a manifest-v2 `type: "index"` list page
(environment CRUD) plus a promote flow reachable from a configuration
group's existing actions. The promote flow's confirmation step is its own
`NcModal` file under `src/modals/PromotePreviewModal.vue` (never inlined —
hydra modal-isolation gate). Environment/target select uses `NcSelect` with
`inputLabel` set (hydra nc-input-labels gate). All strings ENGLISH per
project i18n convention.

## File Structure
```
lib/
  Controller/
    PromotionController.php
  Service/
    PromotionService.php
  Settings/
    openconnector_register.json          (add environment, promotion_audit schemas)
  actions.seed.json                      (add environment.manage, environment.promote)
appinfo/
  routes.php                             (add /api/environments*, /api/promotions*)
src/
  modals/
    PromotePreviewModal.vue
  views/
    EnvironmentsPromotion.vue
tests/
  Unit/Service/PromotionServiceTest.php
  Integration/PromotionIntegrationTest.php
```

## Seed Data

### Schema: `environment`
| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | `local` | `acceptance` |
| name | Local | Acceptance |
| role | source | target |
| sourceRef | *(seeded `source` object, type: api, location: `https://acceptance.example.org`)* | *(same convention)* |
| description | This instance | Acceptance environment for pre-production promotion |

**Related items per object:** none (no files/notes/tasks/contacts — environments
are configuration metadata, not content objects).

### Schema: `promotion_audit`
No seed rows — append-only log schema, populated only by real promotions
(consistent with `call_log`/`job_log`, which also ship with zero seed rows).

## Trade-offs
- Reusing `CallService::call()` for promotion dispatch means promotion
  inherits Source-call semantics (retry, rate limit, CallLog) that were
  designed for arbitrary external APIs, not specifically for
  instance-to-instance OpenConnector calls — accepted because the
  alternative (new dispatch code) duplicates a large, already-hardened
  pipeline for a narrower use case.
- Diff preview requires a live round-trip to the target environment before
  every promotion attempt (no offline/cached diff) — accepted because a
  stale local diff could show a false "no collisions" and silently
  overwrite something created on B after the last preview.
- `credentialRefsNeedingRebind` is computed by scanning the export JSON
  client-side rather than by extending `ConfigurationImportPreviewService`
  with this concern — accepted (per discovery.md) because REQ-007's preview
  is scoped to slug/id resolution, not credential-broker semantics, and
  bolting broker-awareness onto that service would blur its single
  responsibility.

## Open Questions
- Should `environment.role` (`source`/`target`/`both`) be enforced at
  promotion time (reject promoting FROM an environment whose local record
  has `role: target` only), or is it purely descriptive/UI-filtering?
  Deferred to tasks.md implementation; default behaviour treats `role` as
  UI-filtering only (any environment can technically be promoted to/from,
  matching how Sources aren't role-locked today either).
