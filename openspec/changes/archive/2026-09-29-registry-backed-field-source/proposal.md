---
kind: code
depends_on: []
---

# Proposal: registry-backed-field-source

## Summary

A field whose values come from a registry is a small property type plus a
declared source, not a property type per registry. openregister owns the
key on the property. integriq owns the resolvers behind it: BAG, BRP, KvK
and the next one. dossiq owns the declaration and nothing else, so a case
type can finally declare a second address and a second person.

## Motivation

Round 4 discovery, cluster 26 "Fields read live from a registry, not
copied" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14), and cluster CT-5
"Registry-backed fields" from the depth study
(`_round4/discovery/casetype-configurability.md`, rows A6, A7, A8 and B1).
Six candidates, nine passers, seven driven, proving system Zammad. Owner
integriq, size L, wave 1.

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-integrations-9 | must, matrix hole | no | a field's values come from a query against an external source at the moment of use |
| C-intake-10 | must | no | a form field's choices come from a live external service while the applicant is filling it in |
| C-parties-and-contacts-15 | should | partial | the case shows current values read from the source registry rather than a stored copy |
| C-parties-and-contacts-4 | should | partial | a party on the case records how it got there: by hand, derived, or not to be touched |
| C-integrations-11 | should | partial | a record keeps the identifier and the source it carried in the system it came from |
| C-integrations-43 | should | partial | the organisation tree and the classification plan are synchronised on demand |

The proving passer, verbatim from the lane
(`_round4/discovery/candidates.json`, C-integrations-9,
`integrations.tsv:21`): "zammad: External data source
(lib/external_data_source.rb:47, config/routes/external_data_source.rb,
attribute type autocompletion_ajax_external_data_source)". Three driven
passers on that candidate: OTOBO, Zammad and Znuny. The lane's clause:
"BAG, KvK and BRP must be looked up live and never copied."

The depth study measures what we have. dossiq holds the lookups, in
`lib/Service/Pdok/PdokBagService.php`, `register.d/25-brp-kvk.json` and
`InitiatorPicker.vue`, and each is welded to one fixed slot: the case
location, the requester. Ratings A6, A7 and A8 read `partial` for exactly
that reason: "BAG is a fixed sub-record of every case, not a kenmerk a
case type declares", "one hardcoded requester slot; a second BRP-backed
person field cannot be declared", "the KvK lookup is the initiator slot,
not a field type". B1 reads `no`: "the only default is a static literal".

## The decision this rests on

D2, "Is a registry-backed field a type, or a small type plus a declared
source" (`_round4/discovery/decisions.md`). Ruben took the
recommendation: option 2. Verbatim: "openregister owns the `source` key,
integriq owns the adapters, dossiq owns only the declaration. Say plainly
that xxllnc's 39 types are a symptom, not a feature."

The symptom is measured in the depth study: `AttributeType`
(`versioned_casetype.py:912`) is an enum of 39 values, and nine of the 37
real ones are BAG lookups. Option 1 is a new type for every registry,
forever. Option 2 is one key and one resolver contract, and it survives
the tenth registry without touching the type system.

Cluster 26 also names D3, the rules engine, because a field whose options
narrow on another field's value is a rule reading a source. That half is
openregister's and is not in this change.

D6 was answered relevance-led: every `must` candidate enters the corpus as
a row, whatever its passer count. That admits C-intake-10, a `must` with
one documented passer and no driven one, which the old two-driven-passers
bar would have dropped. It is in scope here: a form field's choices read
live while the applicant is filling it in is the `suggest` half of the
same resolver contract, not a second mechanism.

## The key, named, so two lanes do not invent two

openregister owns the key. This change asks for it by name so the integriq
resolver contract and the openregister property key are one thing:

- **`x-openregister-property-source`**, on a schema property:
  `{ "provider": "<id>", "config": { … }, "mode": "live" | "default" }`.
- It is **not** `x-openregister-object-source`. That key exists, in
  openregister's open change `object-source-providers`, and it serves a
  whole schema's objects from a provider instead of the magic table. This
  one serves one property's values. Two keys that differ by one word and
  by their entire blast radius is exactly the confusion worth spending a
  sentence on now.

openregister's half is to be specified in openregister, wave 1, under the
slug `property-source-resolution`. If that lane picks another slug, this
proposal follows it rather than the reverse: the key and the owner are the
decision, the slug is not.

## Scope

Integriq's half:

- A `PropertySourceProvider` contract: `suggest(query, config)` for a
  type-ahead while a field is being filled, `resolve(identifier, config)`
  for one authoritative read, and `describe()` for what the provider can
  answer and how fresh it is.
- Bindings for the registries we already reach: BAG through the PDOK
  Locatieserver (`pdok-adapter`, shipped), BRP through the seeded Haal
  Centraal source, KvK through its API. Each one is a binding over an
  existing source, not a new client.
- A resolved value carries its provenance: the provider id, the
  identifier at the source, the moment it was read, and whether it was
  read live or served from a cache with its age. That is
  C-parties-and-contacts-4 and C-integrations-11 in one field rather than
  two.
- Live means live, with a stated staleness budget per provider and a
  cache that reports its age rather than hiding it.
- A source that is down degrades to the last known value, labelled, and
  never to a blank field or a silent stale one.
- An on-demand resync for the list-shaped providers, which is
  C-integrations-43.

## What integriq builds and what dossiq consumes

Integriq builds the contract, the three bindings, the provenance and the
degradation. openregister builds the property key and calls the resolver.
dossiq declares, and deletes:

1. A case type declares a property with a source. Two address fields and
   two person fields on one case type become configuration rather than a
   schema change.
2. dossiq's fixed slots stop being the only way in.
   `PdokBagService`, `register.d/25-brp-kvk.json#brpPerson`,
   `#kvkCompany` and `InitiatorPicker.vue` keep working for the case
   location and the requester, and stop being the ceiling.
3. dossiq's `property-definition-management` gains the source input. That
   is dossiq's wave 1 change `casetype-field-vocabulary` (CT-1), which
   widens `propertyDefinition` and the
   `x-openregister-extends-form.map`. Without CT-1 the key cannot reach a
   case type at all, because the map forwards eight keys and this is not
   one of them.

## The existing specs this extends

- `pdok-adapter` (done): the BAG binding is a provider over the connector
  that already normalises PDOK into the canonical address shape.
- `registry-subscription-connector` (open): the BRP and KvK sources, and
  the split it already writes down. That change keeps a stored object
  fresh; this one reads without storing. They are the two halves of
  "never copied", and neither replaces the other.
- `source-management` and ADR-005: a provider binds an existing source.
- openregister `integration-brp-haalcentraal` and
  `integration-kvk-opencorporates`: the one-shot reads that exist.

## Size and dependencies

Size L, the build plan's own figure, and the depth study says the same:
"L, and it is new work rather than an existing change". Depends on
openregister's property key, to be specified in openregister, wave 1, and
on dossiq CT-1 before any case type can declare one.

## Out of scope

- The property key itself, the form rendering and the validation.
  openregister's, wave 1.
- A rule that narrows one field's options by another field's value. D3
  and cluster 19, openregister.
- Appointment booking as a field. xxllnc has three appointment types; it
  is a separate capability and a separate owner.
- The subscription that keeps a stored copy fresh.
  `registry-subscription-connector` already holds it.
