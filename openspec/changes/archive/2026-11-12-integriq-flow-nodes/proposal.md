---
kind: code
depends_on: []
---

# Proposal: integriq-flow-nodes

## Summary

Integriq contributes **zero** node types to OpenRegister's flow engine
today. This change adds an `openconnector.source-call` flow node (plus a
companion `openconnector.synchronization-run` node) so an OpenRegister flow can
make an outbound API call through Integriq's governed machinery — a
configured Source, `CallService`, brokered credentials, source-level enable /
host / rate-limit guards — instead of not being able to make one at all. The
node never accepts a raw URL and never handles a secret: it names a Source, and
the Source governs the host and the credential.

## Motivation

The fleet architecture decision of 2026-07-27 (PO, hydra-console flows-first
wave) reads: *"API calls go through Integriq nodes; porting apps onto the
control plane creates no code, just flows."* That sentence describes a
capability that does not exist.

Verified against the checkout on 2026-07-27:

- A sweep of `integriq/lib` for `IFlowNode`, `RegisterFlowNodesEvent`,
  `RegisterFlowResolversEvent` and `IMcpToolProvider` returns **nothing**.
  Integriq registers no flow nodes and no flow resolvers.
- OpenRegister's built-in node set
  (`openregister/lib/Service/Flow/Nodes/`) is Filter, Loop, Merge, Router,
  SetFields, Stop, SubFlow, Switch, Wait — nine nodes, **none of which makes an
  HTTP call**.
- The only contributed node in the fleet is hermiq's
  `HermiqAgentNode` (`hermiq/lib/Flow/HermiqAgentNode.php`), which runs an agent
  turn, not a call.

So a flow today can compute *what* request should be made and can write the
answer onto an item, but it cannot make the request. Every app that wants an
outbound call must still ship imperative PHP — which is exactly the code the
"just flows" decision was meant to stop producing.

The first consumer is the hydra-console chain: a triage agentflow's terminal
step must flip a label on a forge issue by calling the configured forge Source.
Today that flow can only *record* the proposed label; the flip needs a human or
bespoke code. See hermiq's `hydra-console-agent-leaves` change (hermiq repo,
`openspec/changes/`), Risk 1 and its `nc-native-tools` delta, which states that
outbound commands are "Integriq-backed flow/endpoint territory". This
change is the territory being built.

The node is deliberately *not* the only intended user of this. Any flow in any
fleet app — synchronisation triggers, notification callbacks, enrichment from a
government register — becomes expressible without new PHP once a Source exists.

## Affected Projects

- [ ] Project: `integriq` — adds `lib/Flow/` with `SourceCallNode`,
      `SynchronizationRunNode` and `FlowNodeListener`; registers the listener in
      `AppInfo/Application.php` behind a `class_exists` guard; adds node icons.
- [ ] Project: `openregister` — **no code change required**. Consumes the
      existing `RegisterFlowNodesEvent` / `IFlowNode` / `FlowNodeRegistry`
      contract as-is. One upstream gap is *depended on but not fixed here*
      (see Cross-Project Dependencies).
- [ ] Project: `hermiq` — no code change; becomes the first consumer by adding
      an `openconnector.source-call` step to its triage flow document (the
      terminal label-write step of its triage agentflow, change
      `hydra-console-agent-leaves`). Tracked in the hermiq repo, not here.
      hermiq is the consumer that must accept `contract.md` before
      implementation starts.

## Scope

### In Scope

- **`openconnector.source-call` node** implementing
  `OCA\OpenRegister\Service\Flow\IFlowNode`. Config: a Source reference
  (UUID / slug / `reference`), an endpoint path *relative to the Source's
  `location`*, an HTTP method, query/body payloads that support
  `{{dotted.path}}` templating from the current item's `json`, and a response
  mapping that writes selected parts of the response back onto the item.
- **Execution model**: once per input item, returning one output item per input
  with a correct `pairedItem`, per OpenRegister's `FlowItems` contract.
- **Explicit failure semantics**: a call that fails is surfaced as item-level
  error state and/or a thrown exception that the engine's `onError` policy
  (`stop` / `continue` / `dead_letter`) resolves. Never a silently-empty result.
- **Fail-closed attribution**: the call executes as the flow run's owner
  (`context.triggeredBy`). A run with no resolvable owner is refused, loudly.
- **Governance passthrough**: the Source's own `isEnabled`, location/host and
  rate-limit guards, and the brokered-credential rules in `BrokeredCallService`,
  all apply unchanged because the node calls `CallService::call()` rather than
  an HTTP client.
- **`validateConfig()`** rejecting a step that names no Source or no endpoint,
  at flow-save time.
- **Scope declaration** via `isAvailableForScope()` using Nextcloud's
  `IManager::SCOPE_ADMIN` / `SCOPE_USER` constants, per OpenRegister's
  convention.
