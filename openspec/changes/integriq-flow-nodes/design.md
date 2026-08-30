# Design: integriq-flow-nodes

## Architecture Overview

OpenRegister owns the fleet's one flow engine (ADR-065). Apps do not run graphs;
they *contribute node types* to it and let the engine walk the graph. This
change makes Integriq one of those contributors.

```
                     OpenRegister flow engine
  ┌───────────────────────────────────────────────────────────────┐
  │ FlowRunService ──▶ FlowEngine ──▶ FlowStepDispatcher          │
  │                       │                    │                  │
  │                       │            FlowNodeRegistry           │
  │                       │              ▲          ▲             │
  └───────────────────────┼──────────────┼──────────┼─────────────┘
                          │              │          │
             built-ins ───┘   RegisterFlowNodesEvent │
      (Filter/Loop/Merge/           │                │
       Router/SetFields/            │                │
       Stop/SubFlow/Switch/         │                │
       Wait — none call out)        │                │
                                    │                │
                    hermiq ─────────┘                │
              HermiqAgentNode                        │
                                                     │
                    integriq ────────────────────────┘   ← THIS CHANGE
                      FlowNodeListener
                        ├── SourceCallNode          (openconnector.source-call)
                        └── SynchronizationRunNode  (openconnector.synchronization-run)
                                    │
                                    ▼
                       CallService::call(source, endpoint, method, config)
                         ├── guardCallPreconditions()  isEnabled / location / rate limit
                         ├── mergeSourceConfiguration()
                         ├── resolveBrokeredDispatch() credentialRef → OR credential broker
                         ├── normaliseRequestConfig()  Twig, headers, certs, auth-key strip
                         └── → CallLog ObjectEntity
```

**Current state, verified in the checkout on 2026-07-27:**

| Question | Answer |
| --- | --- |
| Does `integriq/lib` reference `IFlowNode`? | No — grep for `IFlowNode`, `RegisterFlowNodesEvent`, `RegisterFlowResolversEvent`, `IMcpToolProvider` returns zero hits. |
| Do OpenRegister's built-in nodes make HTTP calls? | No — `Nodes/` is Filter, Loop, Merge, Router, SetFields, Stop, SubFlow, Switch, Wait. |
| Is there a reference contributed node? | Yes — `hermiq/lib/Flow/HermiqAgentNode.php` + `HermiqFlowNodeListener.php`. |

So "API calls go through Integriq nodes" describes nothing that exists.
This design builds it.

### Version drift in this checkout

The `integriq` working copy is on branch `chore/ncvue-beta190` at
`info.xml` version **0.2.19**, while the app installed on the dev instance
reports **0.3.3** (`occ app:list`). OpenRegister's checkout reads
`0.2.17-unstable.17`. The checkout therefore **lags the deployed app by at least
one minor version**, and internals may have moved.

Consequences that shape this document, deliberately:

- Requirements are stated against **contracts**, never against line numbers or
  private helpers: the `IFlowNode` method set; `CallService::call()`'s **named**
  arguments (`source:`, `endpoint:`, `method:`, `config:`); the Source living as
  an OpenRegister object in register `openconnector`, schema `source`; the
  `FlowItems` `{json, binary, pairedItem}` shape; `FlowEngine`'s `onError`
  values `stop` / `continue` / `dead_letter`.
- Where this design names a specific helper
  (`SynchronizationService::findSourceObject()`,
  `BrokeredCallService::hasCredentialRef()`) it does so as *evidence that the
  capability exists*, not as a mandated call site. The implementer MUST
  re-verify the signature against the deployed tree before wiring, and if a
  private helper has moved, extract or re-resolve rather than reproduce it.
- Any behaviour this design asserts about `CallService` that turns out to have
  changed in 0.3.x is a finding to raise, not a spec to force through.

## Nextcloud Integration

- **Controllers:** none. This change adds no routes and no controllers.
- **Services:**
  - `OCA\Integriq\Service\CallService` — the outbound call, unchanged.
  - `OCA\Integriq\Service\BrokeredCallService` — reached transitively;
    never called directly by a node.
  - `OCA\OpenRegister\Service\ObjectService` — Source lookup (register
    `openconnector`, schema `source`).
  - `OCA\Integriq\Service\SynchronizationService` — for the second node.
