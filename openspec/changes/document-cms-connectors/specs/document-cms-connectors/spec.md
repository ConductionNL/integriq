---
status: proposed
---

# Document management system connectors

## Purpose

Provides two integration capabilities for OpenConnector:

1. **Zenya DOC connector** — integrates Zenya DOC (a document management system) as an OpenConnector Source so that documents managed in Zenya DOC are accessible within the mydash dashboard. Employees can browse, search, and open documents without a separate login. Demand score: 55 (1 tender mention).

2. **Component integration spec framework** — gives developers a formal, testable contract layer for all components integrated with mydash. Every component must expose defined interfaces; the framework validates them and detects breaking changes before deployment. Demand score: 54 (1 tender mention).

---

## Requirements

### REQ-DOC-001: Zenya DOC connection and status confirmation

The connector MUST establish an authenticated connection to a Zenya DOC instance and confirm the connection status to the IT admin. The connection is configured as an OpenConnector Source entity (`type: json`) with `auth: oauth2` or `auth: apikey`. All API calls are routed through `CallService` which logs each request in CallLog. On connection, the Source entity is marked enabled and the admin receives a confirmation.

**Scenarios:**

1. **GIVEN** the Zenya DOC integration is configured with a valid API URL and credentials **WHEN** an IT admin saves the Source entity and clicks "Test connection" **THEN** `CallService` makes a lightweight GET request (e.g. `GET /api/v1/health`) to the Zenya DOC instance, the response HTTP 200 is returned, and the Source entity status is set to `active` — confirming that documents are retrievable.

2. **GIVEN** a Zenya DOC Source entity is saved **WHEN** the IT admin tests the connection and the Zenya DOC API returns HTTP 401 **THEN** the Source status is set to `error`, a descriptive error is written to CallLog, and a Nextcloud notification is sent to the admin indicating that the credentials are invalid.

3. **GIVEN** a Zenya DOC Source entity is saved **WHEN** the Zenya DOC API is unreachable (connection timeout) **THEN** the connection attempt times out after the configured timeout, the Source status is set to `error`, and a notification is sent to the admin indicating that the host is unreachable.

4. **GIVEN** the Zenya DOC API returns rate-limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) **WHEN** `CallService` processes the response **THEN** the Source entity's rate limit fields are updated automatically (existing `CallService.sourceRateLimit()` behaviour), preventing excessive API calls.

---

### REQ-DOC-002: Document listing with titles and metadata

The connector MUST synchronise document titles and metadata from Zenya DOC into OpenRegister so that employees can browse them in the dashboard document section. Documents are stored as `ZenyaDocument` objects in OpenRegister via `SynchronizationService`. The document list is displayed using the standard `CnIndexPage` + `CnDataTable` pattern with filtering by document type and date.

**Scenarios:**

1. **GIVEN** the Zenya DOC integration is active **WHEN** an employee navigates to the document section in mydash **THEN** documents from Zenya DOC are displayed in a paginated table with at minimum their title (`name`), document type (`documentType`), and last modified date (`dateModified`).

2. **GIVEN** the Zenya DOC instance contains 250 documents across multiple types **WHEN** the employee applies a filter for `documentType = "beleid"` **THEN** only documents of type `beleid` are shown, using OpenRegister's standard faceted filter (`CnFacetSidebar`) — no custom search endpoint is needed.

3. **GIVEN** the background sync job runs (via `JobService`) **WHEN** new documents have been added or modified in Zenya DOC since the last sync **THEN** the corresponding `ZenyaDocument` objects in OpenRegister are created or updated with the latest metadata, and existing documents not present in the new sync are marked as archived (not deleted).

4. **GIVEN** the Zenya DOC API returns documents with metadata fields beyond the core schema **THEN** extra fields are stored in the `metadata` object property of the `ZenyaDocument` schema and remain accessible via the standard OpenRegister object detail view.

---

### REQ-DOC-003: Transparent document access without separate login

The connector MUST allow employees to open a document from the mydash document section directly in Zenya DOC without requiring a separate login. Transparent access is implemented via SSO token delegation: `ZenyaConnectorService::getDirectUrl()` generates a short-lived, signed URL that grants the employee access to the document in Zenya DOC using their existing mydash session credentials.

**Scenarios:**

1. **GIVEN** a document exists in Zenya DOC and the employee is logged in to mydash **WHEN** the employee clicks the document in the document list **THEN** `ZenyaConnectorService::getDirectUrl()` is called, a token-delegated URL is generated via `AuthenticationService`, and the document opens in Zenya DOC in a new tab without requiring the employee to log in separately.

2. **GIVEN** the Zenya DOC Source is configured with OAuth2 SSO delegation **WHEN** the employee's delegation token has expired **THEN** `AuthenticationService` automatically refreshes the delegation token before generating the direct URL — the employee experiences no interruption.

3. **GIVEN** the Zenya DOC Source does not support OAuth2 token delegation (API key only) **WHEN** an employee opens a document **THEN** the connector falls back to a direct, unauthenticated URL (`directUrl` field on the `ZenyaDocument` object), and a UI notice informs the employee that they may need to log in to Zenya DOC.

