---
kind: code
depends_on: []
---

# Proposal: stuf-zkn-bridge

## Summary

Add a StUF-ZKN (StUF-ZKN 3.10, VNG/EGEM) bridge to OpenConnector — the
fleet's integration hub — translating inbound StUF-ZKN `zakLk01`/`edcLk01`
SOAP kennisgevingen (zaak/document create/update/vervallen) into an
upserted OR/ZGW zaak/document object, replying with `Bv03`/`Fo03`, and
translating outbound OR/ZGW zaak changes into `zakLk01` kennisgevingen
dispatched to a subscribed legacy StUF consumer over the SAME shared mTLS
transport `iwmo-ijw-adapter`/`fsc-connectivity`/`dso-connector-adapter`
already use. A narrow `StufZknProviderInterface` (send) is bound by
`LogStufZknProvider` (sandbox/dev default) and `StufZknClient` (generic
mTLS-first REST binding). `POST /api/stuf-zkn/inbound` is a signed SOAP
receiver; `POST /api/stuf-zkn/kennisgevingen` lets sibling apps (e.g.
procest) push a zaak change for translation+dispatch. A `StufZknRetryJob`
re-drives failed outbound sends. A `stuf_message` OR schema records every
inbound receipt and outbound attempt for observability, with idempotent
redelivery handling. Two small, genuinely shared pieces (XXE-hardened XML
parsing, the literal-leak-guard scan) were extracted from
`iwmo-ijw-adapter` into `lib/Service/Stuf/` and adopted by both bridges,
keeping `iwmo-ijw-adapter`'s own behaviour and test suite unchanged.

## Motivation

A large share of Dutch municipalities still run StUF-ZKN in their
surrounding systems (midoffice, DMS, belastingen, KCC). Industry research
on StUF-to-ZGW migration is consistent: the integration rework a
municipality faces when adopting a ZGW-API-native zaaksysteem is
substantial, commonly cited as 25-50% of the total procurement cost — and
migration tooling that avoids a rip-and-replace materially improves deal
odds. A StUF-ZKN↔ZGW/OR bridge lets a municipality adopt procest WITHOUT
first ripping out its StUF estate — the last translation gap in this
fleet's connector coverage. Per the user-mandated architecture, ALL
integrations live in OpenConnector; it translates the default
OpenRegister/ZGW object APIs into other standards, never re-implemented
per leaf app (per ADR-022). This is the natural next legacy-standard
connector after `iwmo-ijw-adapter` (StUF iStandaarden Wmo/Jeugdwet) and
`dso-connector-adapter` (DSO-LV).

## Capabilities

- `stuf-zkn-bridge` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `StufZknProviderInterface` abstraction
  with `Log` + `StufZknClient` (REST/mTLS) bindings, two translator classes
  (`InboundBerichtTranslator`, `OutboundKennisgevingTranslator`), a
  `StufZknAcknowledgementBuilder` (Bv03/Fo03), `StufZknSyncService`,
  `StufZknController`, `StufZknRetryJob`, a `stuf_message` OR schema, and
  two shared helper classes (`Stuf\StufXmlParser`, `Stuf\StufLiteralLeakGuard`)
  extracted from and adopted by `iwmo-ijw-adapter`.
- [ ] Project: `procest` (and other ZGW-API-hosting consumer apps) — no
  code change here; a zaak-status-transition module would target
  `POST /api/stuf-zkn/kennisgevingen` (documented cross-app contract only,
  see design.md §9).

## Scope

### In Scope

- `Stuf\StufXmlParser`/`Stuf\StufLiteralLeakGuard`: extracted from
  `iwmo-ijw-adapter`'s `InboundRetourTranslator`/`OutboundBerichtTranslator`,
  adopted by both bridges. `iwmo-ijw-adapter`'s own public behaviour,
  exception types, and existing PHPUnit suite are unaffected — re-verified
  green post-refactor.
- `InboundBerichtTranslator`: `zakLk01`/`edcLk01` SOAP kennisgeving ->
  normalised zaak/document representation. Honours all four StUF 3.01 core
  `verwerkingssoort` codes (T/W/I/V) and the `StUF:noValue`/`xsi:nil`
  convention. Raises `StufZknTranslationException` (never guesses/
  synthesises) when `referentienummer` or `identificatie` is missing/empty
  — literal-leak guard, tested per berichttype/verwerkingssoort.
- `OutboundKennisgevingTranslator`: OR/ZGW zaak object + verwerkingssoort
  (T/W/V) -> `zakLk01` XML envelope. Same literal-leak guard discipline;
  round-trips structurally through the inbound translator.
