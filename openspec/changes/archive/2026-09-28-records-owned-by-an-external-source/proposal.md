---
kind: code
depends_on: []
---

# Proposal: records-owned-by-an-external-source

## Summary

A record integriq pulled from a registry is not an ordinary record. It has an
owner somewhere else, it can vanish at that owner without anyone here doing
anything, and a handler can today delete it by hand and nothing says the copy
was never ours to remove. This change makes the ownership readable, makes what
happens when the source drops a record a declared choice per synchronisation
rather than one hardcoded behaviour, and refuses a local delete of a
source-owned record unless somebody writes down why.

## The row this change closes

Row **5.19**, area "Parties and contacts", capability "Records owned by an
external source, with a policy for when they disappear", rated **no** for
dossiq.

Source field, verbatim:

```
dossiq#2314, published as 5.19
```

Corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **5.19** | 5.19 | Records owned by an external source, with a policy for when they disappear | no | unread |  |
```

Ledger note, dossiq's own evidence, verbatim:

> Rows 5.3 and 5.11 ask whether we can look a person up in BRP or KvK. 25-brp-kvk.json carries no readOnlyFromSource, no policy for a record that disappears, and no lock against a manual delete.

## What the competitor evidence is

There is none to quote, and that is a finding rather than an omission. Row 5.19
is one of the 98 rows promoted under decision D1, and the corpus says in as
many words:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is a
> reading of a product somebody opened, and filling these cells with it would
> fabricate thirty readings per row.

So this proposal claims nothing about what any competitor does. The argument is
the ledger note and the code it names, both of which are ours.

## Why this is integriq's and not dossiq's

The three things the row asks for are all properties of a synchronisation, and
the synchronisation is integriq's. Ownership is the
`SynchronizationContract` that already links an origin id to a target id
(app ADR-005). Disappearance is the garbage-collection pass in
`synchronization-engine` REQ-010, which today has exactly one behaviour and no
declaration. The lock against a manual delete is a rule about a record integriq
maintains, so integriq is the only place that knows the rule applies.

Dossiq holds the declaration and the rendering, nothing else.

## What integriq builds

1. **Ownership is a readable state on the record, not a convention.** A record
   maintained by a synchronisation carries the source, the origin id and a
   stated ownership mode. A record nobody synchronises carries `local` and says
   so, so "who owns this" is never answered by inference.
2. **The disappearance policy is declared per synchronisation.** One of
   `delete`, `mark ended` or `keep and flag`. The default is `delete`, which is
   what the engine does today, so nothing changes for an existing
   synchronisation until somebody declares otherwise.
3. **A record the source stopped carrying says when it was last seen.** Under
   `mark ended` and `keep and flag` the record stays, carries the timestamp of
   the last run that still saw it, and carries the run that first did not.
4. **A local delete of a source-owned record is refused, with a named
   override.** The refusal names the synchronisation. The override records who
   made it and why, per ADR-102, so the exception is a written statement rather
   than a click nobody can find afterwards.
5. **The policy never runs on a fetch that was not complete.** REQ-009 and
   REQ-010 already stop a truncated or rate-limited fetch from looking like a
   source that emptied itself. The new policy sits behind those guards and
   cannot be used to walk around them.

## What dossiq consumes

Dossiq declares and renders. Its consuming half is not yet specified as its own
change, so: to be specified in dossiq, on the surface its existing
`contacts-domain` change opens.

- Declare the ownership mode and the disappearance policy on the `brpPerson`
  and `kvkCompany` synchronisations, which is `lib/Settings/register.d/25-brp-kvk.json`,
  the exact file the ledger note names.
- Render the state on the contact and on the case party: this person is the
  BRP's, it is read only, and the BRP stopped carrying it on this date.
- Stop offering a delete action on a party dossiq does not own.

## ADRs this cites

- **ADR-091** (hydra, external API surface belongs to integriq), clause 6: BRP
  and KvK are national registry shapes, so the record that mirrors one is
  maintained here and not in a case app.
- **ADR-070** (hydra, OpenRegister-backed persistence is the default): the
  ownership state and the last-seen timestamps are properties on the object in
  OpenRegister, not a second table in integriq.
- **ADR-102** (hydra, configuration fail mode): a synchronisation whose policy
  is set to something the engine does not know is refused at save, and an
  override without a reason is refused too.
- **ADR-005** (integriq, the source, synchronisation and contract triad): the
  contract is where per-object state already lives, so ownership and last-seen
  go on it rather than into a new entity.

## Size

M. A declared policy, two states on the record, one refusal with an override,
and the read surface that answers the consuming app. No new transport and no
new engine.

## The existing specs it extends

- `synchronization-engine`, REQ-009 fetch completeness, REQ-010 the deletion
  ratio guard and REQ-018 no garbage collection on an incremental run. The
  delta is that deletion becomes one of three declared outcomes instead of the
  only one.
- `synced-from-tab`, REQ-SYNC-002: the provenance rows that tab already renders
  gain the ownership mode and the last-seen timestamp.

## Out of scope

- Resolving a field live from a registry. That is
  `registry-backed-field-source`, which answers "what does the BRP say right
  now" for one property. This change answers "who owns this record and what
  happens when it goes away", which is the other question.
- Subscribing to changes at the source. That is
  `registry-subscription-connector`.
- Deciding what dossiq shows on the case. Dossiq's half, named above.
