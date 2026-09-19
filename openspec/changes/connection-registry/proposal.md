---
kind: capability
depends_on: []
---

# Proposal: connection-registry

## Summary

This is integriq's half of the fleet change `connection-registry`. The contract lives in the hydra umbrella change, on branch `feat/connection-registry` of ConductionNL/hydra:

- `openspec/changes/connection-registry/proposal.md`
- `openspec/changes/connection-registry/design.md` (D1 to D10 are the contract)
- `openspec/changes/connection-registry/specs/connection-registry/spec.md` (REQ-CONN-001 to REQ-CONN-008)

This change does not restate the contract. It says what integriq builds to honour it.

## Why

Dossiq has the only Integrations page in the fleet that says when a mock adapter answers. Nobody else can reuse it, because its rows, statuses and seed are dossiq's own. Integriq already owns sources, the circuit breaker and the call engine. So integriq is the one app that can check a connection without asking the app that uses it.

## What changes in integriq

1. An `app_connection` schema in the `integriq` register, admin-only for every verb. Not `connection`: stackiq owns that slug, and slugs are global on a shared OpenRegister (umbrella D11).
2. `lib/Settings/connections.schema.json`, the JSON Schema an app's `lib/Settings/connections.json` must pass.
3. `ConnectionRegistryService::sync()`, which turns each enabled app's declaration file into rows. It runs from a repair step, on `AppEnableEvent`, and from the hourly health job when an app's version moved.
4. `ConnectionStatusResolver`, which works out `status`, `statusMessage` and `checkedAt` by the D4 rules, in order.
5. Two typed events, `ConnectionStatusReportedEvent` and `ConnectionRefreshRequestedEvent`, with listeners that never throw into the sender.
6. `ConnectionHealthJob`, an hourly job that probes at most 25 linked sources, oldest first. An open breaker is recorded without a call.
7. A shared `SourceTestService`, so the health job and `SourcesController::test` make the same call.
8. An admin overview page under the Connections group, and `LinkSourceDialog`, which links a source to a declared connection and probes it at once.

## Out of scope

- Adopting the registry in dossiq. That is dossiq's change `adopt-connection-registry`.
- Graduating the two formatters into `nextcloud-vue`.
- Translating stored status messages. Rows carry English text, as the umbrella says.

## Rollback

Revert this change. The `app_connection` objects stay behind and nothing reads them. They hold no credentials, only a source uuid.