- `StufZknAcknowledgementBuilder`: `Bv03Bericht`/`Fo03Bericht` reply
  shaping, correlated via `StUF:crossRefnummer`. `Fo03` NEVER leaks
  internal exception detail — fixed, secret-free fault catalogue only.
- `StufZknProviderInterface` (`getProviderId`, `getConfigSchema`, `send`),
  `LogStufZknProvider` (sandbox, no network/secret), `StufZknClient`
  (generic REST binding routed through the SAME shared
  `MtlsTransportService`/`MtlsConfigResolver` `authentication.mode`
  pattern `IStandaardenClient`/`FscDirectoryClient`/`DsoClient` already
  use — no new TLS implementation).
- `StufZknSyncService`: `receiveInbound()` (translate, idempotent-by-
  referentienummer upsert into a configurable target register/schema
  defaulting to ZGW `zaak`/`enkelvoudiginformatieobject` vocabulary,
  persist `stuf_message`, reply Bv03/Fo03 — never throws out to the
  controller), `sendKennisgeving()` (translate + provider send + persist
  `stuf_message`), `retryFailed()` (per-message-isolated retry of failed
  outbound sends).
- `StufZknController`: `inbound()` (HMAC-signed SOAP receiver, mirrors
  `IwmoIjwController::inbound()`, always replies Bv03/Fo03 as raw XML,
  never a 500), `outbound()` (NC-session push endpoint, mirrors
  `IwmoIjwController::createBericht()`).
- `StufZknRetryJob`: hourly `TimedJob` retrying failed outbound sends —
  satisfies the fleet's orphaned-capability rule (route + job wired AND
  covered by tests proving invocation, not just declared).
- `stuf_message` OR schema (`direction`, `berichttype`, `referentienummer`,
  `verwerkingssoort`, `entiteittype`, `status`, `error`, `syncedAt`), plus
  the register's `schemas` list entry (double-checked against
  `components.schemas`).
- Idempotency: a redelivered inbound message with the same
  `referentienummer` never duplicates a `stuf_message` row or re-applies
  its OR write.
- Feature gating: `configuration.provider` (`log`|`rest`), default `log`.
  An unconfigured bridge reports `not_configured` cleanly, no HTTP; the
  inbound leg remains acknowledgeable even with no source configured.

### Out of Scope

- A live-verified municipal StUF-ZKN integration — no live connection was
  available in this environment (stated explicitly). Every namespace,
  element, attribute, and `Fo03` fault code is a documented assumption;
  see design.md §3/§11 "Open Questions".
- Outbound `edcLk01` (document → StUF estate) — only inbound document
  intake and outbound zaak kennisgeving are in scope.
- `isVan`/`heeftAlsInitiator`/`heeftBetrekkingOp` relation blocks
  (roltype/betrokkene graph) — this change targets the zaak/document's own
  fields; a natural, isolated follow-up.
- Feeding `zakLk01` through the `x-openregister-handoff`/`HandoffService`
  semantic-case-intake mechanism `open-formulieren-intake`/
  `dso-connector-adapter` use — deliberately NOT used here; see design.md
  §4 for the architectural rationale (a StUF `zakLk01` is a
  synchronization event, not a citizen-intake event).
- procest's own consuming module (zaak-status-transition -> outbound push)
  — a cross-app contract this change defines and documents, not implements
  in procest.
- A settings UI for entering/rotating StUF-ZKN credentials or certificate
  material — same convention as every other leaf connector; set via the
  existing config-write surface.

## Approach

Model the StUF-ZKN connection as an openconnector `Source`
(`type=stuf-zkn`) whose `configuration` selects a provider (`log`|`rest`),
carries `authentication` (token or mTLS, same shape as
`IStandaardenClient`/`DsoClient`), `organisatie` (this bridge's own
StUF `zender` code), and `targetRegister`/`targetSchema`/
`targetDocumentRegister`/`targetDocumentSchema` (the OR register/schema
this bridge upserts zaak/document objects into — configurable since these
slugs are legitimately tenant-specific, per procest's own
`ZgwService::RESOURCE_MAP` naming convention). A narrow
`StufZknProviderInterface` (`send`) is implemented by `LogStufZknProvider`
and `StufZknClient`. `StufZknSyncService` resolves the active source +
provider and drives `receiveInbound()` (inbound, signature-verified,
idempotent-by-referentienummer, upsert-by-identificatie) and
`sendKennisgeving()` (outbound). `StufZknController` stays a thin
HTTP/auth shell (mirrors `IwmoIjwController`). Details in design.md.

## New Dependencies