- **Mappers/Entities:** none new. Sources and CallLogs are OpenRegister
  `ObjectEntity` rows (ADR-022: no app-local reimplementation).
- **Events/Hooks:**
  - Listener on `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent`,
    registered in `lib/AppInfo/Application.php`.
  - Registration is guarded with `class_exists(RegisterFlowNodesEvent::class)`
    so Integriq still boots when OpenRegister's flow engine is absent or
    predates it — the same posture as `HermiqFlowNodeListener`.
- **OCP interfaces used:** `IEventListener`, `IL10N`, `IURLGenerator`,
  `OCP\WorkflowEngine\IManager` (for the `SCOPE_ADMIN` / `SCOPE_USER`
  constants that `isAvailableForScope()` takes), `IUserManager` (owner
  resolution), `Psr\Log\LoggerInterface`.

## Security Considerations

This change makes authenticated outbound HTTP reachable from a flow document.
That is the whole point, and it is also the entire risk surface. Four controls,
each load-bearing:

1. **Source-only targeting.** The node config has no URL field. It names a
   Source; the endpoint is a path *relative to* the Source's `location`. A flow
   author cannot invent a destination, and the administrator who owns the Source
   owns the host. The Source's `isEnabled`, location guard and rate limits are
   enforced inside `CallService::guardCallPreconditions()` before any socket
   opens. Endpoint paths are normalised and MUST NOT be able to escape the
   Source's location by traversal (`../`) or by supplying an absolute URL or
   scheme-relative path (`//evil.example`); such an endpoint is rejected in
   `validateConfig()` and again at execute time, because a templated
   `{{...}}` value can only be checked at execute time.
2. **Fail-closed attribution.** Every call runs as the flow run's owner, taken
   from `context['triggeredBy']`. When that is empty, or names a user that
   `IUserManager` cannot resolve, the node **throws** — it does not fall back to
   an admin, to the Source's creator, or to no user. Upstream gap
   ConductionNL/openregister#2158 (agent-dispatched runs are unattributed) means
   this refusal will actually fire today for agent-triggered flows. That is the
   correct visible symptom of an unfixed gap; executing anonymously would hide
   it. Node config MUST NOT offer an owner override — a `config.owner` field
   would be an authoring-time privilege escalation, which is precisely why
   `HermiqAgentNode`'s `$config['owner'] ?? $context['triggeredBy']` fallback is
   not copied.
3. **No secret is expressible in node config.** There is no token, header-auth
   or basic-auth field. Credentials come from the Source's
   `authentication.credentialRef`, resolved by the existing broker, which
   already rejects embedded secrets sitting alongside a `credentialRef`. Node
   config is stored in a flow document, which is far more widely readable than
   a Source — so a secret field there would be a leak by construction.
4. **Response handling does not become an injection vector.** The response is
   written to the item under author-named keys only. The node MUST NOT merge a
   response wholesale into `json` at the root when that would let a remote
   server overwrite control keys (`pairedItem`, `output`, `binary`) or
   attribution fields. Root merges write only into `json` and skip reserved
   `FlowItems` keys.

Input validation: `validateConfig()` runs at flow-save time and rejects a
missing/blank Source reference, a missing/blank endpoint, an unsupported method,
and a malformed `acceptStatuses`. CSRF/CORS: not applicable — no HTTP surface is
added.

## File Structure

```
integriq/
  lib/
    AppInfo/
      Application.php                 # MODIFIED: +1 guarded listener registration
    Flow/
      FlowNodeListener.php            # NEW: registers both nodes on RegisterFlowNodesEvent
      SourceCallNode.php              # NEW: openconnector.source-call
      SynchronizationRunNode.php      # NEW: openconnector.synchronization-run
      FlowTemplate.php                # NEW: {{dotted.path}} rendering against an item's json
      FlowOwner.php                   # NEW: fail-closed owner resolution from run context
  img/
    flow-source-call.svg              # NEW: palette icon
    flow-synchronization-run.svg      # NEW: palette icon
  tests/
    Unit/Flow/
      SourceCallNodeTest.php          # NEW
      SynchronizationRunNodeTest.php  # NEW
      FlowTemplateTest.php            # NEW
```

