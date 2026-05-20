# Design: Document management system connectors

## Context

OpenConnector is the integration and synchronization fabric of the Conduction stack. It exposes a generic Source + CallService + SynchronizationService pattern that any protocol-specific adapter can use. This change adds two adapters on top of that pattern:

1. **Zenya DOC connector** — integrates the Zenya DOC document management system as an OpenConnector Source, synchronising document metadata into OpenRegister and enabling transparent deep-link access.
2. **Component integration spec framework** — introduces a formal contract layer so all components integrated with mydash expose verifiable interfaces that can be tested and diffed for breaking changes.

## Architecture

### New Services

| Service | Location | Responsibility |
|---|---|---|
| `ZenyaConnectorService` | `lib/Service/ZenyaConnectorService.php` | Zenya DOC API client: connect, list documents, fetch document metadata, delegate SSO token for transparent access |
| `IntegrationContractService` | `lib/Service/IntegrationContractService.php` | Manage integration contracts stored in OpenRegister; validate component interfaces against contracts; detect breaking changes on contract updates |

### New Controllers

| Controller | Route prefix | Auth |
|---|---|---|
| `ZenyaController` | `/api/zenya` | `#[NoAdminRequired]` + per-object check |
| `IntegrationContractController` | `/api/integration-contracts` | `#[AuthorizedAdminSetting]` for write; `#[NoAdminRequired]` for read |

### Integration pattern

Both features follow the existing OpenConnector integration pattern:

```
Source entity (Zenya DOC credentials + URL)
  └─► CallService          ← HTTP requests, rate-limit tracking, CallLog
        └─► ZenyaConnectorService ← domain-specific logic (token delegation, metadata mapping)
              └─► SynchronizationService ← upsert ZenyaDocument objects into OpenRegister
```

For the component integration spec:

```
IntegrationContract (OpenRegister object)
  └─► IntegrationContractService
        ├─► validateComponent(componentName)   ← returns pass/fail + violations
        └─► detectBreakingChanges(oldContract, newContract)
```

### Data flow — Zenya DOC document retrieval

1. Admin creates a Source entity (`type: json`, `auth: oauth2` or `apikey`) pointing at the Zenya DOC API.
2. `ZenyaConnectorService::syncDocuments()` calls `CallService::call()` which logs the request in CallLog.
3. Zenya DOC API returns a paginated list of documents; each is upserted as a `ZenyaDocument` object in OpenRegister via `ObjectService::saveObject()`.
4. Employee navigates to the document section in mydash; the frontend fetches `ZenyaDocument` objects from OpenRegister (standard list + filter via `CnIndexPage`).
5. Employee clicks a document; `ZenyaConnectorService::getDirectUrl()` generates a token-delegated deep link so the document opens in Zenya DOC without a separate login.

### Data flow — component contract validation

1. Developer or admin creates an `IntegrationContract` object defining the required interface for a component.
2. On save, `IntegrationContractService::validateAllComponents()` runs against every registered component that declares itself as implementing this contract.
3. Each validation attempt produces a `ContractValidationResult` (pass/fail, violations list) stored as an OpenRegister object.
4. When a contract is updated, `detectBreakingChanges()` compares the old and new contract versions; any removed or renamed required property is flagged as a breaking change and reported before the update is applied.

---

## Data Model

All schemas use `schema.org` vocabulary per ADR-011. Stored in OpenRegister as defined in `lib/Settings/openconnector_register.json`.

### Schema: ZenyaDocument

Represents a document synchronised from Zenya DOC into OpenRegister.

| Property | Type | Required | schema.org mapping | Description |
|---|---|---|---|---|
| `zenyaId` | string | Yes | `schema:identifier` | Zenya DOC internal document identifier |
| `name` | string | Yes | `schema:name` | Document title |
| `description` | string | No | `schema:description` | Document description or abstract |
| `dateCreated` | string (date-time) | No | `schema:dateCreated` | Creation date in Zenya DOC |
| `dateModified` | string (date-time) | No | `schema:dateModified` | Last modified date in Zenya DOC |
| `documentType` | string | No | `schema:additionalType` | Document type/category (e.g. beleid, verordening, rapport) |
| `directUrl` | string (uri) | No | `schema:url` | Deep-link URL for transparent access |
| `author` | string | No | `schema:author` | Name of document author |
| `sourceId` | string (uuid) | Yes | — | OpenRegister UUID of the parent Source entity |
| `metadata` | object | No | `schema:additionalProperty` | Extra key-value metadata from Zenya DOC |

