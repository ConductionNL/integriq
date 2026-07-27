# ADR-018: Flow leaves declare a contract

## Status
Accepted

## Date
2026-07-27

## Context

Under the OpenRegister flow-parity programme (ADR-065) apps stop owning bespoke
flow engines and instead contribute **leaves** — nodes implementing
`OCA\OpenRegister\Service\Flow\IFlowNode`, registered through
`RegisterFlowNodesEvent`. A leaf's real surface is not an HTTP endpoint; it is:

1. its node **id** (fleet-unique, app-namespaced, e.g. `openconnector.synchronization`),
2. its **config** schema (what a flow author sets on the edge; validated at
   save-time by `validateConfig()`),
3. its **item-list I/O** — the records it reads from and returns to the engine's
   data channel `{json, binary, pairedItem}`, and
4. its **error / versioning** behaviour (what it throws, how `onError` sees it,
   how the id/config evolve without breaking stored flows).

Flow authors wire leaves from other apps without reading their PHP. Today that
surface is discoverable only by reading the node class — there is no declared,
reviewable contract. `openconnector-flow-migration` surfaced this: the
`openconnector.synchronization` leaf needed its config shape, item output, and
error-propagation pinned down before any flow could safely depend on it. The
same will hold for every leaf in the programme (`hermiq.agent-step`, the later
openconnector leaves, and future contributors).

## Decision

Every flow leaf an app contributes MUST ship a declared **leaf contract**
alongside its spec — the four-part surface above (id, config schema, item-list
I/O, error/versioning + breaking-change policy). The canonical shape is the
`contract.md` authored by `openconnector-flow-migration` for the
`openconnector.synchronization` leaf; new leaves follow it.

The contract lives with the change that introduces the leaf
(`openspec/changes/<change>/contract.md`) and is promoted into the leaf's
capability spec on archive. A leaf whose config semantics change breaks under a
**new node id** (the old id stays registered for one release), never by
repurposing an existing key.

## Consequences

- Reviewers gate a new leaf on the presence of its contract, the same way a new
  cross-app HTTP API is gated on a `contract.md`.
- Flow authors and other apps can wire a leaf from its contract without reading
  its implementation.
- This rule binds ALL leaf-contributing apps, so it is a programme-level
  decision. **It is flagged for promotion to the shared hydra ADR set** (the
  ADR-065 family), where it can be enforced fleet-wide; this app-local ADR-018
  records it until that promotion lands.
- Cross-reference: ADR-065 (OpenRegister is the fleet's one flow engine),
  ADR-002 amendment (connector logic stays app-local, now expressed as leaves).

## Evidence

- `openspec/changes/openconnector-flow-migration/contract.md` — the canonical
  leaf-contract shape.
- `lib/Service/Flow/Nodes/SynchronizationNode.php` — the leaf the contract
  describes.
- `openspec/architecture/adr-002-mapping-rule-engine-stays-app-local.md` —
  Amendment (2026-07-27) placing connector logic inside leaves.