`FlowTemplate` is a thin, node-local renderer over the item's `json` for
`{{dotted.path}}` placeholders. Per ADR-011 it does **not** reimplement Twig:
`CallService` already renders the *Source's own* configuration through Twig
(`ArrayLoader`, `renderConfiguration()`) with the *source data* as context —
a different context and a different stage. See
`OCA\Integriq\Service\CallService::renderConfiguration()` for the
canonical source-config rendering; `FlowTemplate` covers the item-to-request
substitution that has no existing implementation. If a shared item-templating
helper lands in OpenRegister's flow package, `FlowTemplate` MUST be deleted in
favour of it.

## Decisions

### Decision 1: Source reference, never a URL

**Chosen:** node config carries `source` (UUID, slug, or `reference`) plus a
relative `endpoint`.

**Alternative considered — a `http-request` node taking a URL.** Rejected. It
would be trivially more convenient and would destroy every property that makes
this worth building: no administrator-controlled host list, no rate limiting, no
credential broker, no call log, and a flow document that can be edited into an
SSRF primitive. If a host is worth calling from a flow, it is worth having a
Source. This is a hard boundary, restated in the proposal's Out of Scope.

**Alternative considered — resolve by `location` (URL) with find-or-create,** as
`SynchronizationService::findOrCreateSourceByLocation()` does. Rejected for the
same reason: find-or-create from a flow document *is* a raw-URL node wearing a
Source's clothes.

### Decision 2: Errors are explicit; the HermiqAgentNode pattern is a bug, not a template

**Chosen:** a failed call raises, so `FlowEngine::onError` decides
(`stop` / `continue` / `dead_letter`). Under `continue`, the item that failed
carries explicit error state (`__error` with `status`, `message`, `source`,
`endpoint`) and MUST NOT be shaped like a success.

**Alternative considered — copy `HermiqAgentNode`.** Its `execute()` does:

```php
try   { $answer = $this->scheduleService->runAgentAsOwner(...); }
catch (Throwable $e) { $answer = ''; }
```

An empty string is then written to the output key. A failed turn is thereby
indistinguishable from an empty answer, the run reports success, and the flow
continues on fabricated data. OpenRegister's own `IFlowNode` docblock warns
against exactly this — *"Catching here defeats that policy and produces a run
that reports success while doing nothing"* — and hermiq did it anyway. Rejected,
explicitly, and asserted against by test.

Non-2xx is an error by default. An author who genuinely wants to branch on a
404 sets `acceptStatuses: [200, 404]`, which makes the intent visible in the
flow document instead of buried in downstream conditionals.

### Decision 3: One call per item

**Chosen:** `execute()` loops the input items and calls once per item, emitting
one output item per input with `pairedItem` pointing back.

**Alternative considered — one call for the whole batch.** Rejected: it breaks
the item model every other node obeys, and there is no general way to fold N
items into one request body. Authors who want a single call aggregate upstream
with a Merge node — an explicit, visible step. The cost (a 500-item flow makes
500 requests) is bounded by the Source's own rate limiting and by
`FlowEngine::MAX_TRANSITIONS`, and is stated in the spec so it is chosen rather
than discovered in production.

### Decision 4: Ship a second node, `openconnector.synchronization-run`

**Chosen:** yes, specified here, sequenced after `source-call`.

**Rationale.** `source-call` covers "make one request and put the answer on the
item". It does **not** cover Integriq's other governed outbound capability:
running a configured Synchronization — pagination across pages, mapping,
contract/state tracking, `SynchronizationLog`. Expressing that as a chain of
`source-call` steps would require the flow author to re-implement pagination and
mapping in flow nodes, which is the "no code, just flows" promise inverted into
"lots of flow, reimplementing code". The synchronisation is already configured
and already governed; a node that names one and runs it is a thin, honest
wrapper over `SynchronizationService::synchronize()`.

**Alternative considered — omit it, let `source-call` cover everything.**
Rejected for the reason above. **Alternative considered — a node for inbound
Endpoint dispatch.** Rejected and left out of scope: Endpoints are the *inbound*
surface, and a flow calling its own instance's endpoint is a loop waiting to
happen with no demonstrated need.