- **`openconnector.synchronization-run` node** — running a configured
  Synchronization as a flow step, emitting **one output item per synchronised
  object** (full fan-out, PO decision 2026-07-27) rather than a summary item, so
  the rest of the flow can act on what was synchronised. Bounded by an explicit
  `config.maxItems` ceiling (default 1000) that raises when exceeded and never
  truncates. Justified in design.md, Decision 5.
- **A node contract (`contract.md`)** — the cross-project interface is the node
  contract itself, not an HTTP surface: node ids, per-node config schema, item
  in/out shapes, error semantics and compatibility expectations, with hermiq as
  the named first consumer.

### Out of Scope

- **No MCP tools.** This change contributes flow nodes only. No
  `IMcpToolProvider` implementation, no tool manifest.
- **No raw-URL node.** There will be no node that accepts an arbitrary URL. If a
  host is worth calling it is worth having a Source, because the Source is where
  enablement, rate limiting and credentials live.
- **No new credential mechanics.** The node never reads, stores, renders or
  configures a secret. Whatever `authentication.credentialRef` the Source
  already declares is resolved by the existing broker path.
- **No changes to `CallService` semantics.** The node is a caller, not a
  refactor of the call engine.
- **No endpoint-dispatch (inbound) node.** Integriq Endpoints are the
  *inbound* surface; a flow calling its own instance's endpoint is deliberately
  deferred.
- **No flow-authoring UI work.** The node appears in OpenRegister's palette
  through the existing registry; bespoke config editors are deferred.

## Approach

Mirror the one proven contributed-node implementation in the fleet
(`hermiq/lib/Flow/HermiqAgentNode.php` + `HermiqFlowNodeListener.php`):

1. A listener on `RegisterFlowNodesEvent` registers the node objects. Both the
   listener registration and the node classes are guarded so that Integriq
   still boots on an instance where OpenRegister's flow engine is absent or
   older — the same `class_exists` posture hermiq uses.
2. The node resolves its Source the way Integriq already resolves Sources
   internally: as an OpenRegister object in register `openconnector`, schema
   `source` (see `SynchronizationService::findSourceObject()`), yielding the
   `ObjectEntity` that `CallService::call()` takes as its first argument.
3. The node renders its templated config against the item's `json`, calls
   `CallService::call(source:, endpoint:, method:, config:)`, reads the returned
   `CallLog` ObjectEntity, and either maps the response onto the item or raises.
4. Attribution is asserted **before** any call is attempted.

Deliberately *not* reimplemented (ADR-011): HTTP transport, Twig rendering,
retry/rate-limit, credential resolution, call logging — all already exist in
`CallService` / `BrokeredCallService` and are reached by delegation.

## New Dependencies

None. No new composer packages. The only new *coupling* is a soft, guarded
compile-time reference to OpenRegister's `IFlowNode` interface — OpenRegister is
already a hard runtime dependency of Integriq.

## Impact

- **New code**: `integriq/lib/Flow/` (nodes + listener), one listener
  registration line in `lib/AppInfo/Application.php`, node icons under `img/`.
- **No API surface change**: no new routes, no new controllers, no schema
  changes, no migrations.
- **Behavioural surface**: OpenRegister flow authors gain two new palette
  entries. Existing flows are unaffected.
- **Security surface**: outbound HTTP becomes reachable from a flow document.
  This is the point of the change and is why Source-only, owner-attributed and
  fail-closed are hard requirements rather than preferences.

## Cross-Project Dependencies

- **Depends on** OpenRegister's flow engine contract:
  `Service\Flow\IFlowNode`, `RegisterFlowNodesEvent`, `FlowNodeRegistry`,
  `FlowItems`, and `FlowEngine`'s `onError` policies. All exist in the checkout.
