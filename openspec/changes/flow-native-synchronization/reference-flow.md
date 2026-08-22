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
  → explode           {path: "page.results", as: "source", keepRecord: true}
  → apply-mapping     {mapping, input: "source", output: "target"}
  → contract          {synchronization, idPosition: "source.<id>",
                       hashPosition: "source", output: "contract"}
  → set-fields        {compute: {targetUuid: contract.targetId ?? ""}}
  → object-write      {register, schema, operation: upsert, replace: true,
                       match: [@self.uuid = "{{targetUuid}}"],
                       fields: {…: "{{target.…}}"}, output: "written"}
  → contract-commit   {synchronization, contractPosition, targetIdPosition,
                       targetHashPosition: "target"}
  → contract-sweep    {synchronization, targetIdsPosition, fetchComplete}
  → end
```

The key names are not decoration and they are not free to drift: they are
what `SynchronizationFlowGenerator` emits, and this document is checked
against the generator, not the other way round. The source record stays at
`source`, the mapping's output lands at `target`, the written object comes
back at `written`.

Those names have to agree across three steps. `map` writes `target`, `write`
reads `{{target.<property>}}`, `commit` hashes `target`. Change one and the
flow still validates and still runs: a `{{title}}` template against a record
whose title lives at `target.title` resolves to nothing, and the write
silently stores an empty object. Preflight cannot catch that (see below).

## Why `contract-commit` hashes the MAPPED object and not the WRITTEN one

`contract` only decides `skip` for a contract that carries a matching
`originHash`, a `targetId` AND a `targetHash`. Nothing in the decomposed
pipeline ever wrote a `targetHash`, so that predicate could never hold for a
contract this engine produced: `skip` was structurally unreachable and every
re-run rewrote every object. `targetHashPosition` is what closes that.

It must name `target`, the mapped object. The WRITTEN object — the one
`object-write` returns under `written` — carries server-assigned `@self`
fields, `updated` among them. Hashing that would produce a fresh hash on
every single pass, so the compare would never match and `skip` would stay
unreachable: the same defect wearing the costume of a fix. The mapped object
is the only value on the item that is a pure function of the source, so it is
the only one whose hash can mean "nothing changed".

The recipe is `md5(serialize($mapped))` over the value as it stands, with NO
recursive key sort — deliberately unlike `originHash`, which sorts first.
That asymmetry is copied from the legacy engine
(`SynchronizationService::updateTarget()`, `updateTargetTable()`) so that a
contract written by either engine compares equal to one written by the other.

## Why `skip` items still flow through to the write

A `skip` item is NOT filtered out before `object-write`. Dropping it would
also drop its target id from `contract-sweep`'s input, and a sweep that
cannot see an unchanged object treats it as unreached and DELETES it.
Re-writing an unchanged object is doing more than the legacy engine does;
deleting it would be doing something else entirely.

So what the fix buys is a zero-write CONTRACT commit: `contract-commit`
passes skipped items through untouched, so an unchanged page costs one
contract SELECT and no contract upsert. The object write is not conditional,
and OpenRegister's `SaveObject` stamps `@self.updated` on every update it
performs, so a re-run still moves the target objects' `updated` timestamps.
Making the object write conditional as well requires the sweep to learn a
second source of target ids — its own change, tracked separately rather than
smuggled in here.

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
instance carrying all seven `openconnector.*` nodes. (The node-type ids keep the
old prefix across the app-id rename: they are written into stored flow
documents, so renaming them would leave every existing flow referencing a node
type nothing answers to.)

That verdict is trustworthy because the same endpoint demonstrably refuses
neighbouring mistakes: an `apply-mapping` with no `mapping`, an undeclared
config key (answering with the keys the node does read), and a bulk upsert
without `replace: true`.
