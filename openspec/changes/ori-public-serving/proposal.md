# Proposal: ori-public-serving

## Summary

OpenConnector serves the public ORI 1.4 API (`/api/ori/v1/*` — Popolo-flavoured
meetings, agenda items, decisions, votes) on decidesk's behalf: decidesk keeps
owning the DATA (its OpenRegister objects) and the CONFIGURATION (which
Endpoints/Mappings exist and what they expose), while openconnector owns the
ENDPOINT/serving MACHINERY (public dispatch, anonymous auth handling, response
shaping) it already runs for every other integration. This change specs a set
of openconnector Endpoint + Mapping configurations that reproduce decidesk's
current `OriController`/`OriSerializer` surface, names the concrete places the
existing mapping/rule engine cannot express that surface unmodified, and
defines the parity test plan that must pass before decidesk's controller is
retired.

## Motivation

decidesk currently self-serves ORI 1.4 from `lib/Controller/OriController.php`
(5 `#[PublicPage]` endpoints), `lib/Service/OriSerializer.php`, and
`lib/Service/OriPublicationService.php` — roughly 800 lines of bespoke HTTP
dispatch, JSON-LD field mapping, and public-caller safety logic. openconnector
already owns exactly this kind of machinery for every other integration it
serves: public path dispatch with cache-backed resolution
(`endpoint-runtime` REQ-EP-001/004), an anonymous-serving auth model (no
`authentication` rule on an Endpoint means no auth check runs at all — see
`lib/Service/EndpointService.php:2961`), and a declarative Twig/dot-path
mapping engine (`mapping-and-search` REQ-001/002) for exactly the kind of
"first source field that is set wins" field renaming `OriSerializer`
hand-codes.

Per Hydra ADR-091 (proposed), externally-facing API surface belongs to
openconnector; app repos consume it rather than re-implementing dispatch. The
decidesk "Back to Six" programme (product-owner decision, 2026-08-19) makes
decidesk the sole home of raadsinformatie once the in-flight `ori-adoption`
(decidesk) / `ori-removal` (procest) pair lands, which is the right moment to
also stop duplicating serving infrastructure that already exists one repo
over.

Separately, decidesk carries a pending change `notubiz-ibabs-griffie-koppeling`
(`kind: openconnector`) that proposes NEW NOTUBIZ/iBabs adapters, a sync
engine, and four new OpenRegister schemas. openconnector already ships
`lib/Service/NotuBizConnectorService.php`, `lib/Service/IBabsConnectorService.php`,
and `lib/Cron/RISPollJob.php` (archived changes
`2026-06-14-ibabs-notubiz-connector`, `2026-06-15-decidesk-ris-import-bundle`).
That pending change was written without visibility into the already-shipped
connectors and duplicates them structurally (new adapters, new sync engine)
even though it targets the same vendor APIs. This change does not fold that
proposal itself (it lives in decidesk's repo) but records the finding and adds
a task to close/fold the duplicate — see Impact and `tasks.md`.

## Affected Projects

- [x] Project: `openconnector` — new Endpoint + Mapping configurations serving
      `/api/ori/v1/*` from decidesk's OpenRegister data; no new PHP classes for
      the happy path, two small, named engine gaps (see design.md) that need
      either a new rule type or an accepted scope reduction.
- [x] Project: `decidesk` — declares/owns the ORI configuration payload
      (register.d seed of Endpoint/Mapping objects) and, once parity is
      proven, retires `OriController`/`OriSerializer`. That removal is a
      **companion change in decidesk's own repo, not authored here** (see
      Out of Scope).
- [ ] Project: `openregister` — no changes; consumed as-is (`ObjectService`,
      schema RBAC/`x-openregister-authorization` on `PublicationPayload`).

## Scope

### In Scope

- An openconnector Endpoint configuration per ORI resource (organizations,
  persons, memberships, events, agendaitems, motions, amendments,
  voteevents, votes, reports, publications — 10 resources, `motions` and
  `amendments` sharing the `decision` schema like today) serving GET
  list + GET single-item at `/api/ori/v1/{resource}` and
  `/api/ori/v1/{resource}/{id}`, read-only, anonymous (no `authentication`
  rule).
