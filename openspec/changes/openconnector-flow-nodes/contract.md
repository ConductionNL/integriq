# Contract: openconnector-flow-nodes

## What kind of contract this is

**This is a node contract, not an HTTP contract.** The change adds no route, no
controller and no endpoint, so the template's endpoint/error-code/SLA shape does
not describe the interface that actually crosses a project boundary. What
crosses that boundary is the **flow node contract**: the node type ids another
app writes into a flow document, the configuration schema each node accepts, the
item shapes it consumes and emits, and what it does when something fails.

The template's sections are kept in order and adapted. Where a section does not
apply, it says so and says why, rather than being filled with plausible-looking
boilerplate. Two sections deviate substantially — **Endpoints** (replaced by
node interfaces) and **SLA** (no service of ours is being offered) — and each
carries its own note.

This document MUST be reviewed and accepted by the consuming projects listed
below before implementation starts.

Governing artifacts: `specs/flow-nodes/spec.md` (normative behaviour),
`design.md` (decisions and rationale), `proposal.md` (scope and risks). Where
this contract and the spec appear to disagree, **the spec is normative** and the
disagreement is a defect in this document.

## Consumers

| Project | What it consumes | Status |
| --- | --- | --- |
| `hermiq` | `openconnector.source-call` as the **terminal label-write step** of its triage agentflow — change `hydra-console-agent-leaves` (hermiq repo). This is the driving use case: the hydra-console chain can today only *record* a proposed label; the flip needs this node. | **First consumer. Acceptance required before implementation starts.** |
| `openregister` | Nothing produced by this change. It is the **host** of the contract, not a consumer: it owns `IFlowNode`, `RegisterFlowNodesEvent`, `FlowNodeRegistry`, `FlowItems` and `FlowEngine`, and this change implements against them unchanged. Listed so the direction of dependency is unambiguous. | Host — no acceptance needed, no code change requested. |
| Any fleet app authoring flows | Both node types, as palette entries in flow documents. No app-specific behaviour exists or will be added (see *Anti-goal*). | Downstream, no acceptance gate. |

**Anti-goal, contractually stated:** there is no consumer-specific branch in
either node — no "if the source is a forge, do X". A consumer needing different
behaviour changes its flow document or its Source. If that is impossible, the
declarative surface is missing something and the surface gets fixed; an `if`
does not get added.

## Node interfaces

*(Replaces the template's **Endpoints** section. There are no endpoints. The
unit of interface here is a node type: its id, its config schema, and the item
transformation it performs.)*

### Node `openconnector.source-call`

**Invoked by:** OpenRegister's `FlowStepDispatcher`, per step, per flow run.
Never by HTTP, never by another app directly.

**Identity / auth:** executes as the flow run's owner, taken from
`context['triggeredBy']`. There is no other authentication surface — see
*Attribution* below.

**Config schema:**

| Field | Type | Required | Meaning |
| --- | --- | --- | --- |
| `source` | string | yes | Source UUID, slug or `reference`. Resolved as an OpenRegister object in register `openconnector`, schema `source`. Never created if absent. |
| `endpoint` | string | yes | Path **relative to the Source's `location`**. Absolute URLs, scheme-relative (`//host`) and traversal (`../`) are rejected — at save time for the literal value and again at execute time for the rendered value. |
| `method` | string | yes | HTTP method from the supported set. |
| `query` | object | no | Query parameters. Values support `{{dotted.path}}`. |
| `body` | object | no | Request body. Values support `{{dotted.path}}`. |
| `headers` | object | no | Request headers. Values support `{{dotted.path}}`. No authentication header may be set here. |
| `acceptStatuses` | array of int | no | Non-2xx statuses to treat as success. Default empty: **any non-2xx is an error.** |
| `output` | string | no | Item key the response lands under. |
| `responseMapping` | object | no | `targetKey → selector`. Selector grammar: dotted path for the common case; a leading `$.` is treated as JSONPath. Behaviour is normative (selected response parts land under author-named keys); the exact grammar settles at implementation, and a deviation there is a spec update, not a silent divergence. |

**Forbidden config fields** — rejected at save time, not ignored:

| Field class | Why |
| --- | --- |
| Any URL / host / scheme / port | The Source is the only permitted target. A URL field would make a flow document an SSRF primitive. |
| Any token, password, API key, bearer or header-auth field | A flow document is far more widely readable than a Source. Credentials come only from the Source's `authentication.credentialRef` via the existing broker. |
| Any owner / user / run-as field | Naming the identity a call runs as would be an authoring-time privilege escalation. |