Both nodes share owner resolution, error semantics and validation, so the second
is small once the first exists.

### Decision 5: `synchronization-run` fans out one item per synchronised object

**Chosen (PO review, 2026-07-27 — overrides the earlier provisional):** the node
emits **one output item per synchronised object**, not one summary item per
input item. Every emitted item carries the object's payload, its per-object
outcome, the run's counts under `<output>.summary`, and a `pairedItem` pointing
back at the input item that triggered the run.

**Why the earlier provisional was wrong.** "One summary item with counts" is the
shape that reads safest and is the least useful: a flow that has just
synchronised 400 objects cannot act on any of them. Every downstream use —
route the new ones, write a field on the changed ones, notify on the failed ones
— would have to re-read what the node already had in hand, which is both a
second round trip and a race. The item model exists precisely so a step can hand
its results onward; collapsing to a summary opts out of it. The counts are not
lost: they ride on every item.

**The cost, stated rather than discovered.** A synchronisation of 10 000 objects
would produce a 10 000-item flow run. That is a real hazard — memory, run
duration, and a downstream write step turning into 10 000 writes.

**How it is bounded, honestly:**

| Control | Where | What it bounds |
| --- | --- | --- |
| `config.maxItems` (default 1000) | this node | how many items one run may hand onward |
| Per-step write cap (PO-mandated) | `or-flow-object-write-node`, openregister repo | how many writes one downstream step may perform |
| `FlowEngine::MAX_TRANSITIONS` | openregister | graph-walk depth, not per-item fan-out |
| Source rate limiting | `CallService` | outbound call rate, not item count |

The first two are the load-bearing pair and they are deliberately separate:
`maxItems` bounds the *list*, the write cap bounds the *effect*. Neither
substitutes for the other — a raised `maxItems` without the write cap is a write
amplifier, and a write cap without `maxItems` still lets a remote system's page
count decide how large a flow run is.

**Exceeding the ceiling raises; it never truncates.** Silent truncation is not
on the table under any framing: a shortened list is indistinguishable from a
complete one at every downstream step, which is the same defect class as
`HermiqAgentNode`'s empty-string-on-failure (Decision 2). The node raises,
naming the actual count, the ceiling, the step and the synchronization, and
`onError` decides. Because the synchronisation has already run by the time the
count is known, the error message must also say that the objects *were*
synchronised and only their emission as flow items was refused — otherwise an
author reads a failed step as a failed sync and re-runs it.

**A warning below the ceiling.** At 250 emitted items the node logs a warning
naming the count, step and synchronization. A run that is growing toward its
ceiling should be visible before the day it starts failing.

**Zero objects emits one summary-only item, not nothing.** An empty list would
make "ran, found nothing" indistinguishable from "never ran", and the downstream
half of the flow would silently do nothing while the run reported success. The
summary-only item is explicitly marked (`summaryOnly: true`) so it is not
mistaken for a synchronised object.

**Alternative considered — one summary item plus an opt-in `fanOut: true`.**
Rejected: it makes the useful shape the one nobody discovers, and it doubles the
output contract for every consumer of the node.

### Decision 6: Guarded registration, so Integriq still boots without the flow engine

**Chosen:** `class_exists()` guard around listener registration in
`Application.php`, mirroring `HermiqFlowNodeListener`. OpenRegister is a hard
runtime dependency of Integriq, but its *flow engine* is a newer, moving
surface — and the version drift documented above is exactly the situation where
an unguarded compile-time reference to a class that is not there turns a missing
node into a dead app.

## Declarative vs Imperative

ADR-031 pushes the fleet toward declarative configuration: behaviour lives in
schemas, registers, manifests and flow documents, not in per-app PHP. This
change adds imperative PHP. That needs justifying, and ADR-031 lists the
exception it falls under.

**Why this code is the listed exception — external integration.** Declarative
artefacts describe *what* should happen inside the system. Speaking HTTP to a
system outside it — connection handling, retries, rate-limit headers, auth
schemes, certificates, response decoding — is irreducibly imperative. It cannot
be expressed as configuration because it *is* the boundary at which
configuration stops. ADR-031's external-integration exception exists precisely
for this class of code, and Integriq is the app the fleet already
designated to own it.