None. Reuses `guzzlehttp/guzzle` (already a dependency),
`Mtls\MtlsTransportService`/`MtlsConfigResolver` (from
`mtls-client-certificate-transport`, #213), `ActionAuthService`,
`WebhookSignatureService`, and `OCP\Security\ICrypto` (all already used by
existing leaf connectors in this app). No SOAP/XSD library added —
envelopes are hand-built/parsed via PHP's built-in `DOMDocument`/
`SimpleXMLElement`, mirroring `iwmo-ijw-adapter`'s identical choice.

## Impact

- New: `lib/Service/Stuf/{StufXmlParser,StufLiteralLeakGuard}.php`,
  `lib/Service/StufZkn/{StufZknNamespaces,StufZknProviderInterface,
  LogStufZknProvider,StufZknClient,InboundBerichtTranslator,
  OutboundKennisgevingTranslator,StufZknAcknowledgementBuilder}.php`,
  `lib/Service/StufZknSyncService.php`, `lib/Controller/StufZknController.php`,
  `lib/Cron/StufZknRetryJob.php`, `lib/Exception/{StufZknProviderException,
  StufZknTranslationException}.php`, `appinfo/routes.php` +
  `appinfo/info.xml` entries, a `stuf_message` schema in
  `lib/Settings/openconnector_register.json`.
- Modified (behaviour-preserving refactor only): `lib/Service/IwmoIjw/
  {InboundRetourTranslator,OutboundBerichtTranslator}.php` — now delegate
  to the two shared `Stuf\*` helper classes; existing test suite unmodified
  and green.
- Reused: `Mtls\MtlsTransportService`/`MtlsConfigResolver`, `ActionAuthService`,
  `WebhookSignatureService`, `OCP\Security\ICrypto`.

## Cross-Project Dependencies

- procest (or another ZGW-API-hosting consumer app) is the intended
  production target of this bridge's OR object writes (via configurable
  `targetRegister`/`targetSchema`) and the intended production consumer of
  `POST /api/stuf-zkn/kennisgevingen` (contract owned here; no procest code
  change in this PR).

## Risks

### Risk 1: The real StUF-ZKN wire shape (namespaces, Fo03 fault catalogue) may differ from the documented assumption

**Severity:** Medium — **Mitigation:** every assumed namespace/element/
attribute/fault-code is documented explicitly in design.md §3/§11,
grounded in the publicly published StUF 3.01 core standard and StUF-ZKN
3.10 sector-model naming convention; the two translators structurally
round-trip against each other (proven by test); the `log`/sandbox provider
makes the whole inbound-upsert-ack and outbound-translate-dispatch path
demonstrable end to end without a real credential; every assumption is
isolated to `lib/Service/StufZkn/*` alone should a correction be needed.

### Risk 2: The extraction of shared XML/literal-leak helpers could regress iwmo-ijw-adapter

**Severity:** Low — **Mitigation:** the extraction is behaviour-preserving
by construction (identical logic moved verbatim, delegated to via a
default-valued constructor param so existing zero-arg instantiation still
works); `iwmo-ijw-adapter`'s full existing test suite
(`InboundRetourTranslatorTest`, `OutboundBerichtTranslatorTest`,
`IwmoIjwSyncServiceTest`, `IwmoIjwControllerTest`, `IwmoIjwRetryJobTest`)
was re-run unmodified post-refactor and is green.

### Risk 3: A malformed or duplicate-delivered StUF message could corrupt or duplicate OR state

**Severity:** Low — **Mitigation:** `InboundBerichtTranslator` rejects any
message with an empty/missing `referentienummer`/`identificatie` BEFORE
any OR write; `StufZknSyncService::receiveInbound()` resolves idempotency
strictly by `referentienummer`, upserts strictly by `identificatie`, and
`verwerkingssoort=V` marks-vervallen rather than hard-deletes; a
redelivery of an already-`processed` message never re-touches the OR
object, only re-acknowledges (never a 500, never a guessed target).

## Rollback Strategy

The connector is additive. Revert by removing the new controller/services/
cron job/routes, the `stuf_message` schema entry, and reverting the two
`IwmoIjw/*Translator.php` files to their pre-extraction state (or simply
leaving the shared `Stuf\*` helpers in place, since they are backward-
compatible no-op-equivalent delegations) — no existing source, sync, rule,
or event behaviour changes, so removal cannot regress current
integrations.

## Open Questions

The exact StUF-ZKN per-element namespace assignment, the real `Fo03` fault
code catalogue, and PKIoverheid certificate issuance for a real
municipality are explicitly deferred (see "Out of Scope" / design.md §11)
— not blocking, since the sandbox provider makes the change self-contained
and demonstrable without any of them.