**Item input:** the standard `FlowItems` list — each item `{json, binary, pairedItem}`.

**Item output:** one output item per input item. `pairedItem` identifies the
producing input item. The response is written **only** under author-named keys
(`output` / `responseMapping`). Reserved `FlowItems` keys (`pairedItem`,
`binary`, `output`) are never overwritten from response content, so a remote
server cannot alter an item's provenance or routing.

**Cardinality:** one call per input item. An empty input list makes no call and
returns an empty list. Callers wanting a single call for a batch aggregate
upstream with a Merge node — this node will not fold N items into one request.

### Node `openconnector.synchronization-run`

**Config schema:**

| Field | Type | Required | Meaning |
| --- | --- | --- | --- |
| `synchronization` | string | yes | Reference to an already-configured Synchronization. Inline definitions are rejected; the node never creates one. |
| `force` | bool | no | Passed through to the existing synchronization service. |
| `output` | string | no | Item key the per-object result lands under. |
| `maxItems` | int | no | Fan-out ceiling. **Default 1000.** |

Forbidden config fields are the same as for `source-call`.

**Item output — the part consumers most need to plan for:** **one output item
per synchronised object** (full fan-out, PO decision 2026-07-27), not one
summary item. Each item carries the object payload and its per-object outcome
under `<output>`, the run counts under `<output>.summary`, and a `pairedItem`
pointing at the input item that triggered the run.

| Situation | Emitted |
| --- | --- |
| N objects synchronised, N ≤ `maxItems` | N items, one per object, each carrying `<output>.summary` |
| 0 objects synchronised | Exactly **one** summary-only item, marked `<output>.summaryOnly: true`, zero counts. Not zero items — "ran and found nothing" must stay distinguishable from "never ran". |
| N > `maxItems` | **Nothing. The node raises.** Never a truncated, sampled or paged subset. |

**Consumer-facing consequence of the ceiling:** the objects have already been
synchronised by the time the count is known, so a ceiling breach means the
synchronisation succeeded and only its *emission as flow items* was refused. The
error message says this explicitly; a consumer must not treat the failure as
"nothing synchronised" and re-run. At 250 emitted items a warning is logged, so
growth toward the ceiling is visible before it starts failing.

**Bounding is a two-part story and only half of it lives here.** `maxItems`
bounds how many items a run hands onward. What those items may *do* downstream
is bounded by the PO-mandated per-step write cap in the sibling change
`or-flow-object-write-node` (openregister repo). Neither substitutes for the
other: a raised `maxItems` without the write cap is a write amplifier; a write
cap without `maxItems` still lets a remote system's page count decide how large
a flow run is. Consumers planning a large synchronisation must account for both.

## Error semantics

