# brp-relations-read

## Why

Dossiq checks whether a case worker is the partner, parent or child of the applicant before the
worker decides on the case. That check needs the relation numbers from the BRP. Dossiq's own BRP
client never asked for them, so the check has never named a relation. The client leaves dossiq
(ADR-118, hydra design section 5, decision 220), and the read is built here, where the BRP source
already lives.

Relation numbers are personal data about people who are not part of the case. A municipality may
ask for them only when its BRP authorisation covers them. So the read is off until an
administrator switches it on, on the source.

## What changes

- `BrpRelationReader::read` asks the `brp-haalcentraal` source for `partners.burgerservicenummer`,
  `ouders.burgerservicenummer` and `kinderen.burgerservicenummer`, and nothing else. It answers a
  plain array: `available`, `reason`, and `relations` as relation and burgerservicenummer.
- The read runs only when the source carries `configuration.relationFields: true`. Without it the
  registry is not asked.
- The call is recorded in the call log without any body (decision 229, change
  `call-log-without-bodies`): who asked, when, which source and the outcome. It stays out of the
  property value cache. The log line says a read happened, on which source and with which
  outcome. It holds no number and no part of the answer.
- `BrpPropertySource::resolve` leaves relation fields out of its question, so a schema property
  cannot ask for them around the setting.
- The seeded source's mock answer gains a partner, two parents and a child, with test-range
  numbers.
- `RegistrySourceGateway::read` gains `readWithoutBodies`, and `configuration()` reads a source's
  configuration by slug.

## Impact

No schema, route or version change. The setting is absent on every existing source, so nothing is
asked until an administrator adds it. A property that declared a relation field on the `brp`
binding no longer receives it; no shipped schema does.

## What the municipality needs

The autorisatiebesluit from RvIG must cover the burgerservicenummer of the parents (BRP elements
02.01.20 and 03.01.20, categories Ouder1 and Ouder2), of the partner (05.01.20, category
Huwelijk/geregistreerd partnerschap) and of the children (09.01.20, category Kind), for a task
that includes checking a worker's personal interest in a case. The element numbers were checked on
12 October 2026 against an autorisatietabel RvIG publishes
(https://publicaties.rvig.nl/media/13550/download). The municipality's own autorisatiebesluit is
the authority on what it may ask. Set `relationFields` only after that is in place.