- Mapping objects (`mapping` schema) that reproduce `OriSerializer`'s
  `FIELD_RULES` / `PAYLOAD_FIELD_RULES` / `EMAIL_TYPES` field-projection logic
  as openconnector mapping recipes, evaluated honestly against what
  `MappingService::executeMapping()` can express today.
- A named, itemised list of the specific `OriController`/`OriSerializer`
  behaviours the current endpoint-runtime + mapping engine **cannot**
  reproduce as configuration alone (JSON-LD envelope reshaping, the
  single-item discriminator/lifecycle/publish-window gate) — see design.md
  "Mapping-engine gap assessment". No silent scope reduction: each gap is
  either closed with a small, named engine change proposed in `tasks.md`, or
  explicitly deferred with the resulting behavioural difference documented.
- A parity test plan (`test-plan.md`) that runs the same requests against the
  existing decidesk `OriController` and the new openconnector Endpoints and
  diffs the responses, required to pass green before any cutover.
- A task naming the overlap between decidesk's pending
  `notubiz-ibabs-griffie-koppeling` change and openconnector's already-shipped
  NOTUBIZ/iBabs connectors, for decidesk to fold or close.

### Out of Scope

- Retiring `decidesk/lib/Controller/OriController.php` — that is a companion
  change in the decidesk repo, gated on this change's parity test plan
  passing. Named here, not authored here.
- `OriPublicationService` (the outbound POST-to-external-ORI-endpoint path
  used when decidesk pushes VotingRound results elsewhere) — unrelated
  direction of data flow, not touched.
- The decidesk `ori-adoption` schema-alignment work (Meeting/AgendaItem/
  VotingRound/GovernanceBody field additions) — that change already owns the
  Popolo schema shape this change reads from; this change is purely a serving
  layer on top of whatever schema shape `ori-adoption` lands.
- Rewriting or folding `notubiz-ibabs-griffie-koppeling` itself — flagged as a
  task for decidesk to act on, not rewritten here.
- Write/push ORI endpoints — ORI 1.4 as specified here is read-only, matching
  today's `OriController` (no POST/PUT/DELETE on `/api/ori/v1/*`).

## Approach

Model each ORI resource as one openconnector `Endpoint` (`targetType:
register/schema`, `targetId` pointing at the decidesk register/schema pair)
with an `inputMapping` that injects the resource's fixed query filters
(`lifecycle=published`, `isPublished=public` + `decisionType=motion|amendment`,
or nothing for the two lifecycle-free resources) ahead of
`EndpointService::getObjects()`'s `findAllPaginated()` call — this reproduces
`OriController::buildFilters()` declaratively. Field-level reshaping
(`OriSerializer::applyRules()`) becomes a `mapping`-type after-rule per
Endpoint. The two behaviours the engine cannot express today (envelope
reshaping to `{@context, @type, count, items}`, and the single-item
GET-by-id discriminator/lifecycle/publish-window gate) are named explicitly in
design.md with a proposed minimal engine addition for each, rather than
silently shipping a narrower public API than the one being replaced.

## New Dependencies

None. Uses openconnector's existing Endpoint/Mapping/rule-pipeline
infrastructure.

## Impact

- **openconnector**: new `register.d` seed content (Endpoint + Mapping
  objects) under a decidesk-facing configuration bundle; no schema changes.
  Two small, named additions to `EndpointService`/`MappingService` if the
  identified gaps are closed rather than deferred (see design.md; sized in
  `tasks.md`).
- **decidesk**: no code changes in this proposal. The companion retirement
  change (out of scope here) will delete `OriController.php`,
  `OriSerializer.php`, and their routes once parity is proven.
- **decidesk's `notubiz-ibabs-griffie-koppeling` change**: flagged for
  fold/close — task included, action lives in decidesk's repo.

## Cross-Project Dependencies

