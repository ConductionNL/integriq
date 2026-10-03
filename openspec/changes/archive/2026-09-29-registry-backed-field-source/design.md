# Design: registry-backed-field-source

Kind: code. One contract with three calls, three bindings over sources
that already exist, and a provenance record that makes "read live" a
checkable claim rather than a marketing one.

## D1. One key, not thirty-nine types

xxllnc's `AttributeType` enum is 39 values and nine of them are BAG
lookups (`casetype-configurability.md`, "The 39 attribute types"). That is
the shape a demo rewards and a decade punishes: the tenth registry is a
tenth set of types, and every form renderer, every export and every
validator learns them all.

The alternative is the one D2 took. A property keeps its small type,
`string` or `object`, and carries a source declaration beside it. The type
system stops growing. The list of providers grows instead, which is a list
integriq already keeps for every other kind of connection.

Say this out loud in a tender rather than apologising for having eight
types where they have thirty-nine. Thirty-nine types is a symptom.

## D2. Three calls, and each one answers a different question

- `suggest(query, config)` is the type-ahead. It runs while somebody is
  typing, it is allowed to be approximate, and it is allowed to be served
  from a cache. This is C-intake-10 and Zammad's
  `autocompletion_ajax_external_data_source`.
- `resolve(identifier, config)` is the authoritative read of one thing by
  its key: this BAG object id, this BSN, this KvK number. It is the call
  whose answer we are willing to show on a beschikking.
- `describe()` says what the provider can answer, which identifier it
  keys on, and what its staleness budget is. Without it, a form renderer
  has to know each provider by name, which is the thirty-nine types
  problem in another costume.

Separating suggest from resolve is what lets a suggestion be fast and an
answer be correct. Collapsing them means either a slow keystroke or an
approximate beschikking.

## D3. Provenance travels with the value

C-parties-and-contacts-4 asks how a party got onto the case: by hand,
derived, or not to be touched. C-integrations-11 asks that a record keep
the identifier and the source it carried in the system it came from, and
the lane's clause says why the usual workaround fails: "putting it in the
description is how it gets lost".

Both are one field. A resolved value carries the provider id, the
identifier at the source, the moment of the read, and whether it came from
the source or from a cache with its age. A hand-typed value carries
`manual` and no source. So "is this address the BAG's or did somebody type
it" is a question with an answer, on every field, forever.

## D4. Live is a budget, not an adjective

C-parties-and-contacts-15 is the Common Ground claim: current values read
from the source rather than a stored copy. A resolver that caches for a
day and calls itself live is the failure this candidate exists to catch.

So each provider declares a staleness budget, a cached answer reports its
age, and a consumer that needs a fresh read can demand one. The number is
visible. A municipality that decides the BAG may be a day old and the BRP
may not is making that decision in configuration rather than in our code.

## D5. A source that is down degrades to a labelled last value

Three failure modes, and only one of them is acceptable. Blanking the
field loses data somebody is looking at. Serving the stale value silently
turns an outage into a wrong beschikking nobody notices. Serving the last
known value with its age and a plain statement that the source is
unreachable is the third, and it is the only one that lets a handler
decide.

`pdok-adapter` already has the machinery for the first half of this: a
circuit breaker and stale-result graceful degradation, with per-call
observability. This change reuses it rather than writing a second one.

## D6. This is not the subscription, and it is not the copy

Integriq now has three things that all sound like "get data from the BRP",
and keeping them apart is most of the value of this design note.

- `registry-subscription-connector` keeps a stored object fresh. Somebody
  owns a copy on purpose and wants it to follow the source.
- `object-source-providers` in openregister serves a whole schema's
  objects from a provider instead of the magic table.
- This change resolves one property's value at the moment of use and
  stores nothing.

The candidate says "read live and never copied". That is the third. The
lane's own note on C-parties-and-contacts-15 says it is "distinct from
row 5.11, which is import and subscription of copies", and the lane is
right.

## D7. Each binding is a binding, not a client

BAG resolves through the PDOK Locatieserver connector, which
`pdok-adapter` already ships with caching, backoff and the canonical
address shape. BRP resolves through the seeded Haal Centraal source that
`registry-subscription-connector` already names. KvK resolves through its
API source.

None of the three is a new HTTP client. If a binding needs one, the source
is missing and that is the thing to build, per ADR-005 and ADR-011.

## D8. Where the key lives, and why it is not ours

openregister owns `x-openregister-property-source` because the property is
openregister's. Integriq owns what happens when it is dereferenced,
because the connection is integriq's. dossiq owns neither and declares.

That is the ownership rule applied three ways to one feature, and it is
the reason this change specifies a contract and a set of bindings rather
than a field type.