*(Replaces the template's **Error Codes** table. These nodes return no status
codes of their own — they raise, and OpenRegister's `FlowEngine` applies the
step's `onError` policy: `stop`, `continue` or `dead_letter`.)*

| Condition | Node behaviour | What a consumer sees |
| --- | --- | --- |
| Response status outside 2xx and not in `acceptStatuses` | Raise | `onError` applies. Under `continue`, an item carrying explicit error state naming status, message, Source and endpoint. |
| Response status outside 2xx **and** listed in `acceptStatuses` | Success | The status is available on the item for a downstream step to branch on. |
| Transport failure (DNS, TLS, timeout, connection refused) | Raise — identical to a failed call | Never an empty response body presented as success. |
| Source reference resolves to nothing | Raise, naming the reference | No Source is created, no request is made. |
| Source disabled / rate-limited / location-guarded | The call engine's precondition guard refuses; the node surfaces the refusal as an error | Not a successful empty result. |
| Rendered endpoint escapes the Source location | Raise before any request | Applies to the rendered value, not just the literal one. |
| Run has no resolvable owner (`triggeredBy` absent, empty, or naming a deleted user) | Raise, naming the unattributed run | No request is made and no fallback identity is used. See *Attribution*. |
| Brokered credential cannot be resolved | Raise | Never falls back to an unauthenticated call. |
| Invalid configuration | `\UnexpectedValueException` at **flow-save time**, naming the offending field, message translated via `IL10N` | The flow is not persisted in that state. |
| `synchronization-run` fan-out exceeds `maxItems` | Raise, naming count, ceiling, step id and synchronization, and stating the objects *were* synchronised | No partial list. |

**The one guarantee consumers should hold this contract to:** a failure is never
presented as an empty success. The fleet's existing contributed node,
`HermiqAgentNode`, catches `Throwable` and substitutes an empty string, making a
failed turn indistinguishable from an empty answer while the run reports
success. That behaviour is forbidden here and a regression test asserts its
absence.

## Attribution

Called out separately because it is the term most likely to bite the first
consumer, and it **fails closed today**.

Every call and every synchronisation runs as the flow run's owner, from
`context['triggeredBy']`. When no owner resolves, the node **refuses** — no
fallback to an administrator, to the Source's creator, to a last known user, or
to no user.

Agent-dispatched flow runs are currently unattributed upstream
(**ConductionNL/openregister#2158**). Consequence for hermiq, stated plainly so
it is not discovered during integration: **the triage agentflow's terminal
label-write step will fail loudly until #2158 lands.** That is the intended
behaviour, not a defect to work around — the alternative is an anonymous
authenticated outbound call. Closing #2158 (passing `user:` when queueing) is
what makes agent-triggered flows able to call out at all. No configuration or
node change can unblock it, by design.

## Versioning

There is no API version to negotiate — a flow document names a node type id and
the registry either resolves it or does not. So versioning is expressed as
compatibility rules over the node ids and their config schemas:

- **Node type ids are the stable surface.** `openconnector.source-call` and
  `openconnector.synchronization-run` are permanent once shipped. They are
  app-namespaced, and `FlowNodeRegistry::register()` refuses a collision at
  registration rather than resolving it by load order — so a clash is a
  boot-time error, never a silent displacement.
- **Config fields are additive.** New optional fields may be added at any time.
  Required fields are not added to an existing node, and existing fields do not
  change type or meaning.
- **Defaults are part of the contract.** `acceptStatuses` (empty — non-2xx is an
  error) and `maxItems` (1000) may not be changed without following the breaking
  change policy below, because both change behaviour for flows that never set
  them.
- **Output shape is part of the contract.** Notably `synchronization-run`'s
  one-item-per-object fan-out, the summary-on-every-item rule, and the
  summary-only item for a zero-object run.
- **Removing a node type is breaking.** Rollback removes the registration and a
  referencing flow then fails at dispatch with an unknown step type — loud and
  contained to that flow, which is the correct failure for a removed step.
- **Not versioned:** the internals it delegates to. `CallService`'s behaviour is
  OpenConnector's own contract with itself; this change is a caller. The
  checkout lags the deployed app (0.2.19 vs 0.3.3), so implementers re-verify
  signatures against the deployed tree, and a behaviour change found in 0.3.x is
  a finding to raise, not a spec to force through.

## Breaking Change Policy

- A breaking change is: removing a node type id; adding a required config field;
  changing an existing field's type or meaning; changing a default that alters
  behaviour for flows that do not set it; or changing an output shape.
- Any such change is proposed as its **own OpenSpec change** with the consuming
  projects named in Affected Projects, and it is not merged before every named
  consumer has accepted it — the same gate this document is subject to.
- The first consumer (hermiq, `hydra-console-agent-leaves`) is notified through
  its own change; a breaking change opens an issue on the consuming repo rather
  than relying on the flow author noticing at run time.
- Where a breaking change is unavoidable, the preferred path is a **new node
  type id** alongside the old, not a mutation of the existing one. A flow
  document is data owned by whoever wrote it; changing what an id means edits
  their data from a distance.

## SLA

*(The template asks for response-time and availability commitments. Deviation,
stated rather than filled: there is no service here to be available. The node
runs in-process inside a flow run; if OpenConnector is installed and enabled,
the node exists, and if it is not, the flow fails at dispatch. Latency and
availability belong to the **remote host behind the Source**, which this project
neither operates nor speaks for.)*

What is committed instead, and is testable:

| Commitment | Value |
| --- | --- |
| Node overhead per item — resolution, templating, mapping | Under 5 ms for an item of ordinary size; end-to-end step time is dominated by the remote host. |
| Timeouts, retries, rate limiting | Owned by the Source and `CallService`. The node adds none and bypasses none. |
| Auditability | Every flow-originated call writes the same CallLog an ordinary OpenConnector call writes. A flow-originated call is auditable by exactly the same means. |
| Memory ceiling for a fanned-out run | Bounded by `config.maxItems` (default 1000), an author-visible number — not by the remote system's page count. |

Explicitly **not** committed: uptime, throughput, or response time of any Source
a flow targets.
