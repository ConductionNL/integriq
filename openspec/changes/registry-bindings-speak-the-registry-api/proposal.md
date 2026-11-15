# registry-bindings-speak-the-registry-api

## Why

The `brp` and `kvk` property sources asked their seeded sources for `/brp/{bsn}`, `/brp/suggest`,
`/kvk/{kvkNummer}` and `/kvk/suggest`. Neither registry has those paths. Haal Centraal BRP Personen
v2 takes a POST to `/personen`, and KvK Zoeken v2 takes a GET on `/zoeken`. Both bindings also read
a flat record, where the registries answer an envelope (`personen`, `resultaten`). So a binding
could not read the mock answer its own seeded source ships, and would get a 404 from the real
registry. The unit tests passed because they handed the gateway double a made-up flat record.

Dossiq held the working wire knowledge in its own BRP and KvK clients. Those clients leave dossiq
(ADR-118, hydra design section 5), so the knowledge moves here first.

## What changes

- `BrpPropertySource::resolve` posts RaadpleegMetBurgerservicenummer to `/personen` and returns the
  person. `fields` in the declared configuration overrides the default field list.
- `BrpPropertySource::suggest` answers only for a whole burgerservicenummer. Haal Centraal has no
  free search.
- `KvkPropertySource::resolve` and `suggest` use `/zoeken`, by `kvkNummer` and by `naam`. `resolve`
  returns the head office row of that number.
- `RegistrySourceGateway::read` takes an optional JSON body and then posts.

## Impact

No schema, route or version change. A consumer that read the flat record these bindings never
returned in practice now gets the registry's own record.
