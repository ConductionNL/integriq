---
kind: code
---

# A real Berichtenbox client, built on the interface Logius publishes

Follows `berichtenbox-digital-post-adapter` and `digital-post-service-account-and-log-redaction` (integriq#2574). Spec only. Ruben reviews this before anything is built (2026-10-07: "spec first, then build").

## Why

**No letter reaches a MijnOverheid Berichtenbox today, on any instance.** The `BerichtenboxClient` binding resolves to one of two classes, and neither sends anything:

- `BerichtenboxClientMock` returns a made-up `bbk-mock-…` reference and `deliveryStatus: queued` (`lib/Adapters/Berichtenbox/BerichtenboxClientMock.php:58-66`). The provider marks such a send as simulated (`lib/Service/DigitalPost/BerichtenboxProvider.php:163`).
- With `logius.berichtenbox.feature_flag=1`, `BerichtenboxClientUnavailable` throws on every call (`lib/Adapters/Berichtenbox/BerichtenboxClientUnavailable.php:42-46`, bound in `lib/AppInfo/Application.php:859-876`).

The live class, `BerichtenboxClientHttp`, is named in three docblocks and was never written. The idp-live run of 2026-10-07 had to stand a fake Postex in for Berichtenbox for exactly this reason (`~/memcap-work/fleet-appdir/idp-live/commands.md`, header). dossiq letters now travel through integriq's digital post as the `digital-post` account (integriq#2574, b3c7aa92). They stop at this binding.

**The interface the code was written against does not exist.** The code and its spec describe a "BBK 1.7 REST API" with "OAuth 2.0 client credentials", "HMAC-signed delivery-receipt webhooks" in `X-Logius-Signature` and signed outbound envelopes (`lib/Adapters/Berichtenbox/BerichtenboxClient.php:6-18`, `:76-89`). The only place "BBK 1.7" appears outside this repo is Conduction's own pipelinq docs. Logius publishes something else:

- Two Digikoppeling interfaces. ebMS for sending letters and for bulk subscription queries. WUS (SOAP) for a synchronous subscription check of up to 250 BSNs.
- Authentication by two-way TLS with a PKIoverheid certificate that carries the sender's OIN. TLS 1.2.
- **No payload signing.** "Het is niet mogelijk om de payload van het ebMS bericht digitaal te ondertekenen." "Het is niet mogelijk SOAP berichten te Signen."
- No OAuth, no webhooks, no HMAC. A result comes back as an ebMS message, `GLOBE-R-BV-Result`.
- No read status. "Organisaties krijgen geen inzicht of en wanneer berichten zijn gelezen."
- No replies. "Burgers hebben zelf niet de mogelijkheid om berichten terug te sturen naar de organisatie."

Source: Technische Aansluithandleiding MijnOverheid Berichtenbox, version 1.6.4, read on 2026-10-07 at https://www.logius.nl/domeinen/interactie/mijnoverheid/documentatie/technische-aansluithandleiding-mijnoverheid-berichtenbox, with the XSD package `logius-mijnoverheid-berichtenbox-xsd-2026.zip`. Design section 1 lists every source.

**One blocker named in the old proposal falls away.** That proposal says in-process signing waits on OpenRegister's `CredentialBrokerService::issueSigningMaterial`. Berichtenbox signs nothing, so the live client needs a transport certificate, not signing material. Integriq already has a transport for that (`mtls-client-certificate-transport`, `lib/Service/Mtls/`).

## What changes

- `BerichtenboxClient` is rewritten to the published interface. Three operations: deliver a letter (`GLOBE-R-BV-Request` over ebMS), check subscriptions (`ValidateAbonnementen` over WUS), and collect results (`GLOBE-R-BV-Result`). `verifyWebhook()` goes: there is no webhook to verify.
- A live binding, `BerichtenboxClientHttp`, sends the ebMS leg through a Digikoppeling ebMS adapter that the operator runs. Integriq speaks that adapter's REST API. The WUS leg goes directly over integriq's mTLS transport.
- Before every send the binding checks the recipient's subscription. A BSN that is not subscribed to the sender is refused with `not_subscribed` before anything leaves the instance.
- The letter is built to the official XSD and checked against it before it is sent: subject at most 50 characters, text at most 4000, at most two PDF attachments of 500 kB together.
- The status job collects results. `Verwerkt` moves a letter to `delivered`. Every other code moves it to `failed` with the code and stage in `lastError`. Berichtenbox letters never reach `read`.
- `pollInbound()` returns nothing for a live Berichtenbox source, because the Berichtenbox has no inbound direction.
- A contract-faithful fake, built from the official XSDs and WSDL, stands in for Logius in tests and live proofs.
- The opt-out list and the category rules do not change. A Berichtenbox subscription is a second check, held by Logius, on top of them.

## Capabilities

### Modified capabilities

- `digital-post-adapter`: REQ-DPA-004 and REQ-DPA-006 are rewritten. REQ-DPA-008 to REQ-DPA-014 are added.

## Impact

- integriq: `lib/Adapters/Berichtenbox/`, `lib/Service/DigitalPost/BerichtenboxProvider.php`, `lib/BackgroundJob/DigitalPostStatusJob.php`, `lib/AppInfo/Application.php`, the `adapter:berichtenbox` catalog entry in `lib/Service/CatalogRegistryService.php`, the `digitalPostMessage` schema (two fields).
- dossiq: no code change. Its Berichtenbox letters stop being simulated once a source is live. dossiq must not wait for `read` on a Berichtenbox letter.
- Operators: a Berichtenbox connection needs a Logius aansluiting, a PKIoverheid certificate and an ebMS adapter. Design section 9 lists what Ruben has to request.
- Logius is building a successor (FBS, with BBO). The migration was suspended in June 2026 and will not happen in 2026. No BBO interface is published. This change targets the interface that is live today.