### Schema: IntegrationContract

Defines the interface contract that an integrated component must fulfil.

| Property | Type | Required | schema.org mapping | Description |
|---|---|---|---|---|
| `name` | string | Yes | `schema:name` | Unique contract name (e.g. `document-provider-v1`) |
| `version` | string | Yes | `schema:version` | Semantic version of the contract |
| `description` | string | No | `schema:description` | Human-readable description of what the contract tests |
| `componentName` | string | Yes | — | Name of the component this contract applies to |
| `requiredInterfaces` | array | Yes | — | List of interface method names the component must expose |
| `dataSchemas` | array | No | — | JSON Schema definitions for data exchanged at integration points |
| `status` | string (enum) | Yes | `schema:actionStatus` | `active`, `deprecated`, or `broken` |
| `lastValidatedAt` | string (date-time) | No | `schema:dateModified` | Timestamp of most recent validation run |

### Schema: ContractValidationResult

Records the outcome of a single contract validation run.

| Property | Type | Required | schema.org mapping | Description |
|---|---|---|---|---|
| `contractId` | string (uuid) | Yes | `schema:identifier` | OpenRegister UUID of the IntegrationContract |
| `componentName` | string | Yes | — | Component that was validated |
| `validatedAt` | string (date-time) | Yes | `schema:startTime` | Timestamp when validation ran |
| `passed` | boolean | Yes | — | `true` if all contract requirements were met |
| `violations` | array | No | — | List of interface mismatches (property, expected, actual) |
| `breakingChanges` | array | No | — | List of breaking changes detected on contract update |

---

## Seed Data

Loaded via `lib/Settings/openconnector_register.json` (`x-openregister.type: "mock"`) using the `@self` envelope pattern, idempotent on re-import.

### ZenyaDocument seed objects

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "zenya-document",
      "slug": "zenya-doc-beleidsnota-duurzaamheid-2026"
    },
    "zenyaId": "ZD-2026-0041",
    "name": "Beleidsnota Duurzaamheid 2026",
    "description": "Gemeentelijk beleid voor duurzame energie en circulariteit",
    "dateCreated": "2026-01-15T09:00:00Z",
    "dateModified": "2026-03-10T14:22:00Z",
    "documentType": "beleid",
    "author": "Afdeling Ruimte & Duurzaamheid",
    "sourceId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "metadata": { "afdeling": "Ruimte & Duurzaamheid", "status": "vastgesteld" }
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "zenya-document",
      "slug": "zenya-doc-verordening-participatie-2025"
    },
    "zenyaId": "ZD-2025-0187",
    "name": "Verordening Participatie 2025",
    "description": "Inspraakregeling voor burgers bij gemeentelijke besluiten",
    "dateCreated": "2025-09-01T10:00:00Z",
    "dateModified": "2025-11-18T11:05:00Z",
    "documentType": "verordening",
    "author": "Griffie",
    "sourceId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "metadata": { "afdeling": "Griffie", "status": "gepubliceerd" }
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "zenya-document",
      "slug": "zenya-doc-rapport-financien-q1-2026"
    },
    "zenyaId": "ZD-2026-0092",
    "name": "Financieel rapport Q1 2026",
    "description": "Kwartaalrapportage gemeentelijke financiën eerste kwartaal 2026",
    "dateCreated": "2026-04-05T08:30:00Z",
    "dateModified": "2026-04-05T08:30:00Z",
    "documentType": "rapport",
    "author": "Afdeling Financiën",
    "sourceId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "metadata": { "afdeling": "Financiën", "status": "concept" }
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "zenya-document",
      "slug": "zenya-doc-procesbeschrijving-inkoop-2025"
    },
    "zenyaId": "ZD-2025-0334",
    "name": "Procesbeschrijving inkoopproces 2025",
    "description": "Beschrijving van het gemeentelijk aanbestedingsproces",
    "dateCreated": "2025-06-12T13:00:00Z",
    "dateModified": "2026-01-20T09:45:00Z",
    "documentType": "procesbeschrijving",
    "author": "Afdeling Inkoop",
    "sourceId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "metadata": { "afdeling": "Inkoop", "status": "vastgesteld" }
  }
]
```

### IntegrationContract seed objects

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "integration-contract",
      "slug": "contract-document-provider-v1"
    },
    "name": "document-provider-v1",
    "version": "1.0.0",
    "description": "Contract voor componenten die documenten aanbieden aan het dashboard",
    "componentName": "ZenyaConnectorService",
    "requiredInterfaces": ["listDocuments", "getDocument", "getDirectUrl", "testConnection"],
    "dataSchemas": [],
    "status": "active",
    "lastValidatedAt": "2026-05-01T10:00:00Z"
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "integration-contract",
      "slug": "contract-source-connector-v1"
    },
    "name": "source-connector-v1",
    "version": "1.0.0",
    "description": "Basiscontract voor alle OpenConnector source adapters",
    "componentName": "BaseConnectorInterface",
    "requiredInterfaces": ["connect", "testConnection", "getStatus", "disconnect"],
    "dataSchemas": [],
    "status": "active",
    "lastValidatedAt": "2026-05-01T10:00:00Z"
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "integration-contract",
      "slug": "contract-sync-provider-v1"
    },
    "name": "sync-provider-v1",
    "version": "1.0.0",
    "description": "Contract voor componenten die synchronisatie ondersteunen",
    "componentName": "SynchronizationService",
    "requiredInterfaces": ["synchronize", "getSyncStatus", "resetSync"],
    "dataSchemas": [],
    "status": "active",
    "lastValidatedAt": "2026-04-28T14:30:00Z"
  }
]
```

