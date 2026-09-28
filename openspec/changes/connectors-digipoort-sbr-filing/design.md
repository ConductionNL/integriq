# Design: connectors-digipoort-sbr-filing

Kind: code. Size M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **No Digipoort code.** `grep -rli "digipoort\|\bsbr\b\|xbrl"` over `lib/`, `openspec/` and `docs/` finds nothing.
- **WUS and certificates exist.** `WusProfileService::buildSignedRequest(certificateRef, bodyXml)` wraps a body in a SOAP envelope and signs it with WS-Security through `WsSecuritySigner::sign()`; `verifyResponse()` rejects an unsigned or wrongly signed answer. `PkiOverheidCredentialResolver::resolveSigningMaterial()` gets the certificate and key from the OpenRegister credential broker and fails closed. `MtlsTransportService` builds the two-way TLS options.
- **The statutory send pattern.** `RodService` (`lib/Service/RodService.php`) sends a bericht through the provider chosen on the active source (`resolveActiveSource()`, :357), translates the acknowledgement, and `retryFailed()` (:267) runs from `RodRetryJob`.
- **The hand-off pattern.** `PeppolOutboundConsumer` listens for `ObjectCreatedEvent` in integriq's `event` schema and passes `data` to a service that is idempotent per object and document type. The id comparison it needs is the one `peppol-readable-payloads-and-scoped-consumer` fixes; this consumer uses the same resolver from the start.
- **Catalogue.** `CatalogRegistryService::collectStaticDescriptors()` (`lib/Service/CatalogRegistryService.php:217`) lists adapters such as `adapter:digikoppeling` and `adapter:berichtenbox` with a category, a mechanism and standards.

## D1. One source type, two providers

A source with `type: digipoort-sbr`, `configuration.provider` `log` or `wus`, `configuration.environment` `preproduction` or `production`, `configuration.certificateRef` (broker reference to the PKIoverheid certificate), and the aanlever and status endpoint URLs per environment. `LogDigipoortProvider` answers a deterministic delivery reference and a scripted status sequence, so the path works before a certificate exists. `WusDigipoortProvider` builds the aanleveren and statusinformatie requests, signs them through `WusProfileService`, and sends them over `MtlsTransportService`.

## D2. The request and the record

The consumer takes `nl.conduction.sbr.filing.requested` with `{sourceApp, objectType, objectUri, filingType, recipient, payloadFileUri, identityNumber, identityType}`. `filingType` maps to a berichtsoort (`ob-aangifte` to the VAT return, `kvk-deponering` to the KvK deposit); `recipient` is `belastingdienst` or `kvk`. It stores an `sbr_filing` (`objectUri`, `sourceApp`, `filingType`, `recipient`, `payloadFileUri`, `deliveryReference`, `status`, `statusHistory`, `errors`, `attempts`) and is idempotent per `objectUri` and `filingType`. The instance is read from `payloadFileUri` the way the Peppol change reads a UBL (Nextcloud file id or absolute path).

## D3. Status by polling, reported per change

A job polls every filing that is not final, through the statusinformatieservice with the stored delivery reference. Every new status is appended to `statusHistory` and emitted as `nl.conduction.sbr.filing.status` with `{objectUri, deliveryReference, status, errors}`, where `status` is one of `delivered`, `accepted`, `rejected`, `failed`, and `errors` carries the recipient's codes and texts. A delivery that throws follows the retry budget and then the dead-letter surface, like a Peppol transmission.

## D4. Seen by an administrator

A catalogue item `adapter:digipoort-sbr` in category `Tax filing`, mechanism `mock-seeded`, standards `Digipoort WUS`, `SBR`, `PKIoverheid`. A Digipoort filings log page lists `sbr_filing` records with status and errors, carded on the Reports hub like the other protocol logs.

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| Delivery and status poll | Imperative providers and a job | An external statutory channel (ADR-031 exception). |
| Filing record and its states | Declarative schema with an `authorization` block for administrators | Data. |
| Log page | Declarative manifest page | Page configuration. |

## Seed data

A dormant source `digipoort-sbr` on the `log` provider, environment `preproduction`, no certificate; one `sbr_filing` for "Adviesbureau Kade B.V.", OB aangifte 2026 Q3, with the statuses delivered and accepted.

## Risks

- [Logius changes the WUS interface version] the berichtsoort map and endpoints sit on the source, so a version bump is configuration plus a provider test.
- [A certificate expires] the broker refusal fails the delivery closed and the filing shows the reason; nothing is marked delivered.
