# The reference flow (task 2.1)

`reference-flow.json` is the canonical decomposition of one real
synchronization — `GovData.de to Spectr Tender`
(`cad5d34c-21e8-403e-9ddd-2b0df22bb609`), a CKAN `package_search` source with
a real `sourceTargetMapping` — into the page-level steps this change adds.

It is the shape `SynchronizationFlowGenerator` emits, kept here as the
worked example the generator is checked against.

## The shape

```
trigger-manual
  → source-paginate   {synchronization, output: "page"}
  → explode           {path: "page.results"}
  → apply-mapping     {mapping}
  → contract          {synchronization, idPosition, output: "contract"}
  → set-fields        {compute: {matchId: contract.targetId ?? ""}}
  → object-write      {register, schema, operation: upsert, match, fields}
  → contract-commit   {synchronization, contractPosition, targetIdPosition}
  → contract-sweep    {synchronization, targetIdsPosition, fetchComplete}
  → end
```

## Why `explode` is not optional

**The first draft of this flow omitted it, and passed preflight.** It was
still wrong, and wrong in the most expensive direction.

`source-paginate` emits ONE item per page, whose json holds the whole
`results` list. Every step after it is per-ITEM: `contract` reads one origin
id from each item, and `object-write` writes one object per item. Fed a page
item, `contract` finds no single `id` and decides `invalid`, nothing is
contracted, and `contract-sweep` then sees zero synchronised target ids —
so every existing object looks unreached and becomes a deletion candidate.

`deleteInvalidObjects()`'s ratio guard would refuse that on a large
synchronization, but the guard has a FLOOR: below a minimum contract count it
is skipped entirely (`DEFAULT_DELETION_RATIO_THRESHOLD`, and the minimum-count
constant beside it). On a small synchronization the deletion would proceed.

`explode` turns the page into one item per record, which is what every
downstream node's vocabulary already assumes. Batching is not lost — the
engine hands each node its whole item list in one `execute()`.

**Preflight validates VOCABULARY, not SEMANTICS.** `valid: true` means every
node accepts its own config keys; it cannot know that a node is being handed
the wrong SHAPE of item. That is the gap this document exists to close.

## Why not `bulk`

`openregister.object-write` gained `bulk: true` for exactly this pipeline, and
a real migrated pass still cannot use it. `FlowTokenRouter::takenExits()`
evaluates a branch condition against `$items[0]` and routes the whole token
down one exit, so a create/update split per item is not expressible. A bulk
upsert throws on an unresolvable match, and a `create` decision carries no
`contract.targetId`.

The flow therefore uses the single-object upsert, with the `set-fields` step
making the match always RESOLVABLE: an absent match value is refused by the
node, an empty one is simply a miss, which is the create case. Restoring
`bulk` needs per-item branching in the engine — tracked as a follow-up, not
worked around here.

## Verification

The document in this directory returns `{"valid": true, "blocking": [],
"warnings": []}` from `POST /apps/openregister/api/flow/validate` on a live
instance carrying all seven openconnector nodes.

That verdict is trustworthy because the same endpoint demonstrably refuses
neighbouring mistakes: an `apply-mapping` with no `mapping`, an undeclared
config key (answering with the keys the node does read), and a bulk upsert
without `replace: true`.