### ContractValidationResult seed objects

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "contract-validation-result",
      "slug": "cvr-document-provider-v1-zenya-20260501"
    },
    "contractId": "auto-resolved-by-slug:contract-document-provider-v1",
    "componentName": "ZenyaConnectorService",
    "validatedAt": "2026-05-01T10:00:00Z",
    "passed": true,
    "violations": [],
    "breakingChanges": []
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "contract-validation-result",
      "slug": "cvr-source-connector-v1-zenya-20260501"
    },
    "contractId": "auto-resolved-by-slug:contract-source-connector-v1",
    "componentName": "ZenyaConnectorService",
    "validatedAt": "2026-05-01T10:00:00Z",
    "passed": true,
    "violations": [],
    "breakingChanges": []
  }
]
```

---

## Reuse Analysis

Per ADR-012, the following OpenRegister/OpenConnector services are leveraged **without** modification:

| Service | Where reused |
|---|---|
| `CallService` | All Zenya DOC API calls — handles HTTP, rate-limiting, request/response logging in CallLog |
| `AuthenticationService` | OAuth2 / API key auth for Zenya DOC Source; token refresh and SSO delegation |
| `SynchronizationService` | Upsert ZenyaDocument objects into OpenRegister on each sync run |
| `ObjectService` (`findObjects`, `saveObject`) | CRUD for ZenyaDocument, IntegrationContract, ContractValidationResult |
| `JobService` | Background polling job for periodic Zenya DOC document sync |
| `NotificationService` | Notify admin on connection failures; notify developer on contract validation failures |
| `CnIndexPage` + `useListView` | Frontend document listing (ZenyaDocument schema-driven table) |
| `CnFormDialog` | Schema-driven create/edit forms for IntegrationContract objects |
| `createObjectStore` + plugins | Pinia stores for ZenyaDocument and IntegrationContract |

**Deduplication check:** No similar Zenya DOC connector exists in `openspec/specs/` or `lib/Service/`. The IntegrationContractService is a new domain-specific layer; it does not duplicate OR's `SchemaService` (which manages OpenRegister schemas, not component interface contracts). No overlap found — new code is justified.

---

## Dependencies

| Dependency | Type | Notes |
|---|---|---|
| OpenRegister | Internal | ObjectService, SynchronizationService, CallService — all pre-existing |
| Zenya DOC REST API | External | Customer-supplied credentials; no public documentation available — design based on common DMS REST API patterns |
| Nextcloud Files | Internal | Optional: store downloaded document copies via FileService |
| AuthenticationService (OpenConnector) | Internal | SSO token delegation for transparent document access |

---

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Zenya DOC API is proprietary with limited documentation | Medium | Design against the general DMS REST pattern; document assumptions in the connector config |
| Token delegation for transparent access may require Zenya DOC OAuth2 delegation scope | Medium | Fall back to direct URL (open in Zenya DOC with normal login) if delegation is unavailable |
| Contract validation results in false-positive breaking changes on non-breaking schema additions | Low | Only flag removal/rename of required properties; additions are non-breaking per ADR-011 |
