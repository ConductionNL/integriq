# Proposal: stuf-zds-case-client

kind: capability. Cites ADR-041 (cross-app commands via typed events), ADR-118 (procedures are
configuration: standards adapters live in integriq) and Ruben's decision 193. Paired with the dossiq
change that collapses dossiq's StUF-ZKN outbound surface onto this client.

## Summary

Integriq becomes the StUF-ZDS client for cases. Dossiq built five outbound case messages itself
(creeerZaak, actualiseerZaak, geefZaakdetails, genereerZaakIdentificatie, vrijBericht), with its own
transport, circuit breaker, retry job and credential store. Those move here, onto the StUF-ZKN
source integriq already has:

1. `StufZdsMessageBuilder` builds the envelopes (from dossiq's StufMessageBuilder, outbound half).
   The caller passes the zaaktype omschrijving: mapping case types to zaaktypen stays the calling
   app's configuration.
2. `StufZdsCaseClient` checks the source's circuit, sends, classifies the answer, retries transient
   failures after 5 s, 30 s, 2 min and 10 min, and audits every message as a `stuf_message` row
   (from dossiq's StufAdapterService and StufOutboundTransport).
3. `StufZknClient::transmit()` sends without throwing and reports status, body, duration and a
   transport fout (from dossiq's StufHttpClient). The source gains an `authentication.mode` of
   `wsse` and an `authentication.wsse` UsernameToken.
4. `StufZdsCircuitBreaker` (from CircuitBreakerService), `StufZdsCredentialResolver` (from
   StufVaultService; the password is encrypted on the source with ICrypto instead of a `vault://`
   reference in app config), `StufZdsResponseReader` (from StufMessageParser) and
   `StufZdsRetryJob` (from StufRetryJob).
5. `StufZdsCaseRequestedEvent` and its listener: the ADR-041 command a sibling app dispatches.
   `StufZdsCaseConcludedEvent` announces how a kennisgeving ended, including after retries.

## Deliberate changes against the dossiq original

- A StUF fout in the body decides whether a failure is transient. SOAP 1.1 answers every fault with
  HTTP 500, and dossiq retried permanent refusals four times because of it.
- An answer carrying a DOCTYPE is refused. Dossiq's parser loaded with LIBXML_NOENT.
- genereerZaakIdentificatie is the ZDS 1.2 `genereerZaakIdentificatie_Di02` (dossiq sent a `_Du01`),
  and the SOAPAction is `<zkn namespace>/<functie>_<berichtcode>` (dossiq sent the bare functie).
- actualiseerZaak sends `einddatum` (dossiq sent an `endDate` element, which StUF-ZKN does not have).
- A retry that meets an open circuit waits out the cooldown instead of being dropped.
- A concluded `stuf_message` row keeps no envelope; it is kept only while a retry needs it.

## Out of scope

- Migrating dossiq's configured `stufEndpoint` objects. The paired dossiq change names an integriq
  source per endpoint; secrets are entered again in integriq, never copied (see
  `absorb-dossiq-deliveries` phase 2).
- The inbound StUF-ZKN receiver: integriq's `StufZknController` already has it.
