---
kind: code
depends_on: []
---

# Proposal: connectors-digipoort-sbr-filing

## Summary

A bookkeeper who presses Submit on a VAT return in shillinq sends nothing today: the `digipoort-sbr` connection is a log adapter, and integriq has no Digipoort connector. This change adds one. A sibling app hands integriq a finished XBRL instance through an event, integriq delivers it to Digipoort over the WUS channel with the organisation's PKIoverheid certificate, polls the processing status, and reports every status back as an event.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from shillinq `tax-digipoort-filing`, merged on shillinq `development`. It is `build` by the decision rule.

shillinq `tax-digipoort-filing`, Cross-Project Dependencies: "integriq: a Digipoort/SBR connector under source slug `digipoort-sbr`. None exists on integriq development ... shillinq emits `nl.conduction.sbr.filing.requested` with `{sourceApp, objectType, objectUri, filingType, recipient, payloadFileUri}`, and integriq emits `nl.conduction.sbr.filing.status` with `{objectUri, deliveryReference, status, errors}`. The event names are a proposal for integriq to confirm." This change confirms them.

Rows in the shillinq matrix, both with `provider` integriq:

- `tax-vat-file`, "File the VAT return directly with the Belastingdienst." Five competitors rate yes. SnelStart: "De aangifte wordt verzonden in SBR-formaat via het beveiligde kanaal Digipoort" (https://kennisplein.snelstart.nl/klanten/s/article/btw-aangifte-algemene-informatie). Twinfield: "Als het PKI-certificaat en de Digipoort zijn ingesteld, dan kunnen btw-aangiften direct vanuit Twinfield worden verstuurd naar de Belastingdienst" (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/btw-aangifte-3041090). Moneybird (https://helpcenter.moneybird.nl/nl/articles/223650-de-btw-aangifte-indienen), Exact Online and Odoo (PKIoverheid certificate, SBR) as well.
- `tax-sbr`, "Submit filings to the Belastingdienst and KvK through SBR/XBRL." Exact Online and SnelStart rate yes (KvK deposit through Exact Jaarrekening and SnelStart Accountant Pro).

Digipoort is a national channel run by Logius. ADR-091 decision 6 puts a Dutch statutory shape in integriq, not in a leaf app.

## What integriq already has

- The Digikoppeling WUS profile: `WusProfileService::buildSignedRequest()` and `verifyResponse()` (`lib/Adapters/Digikoppeling/WusProfileService.php:77`, `:104`), `WsSecuritySigner`, and `PkiOverheidCredentialResolver::resolveSigningMaterial()` (`PkiOverheidCredentialResolver.php:87`), which resolves the certificate through the credential broker.
- Two-way TLS with a client certificate: `lib/Service/Mtls/MtlsTransportService.php` (spec `mtls-client-certificate-transport`).
- The pattern of a statutory send with a log provider, a registry of providers, retry and acknowledgement translation: `lib/Service/Rod/` (`RodService::sendBericht()`, `RodProviderInterface`, `LogRodProvider`) and its retry job `RodRetryJob`.
- The event-driven hand-off with idempotency and status events: `PeppolOutboundConsumer` and `PeppolTransmissionService` (spec `peppol-access-point-connector`, REQ-003 and REQ-004).

## What this change builds

1. A `digipoort-sbr` source type with a `log` provider and a `wus` provider, the certificate referenced through the broker, and the environment (preproduction or production) on the source.
2. A consumer for `nl.conduction.sbr.filing.requested` that stores an `sbr_filing` record, delivers the instance through the aanleverservice, and records the delivery reference.
3. A status poll through the statusinformatieservice until the filing reaches a final status, and one `nl.conduction.sbr.filing.status` event per status change, with the recipient's error codes on a rejection.
4. A catalogue item and a filings log page.

## Out of scope

- Building or validating the XBRL instance. That is the sending app's (shillinq `tax-digipoort-filing` D1 and D2).
- Filings other than the VAT return and the KvK deposit in the first release; the source takes any `filingType`, and new types need only a berichtsoort mapping.
- Obtaining the PKIoverheid certificate and the Digipoort registration. They are the organisation's.

## Impact

- New: `lib/Service/Digipoort/` (service, provider interface, `LogDigipoortProvider`, `WusDigipoortProvider`), a consumer registered on `ObjectCreatedEvent`, a status poll job, `sbr_filing` schema, a log page in the manifest.
- Changed: `lib/AppInfo/Application.php`, `appinfo/info.xml`, `lib/Settings/integriq_register.json`, `lib/Service/CatalogRegistryService.php`.

## Cross-project dependencies

- shillinq `tax-digipoort-filing` emits the request and listens for the status. Its `IntegriqSbrHandoffAdapter` and `SbrFilingStatusListener` use the event names and fields confirmed here.

## Risks

- A filing is sent twice after a timeout. Delivery is idempotent per `objectUri` and `filingType`; a retry first asks the status service for the stored delivery reference.
