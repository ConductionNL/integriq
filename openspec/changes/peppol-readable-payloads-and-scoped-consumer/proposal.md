---
kind: code
depends_on: []
---

# Proposal: peppol-readable-payloads-and-scoped-consumer

## Summary

No e-invoice a sibling app hands to integriq is sent today, and no inbound one can be read. The outbound consumer compares OpenRegister's numeric register and schema ids with slugs, so it drops every request (integriq issue #1222). When a request does get through, the access point receives the file reference instead of the UBL. And an inbound document is republished with the access point's own reference, which the receiving app cannot open. This change scopes the consumer on resolved ids, sends the UBL bytes, and stores every inbound document as a file the receiving app can read.

## Why

The owner-moves pass of 2026-09-28 handed two halves to integriq from shillinq `sales-einvoice-exchange`, which is merged on shillinq `development`. Both are `build` by the decision rule.

- **Peppol consumer id fix (#1222).** shillinq `sales-einvoice-exchange`, Cross-Project Dependencies: "`PeppolOutboundConsumer` ... compares `$object->getRegister()` with the slug `integriq` and `getSchema()` with `event`. OpenRegister stamps numeric ids onto the entity ... so the consumer may never match any event, integriq's own included." The comment of 28 Sep on ConductionNL/integriq#1222 confirms it at integriq `413357ec`: openregister `lib/Db/ObjectEntity.php:1181` and `:1190` now declare both getters, `SaveObject.php:3887-3888` stores ids, "so the method returns null for every `ObjectCreatedEvent`".
- **Readable inbound payload.** Same proposal: "the inbound CloudEvent carries a `payloadReference`. Shillinq reads it when it names a Nextcloud file the instance can read. If integriq's reference is anything else, integriq needs to store the inbound UBL where the receiving app can read it."

Rows in the shillinq matrix these halves serve:

- `sal-peppol-send`, "Send a sales invoice as a UBL e-invoice over Peppol." Provider integriq. Four competitors rate yes: Exact Online ("elektronische verkoopfacturen te verzenden via het Peppol-netwerk", https://www.exact.com/nl/producten/boekhouden/features-en-prijzen), Moneybird (https://helpcenter.moneybird.nl/nl/articles/227719-facturen-versturen-via-peppol), SnelStart (https://www.snelstart.nl/productnieuws/e-facturen-sturen-aan-de-overheid), Odoo (`addons/account_peppol/__manifest__.py:5`).
- `sal-einvoice-rejection`, "Be told when a customer rejects an e-invoice you sent." Exact Online and Odoo rate yes.
- `pur-ubl-import`, "Import a supplier's UBL e-invoice and book it without typing." Four competitors rate yes; Moneybird and Odoo name Peppol receipt.

Integriq's own row `nl-peppol` ("Send and receive e-invoices over Peppol") is rated built; the defect above means neither direction works for a sibling app.

## What integriq already has

- `PeppolOutboundConsumer` (`lib/Service/PeppolOutboundConsumer.php`), registered for `ObjectCreatedEvent` at `lib/AppInfo/Application.php:268`, and `PeppolTransmissionService::handleOutboundRequested()` (`lib/Service/PeppolTransmissionService.php:187`), idempotent per `objectUri` and `documentType`.
- The signed inbound webhook `PeppolController::inbound()` (`lib/Controller/PeppolController.php`), which republishes an inbound notification through `handleInboundDocument()` (`PeppolTransmissionService.php:390`) with the reference exactly as the access point sent it.
- `EventService::getSelfSchemaIds()`, which `CloudEventListener::resolveSelfSchemaIds()` (`lib/EventListener/CloudEventListener.php:259`) already uses to compare ids instead of slugs.
- OpenRegister's `FileService::addFile(objectEntity, fileName, content)`, used by `OpenFormulierenIntakeService` (`lib/Service/OpenFormulierenIntakeService.php:407`) to attach a fetched file to an object.

## What this change builds

1. The consumer matches on the resolved ids of register `integriq` and schema `event`, and ignores the same `type` in any other register or schema.
2. The outbound path reads the UBL named by `payloadFileUri` and submits its bytes; an unreadable reference fails the transmission with the reason.
3. An inbound document is fetched from the access point, stored as a file on a `peppol_inbound_document` object, and republished with a reference the receiving app can read.

## Out of scope

- The shillinq side of both directions (`sales-einvoice-exchange`, `purchasing-supplier-invoice-intake`).
- Peppol invoice responses (answering a rejected self-billed invoice), which shillinq names as a later need.
- The `SyncRefResolver::lookupSourceUuidByInt()` probe named in #1222 as a second finding. It is a sync defect, not a Peppol one, and stays on the issue.

## Impact

- Changed: `lib/Service/PeppolOutboundConsumer.php`, `lib/Service/PeppolTransmissionService.php`, `lib/Service/Peppol/PeppolAccessPointProviderInterface.php` and both providers, `lib/Controller/PeppolController.php`.
- New: a `peppol_inbound_document` schema in `lib/Settings/integriq_register.json`.

## Cross-project dependencies

- shillinq `sales-einvoice-exchange` writes the outbound request into register `integriq`, schema `event` (its D1) and reads the inbound reference (its D3). Its task 1.3 live check passes once this lands.
- shillinq `purchasing-order-dispatch-and-receipt` uses the same outbound consumer for purchase orders.

## Risks

- A fix that inverts the guard again. The tests assert both directions: an event in integriq's `event` schema starts a transmission, and the same payload in any other schema does not. That pairing is what #1222 lacked twice.
