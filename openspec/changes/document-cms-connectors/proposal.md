# Document management system connectors

## Summary

This change adds two integration capabilities to OpenConnector: a **Zenya DOC connector** that makes documents managed in Zenya DOC directly accessible within the Nextcloud dashboard, and a **component integration spec framework** that gives developers defined, testable contracts for every integrated component.

## Motivation

Both features appear in tender requirements with high demand scores:

| Feature | Demand | Tender mentions |
|---|---|---|
| Integration: Zenya DOC | 55 | 1 |
| Component integration spec | 54 | 1 |

Dutch government organisations using mydash require a document management system connector so employees can find and open documents without switching between systems. The component integration spec addresses an architectural need: as the number of integrated components grows, the absence of formal contracts makes it impossible to detect interface drift or breaking changes before deployment.

## Scope

### Feature 1 — Integration: Zenya DOC

- New **ZenyaConnectorService** (`lib/Service/ZenyaConnectorService.php`) bridging OpenConnector's existing Source + CallService infrastructure with the Zenya DOC REST API.
- Connection management: configuration as an OpenConnector Source entity, health check, connection status confirmation.
- Document listing: retrieval of document titles and metadata, surfaced in the dashboard document section via OpenRegister sync.
- Transparent document access: employees open Zenya DOC documents from mydash without a separate login — token delegation via the existing `AuthenticationService` OAuth2/SSO flow.
- Admin configuration UI for Zenya DOC source credentials and endpoint settings.

### Feature 2 — Component integration spec

- New **IntegrationContractService** (`lib/Service/IntegrationContractService.php`) managing interface definitions stored as OpenRegister objects.
- Contract schema: component name, version, required interfaces, data shapes, and schema.org-typed property definitions.
- Validator that runs against all registered components when a contract is applied or updated, reporting mismatches with GIVEN/WHEN/THEN traceability.
- Breaking-change detector: invoked on contract updates, identifies components that would fail the updated contract before deployment.

## Impact

### Files affected (apply-phase hints)

| File | Change |
|---|---|
| `lib/Service/ZenyaConnectorService.php` | New — Zenya DOC API client |
| `lib/Service/IntegrationContractService.php` | New — contract management + validation |
| `lib/Controller/ZenyaController.php` | New — `/api/zenya/documents` endpoint |
| `lib/Controller/IntegrationContractController.php` | New — `/api/integration-contracts` endpoint |
| `lib/Settings/openconnector_register.json` | Extended — new schemas + seed data |
| `appinfo/routes.php` | Extended — new API routes |
| `src/views/ZenyaView.vue` | New — document listing page |
| `src/views/IntegrationContractsView.vue` | New — contracts management page |
| `src/store/modules/zenyaDocument.js` | New — Pinia store for Zenya documents |
| `src/store/modules/integrationContract.js` | New — Pinia store for contracts |

### Breaking changes

None — this change is additive. No existing APIs, schemas, or routes are modified.

### Dependencies

- **OpenRegister** (ObjectService, CallService, AuthenticationService, SynchronizationService) — pre-existing
- **Zenya DOC REST API** — external service (requires customer credentials)
- **Nextcloud Files** — optional: store downloaded documents alongside existing files