**Why the exception stays narrow.** The imperative surface added here is
deliberately small and sits at the boundary only:

| Layer | Imperative or declarative | Owner |
| --- | --- | --- |
| Which host, which auth, which rate limit | **Declarative** — a Source object | administrator |
| Which credential | **Declarative** — `credentialRef` → broker | administrator |
| Which endpoint, method, payload, mapping | **Declarative** — node config in the flow document | flow author |
| When it runs, what precedes/follows it, what happens on failure | **Declarative** — the flow document's graph and `onError` | flow author |
| Speaking HTTP | **Imperative** — `CallService`, already existing | Integriq |
| Adapting HTTP to the item model | **Imperative** — this change, ~2 classes | Integriq |

Everything *consuming* this stays declarative. hydra-console's triage flow gets
no new PHP: it gains a step in a JSON flow document. The same holds for every
subsequent consumer — which is the "porting apps onto the control plane creates
no code, just flows" claim actually becoming true, rather than being asserted.

**The anti-goal.** This change must not become a place where per-consumer
behaviour accretes. There is no consumer-specific branch in the node, no "if the
source is a forge, do X". A consumer that needs different behaviour changes its
flow document or its Source. If a change request cannot be satisfied that way,
that is a signal the declarative surface is missing something — fix the surface,
do not add an `if`.

## Seed Data

This change introduces no new OpenRegister schema, so there is no new schema to
seed. What it does need on install is **something to run** — a node with no
configured Source and no example flow is untestable on a fresh instance
(ADR-016 / ADR-001). Seed data therefore consists of one demo Source, one demo
flow that calls it, and their placeholder identifiers.

All UUIDs below are nil-UUID placeholders and MUST be replaced with generated
values at seed time. No real hosts, no real tokens.

### Schema: `source` (existing — register `openconnector`)

| Field | Object 1 | Object 2 | Object 3 |
| --- | --- | --- | --- |
| `slug` | `demo-echo-api` | `demo-forge-api` | `demo-registry-api` |
| `id` | `00000000-0000-0000-0000-000000000000` | `00000000-0000-0000-0000-000000000000` | `00000000-0000-0000-0000-000000000000` |
| `name` | Demo Echo API | Demo Forge API | Demo Registry API |
| `description` | Public echo endpoint used to demonstrate a flow making an outbound call. | Issue tracker used by the flow-node demo to update a label. | Read-only reference register used to enrich an item. |
| `location` | `https://echo.example.org` | `https://forge.example.org/api/v1` | `https://registry.example.org/api` |
| `type` | `json` | `json` | `json` |
| `isEnabled` | `true` | `false` (enable after configuring a credential) | `true` |
| `auth` | `none` | `none` (see `authentication.credentialRef` below) | `none` |
| `configuration.authentication.credentialRef` | — | `{"credentialName": "demo-forge-token"}` | — |
| `@self.register` | `openconnector` | `openconnector` | `openconnector` |
| `@self.schema` | `source` | `source` | `source` |

Object 2 ships **disabled on purpose**: it demonstrates the brokered-credential
path, and an enabled Source pointing at a placeholder host with an unresolvable
`credentialRef` would produce confusing failures on a fresh install. Its
credential is a *reference by name* to a broker entry an administrator creates;
the seed never contains a token. Where a token is shown in documentation it is
written `YOUR_TOKEN_HERE`.

**Related items per object:**

- Files: none.
- Notes: each seeded Source carries a description explaining it is demo data and
  safe to delete.
- Tasks: none.
- Contacts: none.

### Example node configuration — `openconnector.source-call`

Minimal (Object 1, no credential, no templating):

```json
{
  "id": "step-echo",
  "type": "openconnector.source-call",
  "config": {
    "source": "demo-echo-api",
    "endpoint": "/get",
    "method": "GET",
    "output": "echo"
  }
}
```

Templated request + response mapping + explicit error policy (Object 2):

