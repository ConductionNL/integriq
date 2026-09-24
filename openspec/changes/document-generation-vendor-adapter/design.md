# Design: document-generation-vendor-adapter

Kind: code. One interface, three bindings, one schema, two typed events,
two catalog entries.

## D1. Provider seam

`lib/Service/DocumentGeneration/DocumentGenerationProviderInterface.php`:
- `getProviderId(): string`
- `getConfigSchema(): array`
- `listTemplates(array $sourceConfiguration): TemplateList`
- `render(array $sourceConfiguration, string $templateId, array $data, RenderOptions $options): RenderResult`
- `status(array $sourceConfiguration, string $externalId): RenderStatus`
- `fetch(array $sourceConfiguration, string $externalId): FetchedFile`

Bindings resolve by `providerId` from the source configuration, as the
digital post providers do. `LogDocumentGenerationProvider` answers a
one-page placeholder PDF naming the template and the data hash.
`SmartDocumentsProvider` and `XentialProvider` post to the vendor's REST
API; both are asynchronous on the vendor side, so `render` returns an
external id and `status` polls until `fetch` can return the file.

## D2. `documentGenerationJob`

`sourceId`, `providerId`, `templateId`, `dataHash` (SHA-256 of the
canonical JSON of the data), `requestedBy` (`{app, objectRef}`),
`externalId`, `resultFileRef`, `format` (`pdf`, `docx`), lifecycle
`queued`, `rendered`, `failed`, `lastError`, `requestedAt`, `renderedAt`.
The data is never stored on the job: the requesting app holds its own
object, and a second copy of a beschikking's content in integriq is what
the 2026-09-11 registry-subscription rejection was about.

## D3. Typed events (ADR-041)

- In: `DocumentRenderRequestedEvent(sourceId, templateId, data, options,
  requestedBy)` with a result slot carrying the job id or a structured
  refusal. Filinq dispatches it for a template whose `engine` names a
  vendor source.
- Out: `DocumentRenderedEvent(jobId, requestedBy, resultFileRef, format)`
  on `rendered`, and the same event with `failed` and `lastError` on
  failure, so filinq files the result or records the failure on its
  `generatedDocument` without polling.

## D4. Credentials

The source configuration holds a `credentialRef`; the binding resolves
the vendor's API key or client certificate through the OpenRegister
credential broker at call time. No key appears in a method argument, a
log line or the job object.

## D5. Catalog

Two `catalog_item` entries, `adapter:smartdocuments` and
`adapter:xential`, category "Document generation", `mechanism:
mock-seeded`, dormant until a source is instantiated from them
(`connector-catalog` REQ-001 and REQ-002).

## D6. The filinq side, described so the seam is clear

Not integriq's code. Filinq's `template` gets an `engine` property:
`twig` (default, today's path) or `vendor:<sourceSlug>`. For a vendor
engine `DocumentService::generateDocument()` dispatches
`DocumentRenderRequestedEvent`, stores the job id on its
`generatedDocument`, and on `DocumentRenderedEvent` files the result
through its existing storage path and runs its PDF/A-3b conversion. dossiq
keeps calling filinq's contract (ADR-075) and never sees the vendor.

## Risks

- A vendor that answers slowly. The job stays `queued`; a scheduled job
  polls `status` with backoff; filinq's caller sees "rendering" on the
  generated document instead of a timeout.
- Personal data in transit to a vendor. That is the municipality's
  contract with the vendor; integriq logs the data hash, not the data,
  and the source page names the vendor and the processing agreement
  reference the admin typed.
- Two engines producing different output for one template. A template
  has one `engine`; there is no fallback from vendor to Twig, because a
  beschikking rendered by the wrong engine is a legal defect, not a
  degraded mode.