- Depends on decidesk's `ori-adoption` change landing first (it defines the
  Popolo schema shape — `Meeting.meetingType`, `VotingRound.partyResults`,
  `GovernanceBody.bodyType`, etc. — this change's Mappings read from).
- Blocks decidesk's `OriController` retirement (companion change, decidesk
  repo) — that change depends on this one's parity test plan passing.
- Informs decidesk's `notubiz-ibabs-griffie-koppeling` change, which should
  fold into or reference the already-shipped `NotuBizConnectorService`/
  `IBabsConnectorService` rather than re-specifying them.

## Risks

### Risk 1: Single-item GET bypasses declarative filters entirely
**Severity:** High — **Mitigation:** `EndpointService::getObjects()`'s
id-branch calls `$mapper->find($id, ...)` directly with no filter/parameter
application, so `inputMapping`-injected filters (lifecycle, decisionType,
publish-window) never run on `/api/ori/v1/{resource}/{id}`. Today's
`OriController::show()` enforces `isLifecycleBlocked()`,
`narrowToDecisionType()`, and `isPayloadLive()` in PHP specifically for this
path. Documented as Gap 2 in design.md with a proposed minimal engine
addition (a declarative id-fetch filter check); the parity test plan's
single-item scenarios are the acceptance gate — this must be closed, not
silently dropped, before cutover.

### Risk 2: Response envelope shape has no output-reshaping hook on the register/schema GET path
**Severity:** Medium — **Mitigation:** `handleSchemaRequest()`'s GET branch
returns `{count, results, next, previous}` directly; ORI needs
`{@context, @type, count, items}`. A `mapping`-type after-rule *can* run
against the full response (confirmed via `processMappingRule`/`processRules`
before/after wiring), but whether one recipe can rename `results`→`items`,
inject fixed `@context`/`@type`, and per-item map the array in a single pass
needs implementation-time verification — documented as Gap 1 in design.md
with a two-rule fallback (list-mapping rule + envelope-mapping rule) if a
single recipe cannot do it.

### Risk 3: Anonymous RBAC propagation through openconnector unverified for the publish-window gate
**Severity:** Medium — **Mitigation:** decidesk's own code comments that OR's
`x-openregister-authorization` RBAC on `PublicationPayload` (publish-window
gate) already denies anonymous reads at the OR storage layer, independent of
caller. If that holds when the read is routed through openconnector's
`ObjectService`/mapper rather than decidesk's own controller, the RBAC-backed
part of Gap 2 is already covered "for free" and only the non-RBAC
lifecycle/decisionType gates remain a genuine gap. This must be verified
empirically during implementation (task in `tasks.md`), not assumed.

## Rollback Strategy

Config-only change (Endpoint/Mapping objects in `register.d`). Rollback is
deleting or disabling the ORI Endpoint objects — decidesk's existing
`OriController` is untouched by this proposal and keeps serving
`/api/ori/v1/*` throughout, so there is no cutover risk until the companion
decidesk change actually removes it. If the openconnector Endpoints are wired
to a *different* path prefix during validation (recommended — see
test-plan.md), rollback is simply not switching decidesk's public
`/api/ori/v1/*` mount over to them.

## Open Questions

- Does OR's RBAC on `PublicationPayload` (`authorization.read`) actually
  evaluate against an anonymous/public identity when the read is issued via
  openconnector's `ObjectService` rather than decidesk's own controller
  context? (Risk 3 — needs empirical verification, task added.)
- Can a single `mapping`-type after-rule express both list-mode per-item
  mapping and root-level envelope reshaping in one recipe, or does it need to
  be split into two chained after-rules? (Risk 2 — needs implementation-time
  verification, task added.)
- Should the single-item discriminator/lifecycle/publish-window gate (Risk 1)
  be closed by a new declarative rule type in openconnector, or by pushing
  `lifecycle`/`decisionType` into OR-level `x-openregister-authorization` on
  the decidesk schemas so OR enforces it unconditionally (mirroring how
  `PublicationPayload`'s publish-window already works)? The latter would also
  close Risk 1 for decidesk's *own* remaining direct API, not just ORI —
  worth a decision before implementation starts.