```json
{
  "id": "step-apply-label",
  "type": "openconnector.source-call",
  "onError": "dead_letter",
  "config": {
    "source": "demo-forge-api",
    "endpoint": "/issues/{{issue.number}}/labels",
    "method": "POST",
    "body": { "labels": ["{{triage.proposedLabel}}"] },
    "headers": { "Accept": "application/json" },
    "acceptStatuses": [200, 201],
    "output": "labelResult",
    "responseMapping": {
      "labelResult.applied": "$.labels[*].name",
      "labelResult.status": "$.status"
    }
  }
}
```

Read + enrich, tolerating a missing record (Object 3):

```json
{
  "id": "step-enrich",
  "type": "openconnector.source-call",
  "onError": "continue",
  "config": {
    "source": "demo-registry-api",
    "endpoint": "/organisations/{{organisation.kvk}}",
    "method": "GET",
    "acceptStatuses": [200, 404],
    "output": "organisationDetails"
  }
}
```

### Example node configuration — `openconnector.synchronization-run`

```json
{
  "id": "step-sync",
  "type": "openconnector.synchronization-run",
  "onError": "stop",
  "config": {
    "synchronization": "00000000-0000-0000-0000-000000000000",
    "force": false,
    "output": "syncResult",
    "maxItems": 1000
  }
}
```

`maxItems` is written out here even though 1000 is the default, because the
number decides how large a run this step may produce and a seed example is where
an author learns the field exists. The step emits one item per synchronised
object; a run over the ceiling raises rather than truncating (Decision 5).

### Example flow document using the node

Seeded as a demo flow so the capability is exercisable on install. Trigger is
manual so nothing fires by itself.

```json
{
  "@self": { "register": "openregister", "schema": "flow", "slug": "demo-outbound-call" },
  "id": "00000000-0000-0000-0000-000000000000",
  "name": "Demo — call an API through Integriq",
  "description": "Shows a flow making a governed outbound call. Safe to delete.",
  "trigger": { "type": "manual" },
  "steps": [
    {
      "id": "step-seed",
      "type": "openregister.set-fields",
      "config": { "fields": { "issue": { "number": 1 }, "triage": { "proposedLabel": "needs-triage" } } },
      "next": ["step-echo"]
    },
    {
      "id": "step-echo",
      "type": "openconnector.source-call",
      "onError": "stop",
      "config": {
        "source": "demo-echo-api",
        "endpoint": "/get",
        "method": "GET",
        "query": { "issue": "{{issue.number}}" },
        "output": "echo"
      },
      "next": ["step-report"]
    },
    {
      "id": "step-report",
      "type": "openregister.set-fields",
      "config": { "fields": { "summary": "called echo for issue {{issue.number}}" } },
      "next": []
    }
  ]
}
```

Running it on a fresh instance is the acceptance test for "the node exists, is
in the palette, resolves a Source, makes a call, and puts the answer on the
item". Note the step id `openregister.set-fields` is written as the built-in
`SetFieldsNode` registers itself; the implementer MUST confirm the exact
registered type string against `FlowNodeRegistry` in the deployed tree and
correct the seed if it differs.

## Migration Plan

No data migration. No schema change. No `Version*Date*.php`.

**Deploy:** ship the new `lib/Flow/` classes plus the guarded listener
registration. On the next `RegisterFlowNodesEvent` dispatch, the nodes appear in
the palette. Existing flows are untouched. Seed data lands through the normal
register-import path (note: a register change needs a **forced** import to apply
to existing schemas).

**Rollback:** remove the listener registration line and ship. Flow documents
that reference `openconnector.source-call` then fail at dispatch with an unknown
step type — loud, contained to that flow, and reversed by re-adding the line.
Nothing to undo in the database.

**Ordering:** `source-call` first with tests and a live run against the demo
Source; `synchronization-run` after it, reusing the shared owner/error helpers.

## Trade-offs

