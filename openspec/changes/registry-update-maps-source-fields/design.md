# Design: registry-update-maps-source-fields

## D1. The map lives on the provider, keyed by target schema slug

A provider knows its source's field names. Which property each one becomes depends on the schema that follows the identity, so the map is keyed by that schema's slug. A provider that maps implements `MapsSourceFieldsInterface`; `LogSubscriptionProvider` does not, and its changes go out unchanged.

Slugs, not ids: a schema id differs per instance, the slug is what the app ships.

## D2. Integriq learns the target schema from the subscription request

`RegistrySubscriptionRequestedEvent::getPayload()` carries the requesting object's `schema` (an id). The handler resolves it to a slug through OpenRegister's `SchemaMapper` and records it on the roster next to the identity. When the lookup fails, the raw value is recorded and a warning names it; that target then gets the unmapped names, which is the behaviour before this change.

The targets live under their own app-config key (`registry_subscription.targets.<registry>`), so the existing roster (`identity => reference`) keeps its shape and needs no migration.

## D3. One post per target schema

OpenRegister applies an inbound update to every object that follows the identity, and refuses a row when a property is not owned by that row's schema. A response with at least one applied row is a 200. So integriq posts one update per target schema, each in that schema's names: the matching row applies it, a row of another schema refuses it, and that refusal is expected.

An identity with no recorded target (subscribed before this change) posts once, unchanged, exactly as before. A mapped change that keeps no field posts nothing.

## D4. The dossiq maps

`brpPerson`: `naam` to `name`, `geboorte` to `birth`, `verblijfplaats` to `residence`, `geheimhoudingPersoonsgegevens` to `indicatieGeheim`. The inner blocks already use Haal Centraal naming, so only the top-level key moves.

`kvkCompany`: `handelsnaam` and `naam` to `tradeName`, `rechtsvorm` to `legalForm`, `adres` and `bezoekadres` to `address`.
