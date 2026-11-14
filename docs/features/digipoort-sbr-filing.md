# Digipoort SBR filing

## Overview

Integriq files XBRL instances with the Belastingdienst and the KvK through Digipoort, the national channel Logius runs. A sibling app builds the instance (shillinq builds the VAT return), stores it in Nextcloud Files and asks integriq to file it. Integriq delivers it once, asks Digipoort how far it got until the recipient accepts or rejects it, and reports every status back to the sending app.

Building and validating the instance is the sending app's work. Obtaining the PKIoverheid certificate and the Digipoort registration is the organisation's.

## The request and the answer

A sending app raises a CloudEvent of type `nl.conduction.sbr.filing.requested` in integriq's `event` schema:

| Field | Meaning |
|---|---|
| `sourceApp` | The app asking, for example `shillinq` |
| `objectType`, `objectUri` | The object the filing is for |
| `filingType` | `ob-aangifte` (VAT return) or `kvk-deponering` (KvK deposit) |
| `recipient` | `belastingdienst` or `kvk` |
| `payloadFileUri` | The instance in Nextcloud Files: `nextcloud-file:<id>` or `/<user>/files/...` |
| `identityNumber`, `identityType` | The party the filing is for, as Digipoort identifies it |

Integriq answers with one `nl.conduction.sbr.filing.status` event per status change:

| Field | Meaning |
|---|---|
| `objectUri` | The object the filing is for |
| `deliveryReference` | Digipoort's kenmerk for the delivery |
| `status` | `delivered`, `accepted`, `rejected` or `failed` |
| `errors` | The recipient's error codes and texts on a rejection, or the reason on a failure |

One filing exists per `objectUri` and `filingType`. A repeated request for a filing that was already delivered sends nothing again.

## The Digipoort source

The source `digipoort-sbr` ships dormant on the `log` provider. The log provider makes no network call: a delivery answers a reference derived from the filing, and the status service answers delivered then accepted, or the sequence on `configuration.mockStatuses`. The whole path can be tried before a certificate exists.

To go live:

1. Store the PKIoverheid certificate in the credential broker and set `configuration.certificateRef` to its reference. Requests are signed with it (WS-Security).
2. Set the client certificate for two-way TLS under `configuration.authentication.mtls`.
3. Set `configuration.provider` to `wus` and `configuration.environment` to `preproduction` or `production`. The endpoints per environment are on `configuration.endpoints`.
4. Enable the source.

Without usable certificate material the WUS provider refuses the delivery, names the certificate, and sends nothing. An answer whose signature does not verify is rejected.

What changes between WUS versions sits on the source: the berichtsoort per filing type (`configuration.berichtsoorten`), the autorisatie adres, and the status codes that mean the recipient accepted the filing (`configuration.acceptedStatusCodes`). A status that carries a foutcode is a rejection.

## Retries and failures

A delivery that fails stays queued. The status job (`SbrFilingStatusJob`, every ten minutes) tries it again, up to three attempts in all; the filing is then `failed`, reported like any other status, and the connection alert group is notified. A filing type the source has no berichtsoort for, or an unknown recipient, fails at once, because trying again cannot help.

## Seeing the filings

The catalogue lists Digipoort under Tax filing. The Reports page has a Digipoort filings card: each filing with its recipient, filing type, delivery reference and status, and in the row detail the status history with times and the recipient's errors. Filings are visible to administrators only.

## Specification

`openspec/changes/connectors-digipoort-sbr-filing`.