| Trade-off | Accepted because |
| --- | --- |
| Per-item calls make N requests | Correct for the item model; bounded by Source rate limits; aggregation is an explicit upstream node. |
| No raw-URL escape hatch | Every governance property depends on the Source being the target. Convenience here costs the whole point. |
| Fail-closed attribution blocks agent-triggered flows until openregister#2158 lands | A loud failure is strictly better than an anonymous authenticated outbound call. The gap becomes visible instead of tolerated. |
| A second node (`synchronization-run`) widens scope | Small once the first exists, and it prevents authors from re-implementing pagination and mapping in flow steps. |
| Node-local `{{dotted.path}}` renderer | No existing helper covers item→request substitution; scoped to deletion if OpenRegister ships one. |
| `synchronization-run` fan-out can make a run very large | The alternative (a summary item) makes the synchronised objects unreachable to the rest of the flow. Bounded by `config.maxItems` here and by `or-flow-object-write-node`'s per-step write cap downstream; over the ceiling the node raises, never truncates. |

## Resolved Questions (PO review, 2026-07-27)

- **Should `synchronization-run` emit one item per synchronised object, or one
  summary item?** **Resolved: one item per synchronised object — full fan-out.**
  Overrides the earlier provisional ("one summary item with counts"). Rationale,
  the large-run hazard and the two-control bounding story are in Decision 5;
  the normative behaviour is the fan-out requirement in
  `specs/flow-nodes/spec.md`.
- **Should `synchronization-run` ship in the same release as `source-call`?**
  **Confirmed:** both are specified here, `source-call` sequenced first in
  `tasks.md` (Tasks 1–3 before Task 4).
- **Should a non-2xx HTTP status be an error by default?** **Confirmed:** yes,
  with an explicit `config.acceptStatuses` opt-out. Stated normatively in the
  spec's failure requirement.
- **Response mapping syntax.** **Confirmed:** dotted paths for the common case,
  a leading `$.` treated as JSONPath, so the simple case needs no new grammar.
  The requirement stays stated as *behaviour* (selected response parts land
  under author-named keys); the exact grammar settles at implementation and a
  deviation there is a spec update, not a silent divergence.
- **`spec_ref` paths in `tasks.md`.** **Confirmed:** they stay pointed at the
  change directory (`openspec/changes/integriq-flow-nodes/specs/...`) until
  this change is archived, at which point they move to the canonical
  `openspec/specs/` home.

## Open Questions

- **Exact registered type string of OpenRegister's built-in SetFields node** —
  used in the seed flow. *Provisional:* `openregister.set-fields`; verify
  against `FlowNodeRegistry` in the deployed tree before seeding. This is an
  implementation verification item, not a product question.

## Artifact Decisions

Three optional artifacts in this schema were deliberately not written, and one
(`contract.md`) was written after the PO review of 2026-07-27. Recorded here so
the omissions read as decisions rather than gaps.

| Artifact | Decision | Reason |
| --- | --- | --- |
| `contract.md` | **Written** | Originally skipped on the grounds that the template documents HTTP endpoints and an SLA, and this change adds no HTTP surface. Overridden by the PO on 2026-07-27: the cross-project interface here is the **node contract itself** — node ids, per-node config schema, item in/out shapes, error semantics, versioning — and it has a named first consumer (hermiq's `hydra-console-agent-leaves`, whose triage agentflow's terminal label-write step calls `openconnector.source-call`). The artifact adapts the template to a non-HTTP contract and states per section where and why it deviates, rather than filling endpoint/SLA boilerplate that does not apply. |
| `discovery.md` | Skipped | Discovery exists to resolve uncertainty about API availability or feasibility before committing to a spec. That research was already done and its result is recorded verbatim: the `IFlowNode` / `RegisterFlowNodesEvent` / `FlowNodeRegistry` contract exists and is in use by hermiq; Integriq contributes nothing; none of the nine built-in nodes calls out. See the verification table in Architecture Overview and the Motivation section of `proposal.md`. There is no open feasibility question left to time-box. |
| `migration.md` | Skipped | No schema change, no new table or column, no `Version*Date*.php`. The change is additive code plus seed data. Deployment and rollback are documented in the Migration Plan section above and in `proposal.md`'s Rollback Strategy. |
| `test-plan.md` | Skipped | Its job — pre-defining what "done" means by mapping scenarios to test cases — is already served without duplication: `specs/flow-nodes/spec.md` carries 32 GIVEN/WHEN/THEN scenarios across 9 requirements and a 28-item Acceptance Criteria list, and every task in `tasks.md` carries its own acceptance criteria plus an `- [ ] Test` gate. A separate mapping document would restate those and then drift from them. |