- **Depends on an unfixed upstream gap**: agent-dispatched flow runs are
  currently unattributed (ConductionNL/openregister#2158). This change does not
  fix it; it refuses to execute when `context.triggeredBy` yields no resolvable
  user, so the gap manifests as a loud failure rather than an anonymous
  outbound call. Closing #2158 is what makes agent-triggered flows able to call
  out at all.
- **Consumed by** hermiq's `hydra-console-agent-leaves` chain (first consumer).
  The interface it consumes is written in `contract.md` and MUST be accepted by
  hermiq before implementation starts.
- **Relies on a sibling change for the downstream half of the fan-out bound**:
  `or-flow-object-write-node` (openregister repo) carries a PO-mandated per-step
  write cap. `openconnector.synchronization-run`'s `config.maxItems` bounds how
  many items a run hands onward; that write cap bounds how many writes one
  downstream step performs. Neither substitutes for the other, and this change
  does not implement the write cap.
- **Affects** every app that would otherwise ship its own outbound-call PHP.

## Risks

### Risk 1: A flow document becomes a way to make authenticated outbound calls

**Severity:** High — **Mitigation:** Three independent constraints, all
enforced in the node: (a) the target is always a configured Source, so the host
and its enablement/rate limits are administrator-controlled and a flow author
cannot invent a destination; (b) the call runs as the flow run's owner and is
refused outright when no owner resolves (fail-closed), so there is no anonymous
or privilege-escalating path; (c) no secret is ever expressible in node config —
credentials come only from the Source's `credentialRef` via the existing broker,
which already rejects sibling embedded secrets.

### Risk 2: Copying HermiqAgentNode's error handling would produce green-but-dead runs

**Severity:** High — **Mitigation:** `HermiqAgentNode::execute()` catches
`Throwable` and substitutes an empty string, which makes a failed turn
indistinguishable from an empty answer and reports the run as successful. That
flaw is explicitly *not* copied: a failed call must produce explicit item-level
error state and honour `onError`. The spec states this as a requirement, and a
regression test asserts a failing call never yields a success-shaped item.
The upstream `IFlowNode` docblock already warns about exactly this
("Catching here defeats that policy and produces a run that reports success
while doing nothing") — the warning was simply not heeded in hermiq.

### Risk 3: Unattributed runs (openregister#2158) block the first consumer

**Severity:** Medium — **Mitigation:** Accepted and made visible. The node
fails closed, so hermiq's agent-triggered triage flow will fail loudly until
#2158 lands rather than calling a forge API as nobody. This is the intended
trade; the alternative (defaulting to an admin or to no user) is the failure
mode being avoided.

### Risk 4: The checkout lags the installed app

**Severity:** Medium — **Mitigation:** The checkout is `0.2.19` while the
installed app reports `0.3.3`. Requirements are written against contracts
(`IFlowNode`, `CallService::call()`'s named arguments, the Source
register/schema pair) rather than line numbers or version-specific internals,
and implementation must re-verify signatures against the deployed tree.
Documented in design.md.

### Risk 5: A per-item call turns a 500-item flow into 500 HTTP requests

**Severity:** Medium — **Mitigation:** This is the correct semantics for the
item model and matches every other node, so it is not "fixed" by batching. It
is bounded by the Source's own rate limiting (already enforced in
`CallService`) and by `FlowEngine`'s `MAX_TRANSITIONS` ceiling. Authors who want
one call per batch use a Merge/aggregate node upstream. Called out in design.md
so the behaviour is chosen, not discovered.

### Risk 6: A large synchronisation fans a flow run out into thousands of items

**Severity:** High — **Mitigation:** This is the direct cost of the PO's
fan-out decision (2026-07-27) and it is bounded rather than hoped away. A
10 000-object synchronisation would otherwise produce a 10 000-item flow run.
Four controls, of which the first two are load-bearing: (a) `config.maxItems`
on this node, default 1000, bounds how many items one run may hand onward;
(b) the per-step write cap in the sibling change `or-flow-object-write-node`
(openregister repo, PO-mandated) bounds how many writes a downstream step may
perform, so a raised ceiling cannot become a write amplifier; (c)
`FlowEngine::MAX_TRANSITIONS` bounds graph-walk depth; (d) a warning is logged
at 250 emitted items so growth toward the ceiling is visible before it starts
failing. Exceeding the ceiling **raises** — naming the count, the ceiling, the
step and the synchronization, and stating that the objects were synchronised and
only their emission was refused. It never truncates or samples: a shortened list
is indistinguishable from a complete one at every downstream step, which is the
same defect class as Risk 2.

### Risk 7: Node type-id collision across the fleet

**Severity:** Low — **Mitigation:** `FlowNodeRegistry::register()` refuses
collisions at registration rather than resolving them by load order, and ids are
app-namespaced (`openconnector.source-call`). The risk is a boot-time error,
not silent displacement.

## Rollback Strategy

Remove the listener registration from `lib/AppInfo/Application.php` (one line)
and ship. The nodes stop appearing in the palette immediately. Any flow document
still referencing `openconnector.source-call` fails at dispatch with an unknown
step type — noisy, contained to that flow, and reversible by re-adding the line.
No data is written, no schema is altered, no migration needs undoing, so
rollback is a pure code revert.

## Resolved Questions (PO review, 2026-07-27)

- **Should `openconnector.synchronization-run` ship in the same release as
  `source-call`, or land behind it?** **Confirmed:** specify both, sequence
  `source-call` first in `tasks.md` (Tasks 1–3 before Task 4).
- **Should a non-2xx HTTP status be an error by default, or opt-in per step?**
  **Confirmed:** non-2xx is an error by default, with an explicit
  `config.acceptStatuses` opt-out. Normative in the spec.
- **Should `synchronization-run` emit one item per synchronised object, or a
  summary item?** **Resolved — one item per synchronised object (full
  fan-out).** This *overrides* the earlier provisional. The large-run hazard it
  creates is Risk 6 above; the bounding controls are `config.maxItems` here and
  the sibling per-step write cap downstream.
- **Should `contract.md` be written?** **Resolved — yes.** This *overrides* the
  earlier "skipped" decision. The cross-project interface is the node contract
  itself rather than an HTTP surface; see `contract.md` and the Artifact
  Decisions table in `design.md`.

## Open Questions

None outstanding at the product level. One implementation-verification item
remains, recorded in `design.md`: the exact registered type string of
OpenRegister's built-in SetFields node, used by the seed flow.
