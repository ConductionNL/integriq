---
kind: code
depends_on: []
---

# Proposal: registry-update-maps-source-fields

## Summary

The BRP and KvK connectors post the source's own field names (`naam`, `verblijfplaats`, `handelsnaam`) to OpenRegister's inbound update endpoint. A requesting app's schema names its properties its own way (dossiq's `brpPerson` has `name`, `birth`, `residence`; `kvkCompany` has `tradeName`, `legalForm`, `address`). OpenRegister applies only the properties the schema declares as owned and refuses the rest, so today every live refresh of a dossiq person or company is refused with a 422 and nothing changes.

This change makes integriq translate. Each provider declares, per target schema, which source field becomes which schema property. Integriq remembers which schemas follow an identity when the subscription is requested, and the poll job posts one update per following schema, in that schema's names.

## Why

Decision 178 (10 Oct 2026, Ruben, Q-dossiq-L4b-1): the BRP/KvK field-name mapping lives in integriq's providers, per target schema. The alternative, a map in OpenRegister's `x-openregister-registry` annotation, was the recommendation and was not chosen.

## What changes

- `MapsSourceFieldsInterface::fieldMapFor(string $targetSchema): ?array`, implemented by `BrpVolgindicatieProvider` (target `brpPerson`) and `KvkMutatieProvider` (target `kvkCompany`).
- `SubscriptionChange::mappedTo(array $map)` keeps only the mapped fields, under their schema names.
- `SubscriptionRoster` records the target schemas per identity.
- `SubscriptionRequestHandler` records the requesting object's schema slug when a subscription goes active.
- `RegistrySubscriptionPollJob` posts one update per recorded target schema, mapped when the provider has a map for it, unchanged when it has none.

## Out of scope

The OpenRegister owned-property guard is unchanged: it still refuses what a schema does not own. A target schema with no map still receives the source's own names, so a schema that adopted those names keeps working.
