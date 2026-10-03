# document-generation-vendor-adapter Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- document-generation-vendor-adapter

## Purpose

One provider seam for vendor document generation, SmartDocuments and
Xential first, behind filinq's one document channel (ADR-075). Filinq asks
for a render through a typed event; integriq tracks the job, resolves
credentials by reference and announces the result. Requested by the
dossiq competitor analysis, register row 12.11.

## ADDED Requirements

### Requirement: One provider seam with log, SmartDocuments and Xential bindings (REQ-DGV-001)

Integriq MUST define `DocumentGenerationProviderInterface` with
`getProviderId()`, `getConfigSchema()`, `listTemplates()`, `render()`,
`status()` and `fetch()`, resolved by `providerId` from the source
configuration, with a `log` binding, a `smartdocuments` REST binding and
a `xential` REST binding. A vendor binding MUST refuse activation without
a `credentialRef` and MUST say so.

#### Scenario: A source without credentials cannot activate
- GIVEN a document generation source with `providerId = smartdocuments` and no `credentialRef`
- WHEN an operator activates it
- THEN activation is refused with a message naming the credential reference
- e2e: `tests/e2e/document-generation-source.spec.ts`

#### Scenario: The log binding answers a placeholder
- GIVEN a source with `providerId = log`
- WHEN a render is requested
- THEN a one-page PDF naming the template id and the data hash is returned and the job is `rendered`
- @e2e exclude the log provider is a backend fixture; covered by PHPUnit

### Requirement: A render is a typed command with a tracked job (REQ-DGV-002)

Integriq MUST handle `DocumentRenderRequestedEvent` by creating a
`documentGenerationJob` (`sourceId`, `providerId`, `templateId`,
`dataHash`, `requestedBy`, lifecycle `queued`, `rendered`, `failed`),
calling the source's provider, and answering the result slot with the job
id or a structured refusal. The job MUST NOT store the data. Every
terminal state MUST dispatch `DocumentRenderedEvent` with the job id,
`requestedBy` and the result file reference or the error.

#### Scenario: filinq renders a beschikking through SmartDocuments
- GIVEN filinq dispatches `DocumentRenderRequestedEvent` for a template on a mock-mode SmartDocuments source
- WHEN integriq handles it
- THEN a job in `queued` exists with the data hash and no data, the result slot carries its id, and a later status poll moves it to `rendered` and dispatches `DocumentRenderedEvent` with the file reference
- @e2e exclude cross-app typed events; covered by PHPUnit with a stub requester and a mock-mode source

#### Scenario: A vendor error keeps the job and names the cause
- GIVEN the provider answers an error
- WHEN the render runs
- THEN the job is `failed` with `lastError` set and `DocumentRenderedEvent` carries the failure
- @e2e exclude failure path; covered by PHPUnit

### Requirement: Credentials are resolved by reference, never passed by value (REQ-DGV-003)

A vendor binding MUST resolve its API key or certificate through the
OpenRegister credential broker from the source's `credentialRef` at call
time. No credential MUST appear in a method argument, a log line, the
job object or the source page.

#### Scenario: A render with a credential reference
- GIVEN a source with a `credentialRef`
- WHEN a render runs
- THEN the material is resolved inside integriq and no key appears in any call argument or log line
- @e2e exclude covered by PHPUnit on the binding with a stub broker and a log assertion

### Requirement: Templates are listed from the vendor, not copied (REQ-DGV-004)

`listTemplates()` MUST return the vendor's template ids and names for a
source, and integriq MUST NOT store a copy of a vendor template. The
source page MUST show the list so an operator can hand a template id to
filinq's template admin.

#### Scenario: The operator sees the vendor's templates
- GIVEN a mock-mode Xential source with three templates
- WHEN the operator opens the source page
- THEN the three templates are listed with their ids and none is stored as an integriq object
- e2e: `tests/e2e/document-generation-source.spec.ts`

### Requirement: The bindings are catalog entries (REQ-DGV-005)

Integriq MUST seed `adapter:smartdocuments` and `adapter:xential` as
`catalog_item` objects in category "Document generation" with `mechanism:
mock-seeded`, dormant until a source is instantiated, following
`connector-catalog` REQ-001 and REQ-002.

#### Scenario: The catalog lists both vendors dormant
- GIVEN a fresh install
- WHEN an operator opens the Catalog page and filters on "Document generation"
- THEN both cards render with status "dormant"
- e2e: `tests/e2e/connector-catalog.spec.ts`