4. **GIVEN** the document's `directUrl` has been revoked or the Zenya DOC session is invalid **WHEN** Zenya DOC returns HTTP 403 on the deep link **THEN** the employee sees an error message in mydash ("Document not accessible — please open Zenya DOC directly") and no stack trace or internal path is exposed.

---

### REQ-CIS-001: Component interface registration and contract testing

The framework MUST allow developers to register integration contracts for components and verify that each component exposes the required interfaces. A contract is stored as an `IntegrationContract` object in OpenRegister. When a contract is applied to a component, `IntegrationContractService::validateComponent()` runs and produces a `ContractValidationResult` with pass/fail status and a violations list.

**Scenarios:**

1. **GIVEN** a new component is added to mydash **WHEN** the integration spec is applied (an `IntegrationContract` is created or updated for that component) **THEN** `IntegrationContractService::validateComponent()` runs, the component's exposed interfaces are verified against the `requiredInterfaces` list, and a `ContractValidationResult` is persisted — with `passed: true` if all required interfaces are present.

2. **GIVEN** an `IntegrationContract` requires interfaces `["listDocuments", "getDocument", "testConnection"]` for `ZenyaConnectorService` **WHEN** `ZenyaConnectorService` exposes only `["listDocuments", "getDocument"]` (missing `testConnection`) **THEN** the validation produces `passed: false` with a violation entry `{ "missing": "testConnection", "expected": "function", "actual": "undefined" }`.

3. **GIVEN** an `IntegrationContract` with `status: active` has all validations passing **WHEN** the admin views the contract detail page **THEN** the most recent `ContractValidationResult` is shown with `passed: true`, the `lastValidatedAt` timestamp, and a list of validated interface names — demonstrating that the component passes all contract tests.

---

### REQ-CIS-002: Integration point data validation

The framework MUST validate that data flowing between components at integration points conforms to the data schemas defined in the contract. When two components interact, `IntegrationContractService::validateDataFlow()` checks that the data exchanged matches the schema definitions, reporting any field mismatches, type errors, or missing required properties.

**Scenarios:**

1. **GIVEN** two components interact within mydash — e.g. `ZenyaConnectorService` produces a `ZenyaDocument` object and the dashboard consumes it **WHEN** the integration points are validated **THEN** `IntegrationContractService::validateDataFlow()` checks that the `ZenyaDocument` conforms to the contract's `dataSchemas` definition and returns `passed: true` if all required fields are present with the correct types.

2. **GIVEN** the `ZenyaDocument` object is missing the required `zenyaId` field **WHEN** `validateDataFlow()` runs **THEN** the result contains `passed: false` and a violation entry `{ "field": "zenyaId", "error": "required field missing" }` — so the data mismatch is detected before the consuming component processes the malformed object.

3. **GIVEN** a `ZenyaDocument` has `dateModified` stored as a plain string `"15-04-2026"` instead of ISO 8601 format `"2026-04-15T00:00:00Z"` **WHEN** the data schema for `dateModified` specifies `format: date-time` **THEN** the validation reports a type violation and flags the field as non-conformant.

---

### REQ-CIS-003: Breaking change detection on contract updates

The framework MUST detect breaking changes when an existing integration contract is updated and report them before the update is applied. A change is breaking if it removes or renames a property that is currently in `requiredInterfaces` or removes a required field from a data schema. The detection result is returned synchronously from `IntegrationContractService::detectBreakingChanges()` so the developer can review it before saving.

**Scenarios:**

1. **GIVEN** an existing `IntegrationContract` with `requiredInterfaces: ["listDocuments", "getDocument", "testConnection"]` **WHEN** the developer updates the contract by removing `"testConnection"` from `requiredInterfaces` **THEN** `detectBreakingChanges()` reports this as a non-breaking change (a required interface removed from the contract is permissive, not breaking for existing implementations — documented in the result).

2. **GIVEN** an existing `IntegrationContract` with a data schema that declares `zenyaId` as a required field **WHEN** the developer updates the contract to rename `zenyaId` to `externalId` **THEN** `detectBreakingChanges()` flags this as a breaking change with `{ "type": "rename", "field": "zenyaId", "newName": "externalId" }` — and the change is blocked until the developer acknowledges the breaking change.

3. **GIVEN** the integration spec is updated to add a new optional field `thumbnailUrl` to the `ZenyaDocument` data schema **WHEN** existing components are re-validated after the update **THEN** `detectBreakingChanges()` reports `breakingChanges: []` (adding optional properties is non-breaking per ADR-011), all existing `ContractValidationResult` records retain `passed: true`, and no deployment block is triggered.

---

## Data Model

See `design.md` for full schema definitions. Schemas stored in OpenRegister:

| Schema | Purpose |
|---|---|
| `ZenyaDocument` | Cached document metadata from Zenya DOC |
| `IntegrationContract` | Interface contract definition for a component |
| `ContractValidationResult` | Outcome of a single contract validation run |

All entities are stored in OpenRegister using the standard `ObjectService` API (`findObjects`, `saveObject`) with the `openconnector` register and the schema slug as the lookup key. No foreign keys — cross-entity references use the OpenRegister relation mechanism.
