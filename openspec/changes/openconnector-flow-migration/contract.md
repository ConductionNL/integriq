# Contract: openconnector-flow-migration — `openconnector.synchronization` leaf

> This is a **flow-leaf contract**, not an HTTP API. A leaf is a node an app
> contributes to the OpenRegister flow engine by implementing
> `OCA\OpenRegister\Service\Flow\IFlowNode` and registering it via
> `RegisterFlowNodesEvent`. Its "surface" is the node **id**, its **config**
> schema (validated at editor save-time), and its **item-list** input/output —
> the flow engine's data channel `{json, binary, pairedItem}`. Per the new ADR
> established by this change, every leaf an app ships MUST declare a contract in
> this shape so downstream flow authors know how to wire it.

## Consumers
- `openregister` (the flow engine): invokes the leaf through `FlowNodeRegistry` →
  `RegistryStepDispatcher::dispatch()` → `IFlowNode::execute()`. It is the only
  caller; flow **authors** consume the leaf indirectly by placing its id on an
  edge `type`.
- `openconnector` (owner): registers and implements the leaf. No other app calls
  it directly.

## Leaf surface

### Node id
`openconnector.synchronization` — unique across the fleet (namespaced with the
app id). A duplicate id is **refused** at registration by `FlowNodeRegistry`
(logged, not overwritten); it is never resolved by load order.

### Config (validated at save-time by `validateConfig()`)
```json
{
  "synchronizationId": "<synchronization-object-uuid>",
  "force": false
}
```
- `synchronizationId` (string, **required**) — the id/uuid of an OpenConnector
  Synchronization object (stored in the OpenRegister `openconnector` register,
  schema `synchronization`). Empty/missing → `\UnexpectedValueException` at save.
- `force` (bool, optional, default `false`) — bypasses contract-hash change
  detection, mirroring the existing `force` flag on
  `SynchronizationService::synchronize()`.

### Input (items in)
The engine passes the run's current item list. The synchronization leaf is a
**source-style** node: it does not require upstream items and ignores their
contents in Phase 1 (the sync is defined entirely by `synchronizationId`). It
receives `array $items, array $config, array $context` per `IFlowNode::execute`.
`$context` is run-level metadata (trigger, run id, subject) — **not** the data
channel.

### Output (items out)
A well-formed item list `[{ "json": {…}, "binary": {…}, "pairedItem": {…}|null }]`
describing the synchronisation result (per-object outcomes / summary), normalised
via `FlowItems::normalise()`. An empty list is legal and ends the branch.

## Error behaviour
| Condition | Behaviour |
|-----------|-----------|
| `synchronizationId` missing/empty at save | `validateConfig()` throws `\UnexpectedValueException` (editor-time) |
| Referenced synchronisation not found at run | `execute()` lets the resolution exception **propagate** to the engine — the engine reads the edge `onError` (`stop`/`continue`/`dead_letter`); the leaf does NOT swallow it |
| `SynchronizationService` / OpenRegister absent | Registration is `class_exists()`-guarded and no-ops; a flow referencing the leaf simply is not offered. `execute()` is never reached in that state |
| Upstream rate-limit (`X-RateLimit-Reset`) | Propagates; Phase 2 models this as an `openregister.wait` (suspend-until) edge |

## Versioning
The node id `openconnector.synchronization` is the stable contract key. The
config schema is additive-only: new optional keys may be added; existing keys are
never repurposed or made required without a new node id. The item-list envelope
shape `{json, binary, pairedItem}` is fixed by the engine (amended into #2064
before merge) and is not this leaf's to version.

## Breaking Change Policy
A breaking change to config semantics ships under a **new node id** (e.g.
`openconnector.synchronization-v2`), leaving the old leaf registered for one
release so existing flow objects keep resolving. Removal follows a deprecation
release. Coordinated with the OpenRegister flow-parity programme (ADR-065).

## SLA
Execution time is bounded by the underlying synchronisation, which can be long
(paginated fetch + per-object writes). For that reason the default trigger path
is **async** (queued, drained by `FlowRunWorker`); an `executionMode: sync` flow
(gated on the `openregister-flow-executionmode-and-token` dependency) runs inline
and should only be used for small, save-time-parity synchronisations.
