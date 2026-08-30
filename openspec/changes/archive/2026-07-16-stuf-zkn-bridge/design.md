# Design: stuf-zkn-bridge

## 1. Motivation, verified-at-HEAD context

A large share of Dutch municipalities still run StUF-ZKN (the legacy
GEMMA/VNG SOAP/XML case-management message standard) in their surrounding
systems — midoffice, DMS, belastingen, KCC. Industry research is
consistent: migrating a municipality off StUF to the newer ZGW APIs
("StUF to ZGW API migration") creates real integration rework for the
receiving system, and that migration effort is commonly cited as 25-50% of
a zaaksysteem procurement's total cost — meaning migration tooling that
avoids a rip-and-replace materially improves a deal's odds. Per the
user-mandated architecture, **openconnector is the fleet's integration
hub**: it translates the default OpenRegister/ZGW object APIs into other
standards' shapes, never re-implemented per leaf app (mirrors
`iwmo-ijw-adapter`, `dso-connector-adapter`, `zgw-version-translation`).
A StUF-ZKN↔ZGW/OR bridge lets a municipality adopt procest **without**
first ripping out its StUF estate — the last translation gap identified in
this fleet's connector coverage.

**Verified at HEAD (2026-07-16)**: `git log --oneline -20` on `development`
shows `mtls-client-certificate-transport` (#213) merged immediately before
this change, and `iwmo-ijw-adapter`/`dso-connector-adapter` both already
shipped hand-built StUF-style envelope handling via `DOMDocument`
(`lib/Service/IwmoIjw/{Outbound,Inbound}*Translator.php`). No prior change
in this app builds a true StUF-ZKN 3.10 SOAP envelope (namespaced,
`entiteittype`/`verwerkingssoort` attributes, `Bv03`/`Fo03` reply berichten)
— `iwmo-ijw-adapter`'s envelope is a deliberately simplified,
non-namespaced `<Bericht>` shape, honestly scoped to iStandaarden
Wmo/Jeugdwet only (see its own design.md). This change is the first true
StUF-ZKN SOAP implementation in the fleet.

## 2. Shared-envelope extraction decision

**Read first, verified at HEAD**: `lib/Service/IwmoIjw/OutboundBerichtTranslator.php`
and `InboundRetourTranslator.php` (iwmo-ijw-adapter, 2026-07-14). Their
envelope shape is fundamentally different from real StUF-ZKN: no SOAP
envelope, no `StUF`/sector XML namespaces, no `entiteittype`/
`verwerkingssoort` attributes, no `xsi:nil`/`noValue` convention. Because
of that, a shared "StUF envelope builder" covering the actual message
structure is **not** a genuine extraction target — mirroring the shape
would just be copy-pasting a different problem.

What genuinely IS identical logic, byte-for-byte, between the two
translators:

1. **XXE-hardened XML parsing** — `InboundRetourTranslator::parseXml()`'s
   exact `LIBXML_NONET`-only, error-collecting, never-throws-from-here
   parse routine.
2. **The literal-leak guard's final scan** —
   `OutboundBerichtTranslator::assertNoUnresolvedPlaceholder()`'s exact
   `{{...}}`/`%%UNRESOLVED%%` regex check on the fully rendered XML.

**Decision**: extract exactly these two, and only these two, into
`lib/Service/Stuf/StufXmlParser.php` and
`lib/Service/Stuf/StufLiteralLeakGuard.php`. Both `IwmoIjw\InboundRetourTranslator`
and `IwmoIjw\OutboundBerichtTranslator` were refactored to delegate to the
shared classes (constructor-injected, with a `new X()` default so existing
zero-arg call sites and the existing test suite's `new InboundRetourTranslator()`
keep working unchanged) — their own public behaviour, exception types, and
existing PHPUnit suites are unaffected; re-verified green post-refactor
(`InboundRetourTranslatorTest`, `OutboundBerichtTranslatorTest`,
`IwmoIjwSyncServiceTest`, `IwmoIjwControllerTest`, `IwmoIjwRetryJobTest` —
all pass unmodified). `stuf-zkn-bridge`'s own translators use the same two
shared classes. The rest of `stuf-zkn-bridge`'s envelope-building logic
(true StUF-ZKN SOAP shape, `entiteittype`/`verwerkingssoort` handling,
`Bv03`/`Fo03` shaping) is NOT shared — it lives in `lib/Service/StufZkn/`,
mirroring the `iwmo-ijw-adapter`'s own dedicated directory, because it is
genuinely a different message family with no real overlap beyond the two
extracted pieces.

## 3. StUF-ZKN element/attribute assumptions — NO LIVE ENDPOINT

**No live municipal StUF-ZKN endpoint was available in this environment to
verify the exact wire shape against.** Every namespace URI, element name,
and attribute below is an explicit, documented assumption grounded in the
publicly published StUF 3.01 core standard and the StUF-ZKN 3.10
(EGEM/VNG) sector model naming conventions, mirroring `iwmo-ijw-adapter`'s
and `dso-connector-adapter`'s identical honesty requirement for their own
un-verified transports. A future revision with real StUF-ZKN test-endpoint
access should correct anything below without needing to touch the provider
seam, controller, or sync-service orchestration — isolated entirely to
`lib/Service/StufZkn/{InboundBerichtTranslator,OutboundKennisgevingTranslator,
StufZknAcknowledgementBuilder}.php`.

### 3.1 Namespaces (`StufZknNamespaces`)

| Prefix | URI | Carries |
|---|---|---|
| `soap:` | `http://schemas.xmlsoap.org/soap/envelope/` | SOAP 1.1 envelope/body |
| `StUF:` | `http://www.egem.nl/StUF/StUF0301` | Core reusable building blocks: `berichtcode`, `zender`/`ontvanger` (+ their `organisatie` child), `referentienummer`, `tijdstipBericht`, `crossRefnummer`, `entiteittype`, `mutatiesoort`, `indicatorOvername`, and the `entiteittype`/`verwerkingssoort`/`noValue` **attributes** |
| `zkn:` | `http://www.egem.nl/StUF/sector/zkn/0310` | The `zakLk01`/`edcLk01` message wrappers, `stuurgegevens`/`parameters`/`object` elements, and every zaak/document domain field (`identificatie`, `omschrijving`, `zaaktype`, `titel`, ...) |
| `xsi:` | `http://www.w3.org/2001/XMLSchema-instance` | `xsi:nil` |

This namespace split (core StUF building blocks vs. sector-specific
`zkn:` domain fields) mirrors how the real StUF-ZKN XSDs reference the
core `StUF0301.xsd` complex types from the sector schema — the exact
element-by-element namespace assignment (e.g. whether `stuurgegevens`
itself is `zkn:` or `StUF:`) is the single largest unverified assumption
in this change; the chosen split renders and round-trips consistently
through both directions of this bridge's own translators (proven by
`OutboundKennisgevingTranslatorTest::testRenderedEnvelopeRoundTripsThroughInboundTranslator()`),
but has not been checked against a real municipality's StUF-ZKN gateway.

### 3.2 `stuurgegevens`

`berichtcode` (`Lk01` on kennisgevingen, `Bv03`/`Fo03` on replies),
`zender.organisatie`, `ontvanger.organisatie`, `referentienummer`
(REQUIRED — the idempotency/correlation key, see §5), `tijdstipBericht`
(`YmdHis`), `entiteittype` (`ZAK`|`EDC`), and on replies only
`crossRefnummer` (correlates the reply back to the kennisgeving's own
`referentienummer`). This bridge does not model `zender`/`ontvanger`'s
`applicatie`/`administratie`/`gebruiker` sub-fields — only `organisatie`,
kept intentionally minimal since no live endpoint exists to verify a
richer shape's necessity against.

### 3.3 `object` (the entity payload) — `entiteittype`/`verwerkingssoort`

The `object` element carries two StUF-namespaced **attributes**:
`StUF:entiteittype` (`ZAK` for a zaak, `EDC` for a document) and
`StUF:verwerkingssoort`, whose four StUF 3.01 core values this bridge
implements exactly as documented in
`InboundBerichtTranslator`'s class docblock:

| Code | Meaning | This bridge's handling |
|---|---|---|
| `T` | Toevoeging (create) | Upsert by `identificatie` (create or, on redelivery, idempotent overwrite) |
| `W` | Wijziging (non-identifying attribute update) | Upsert by `identificatie` |
| `I` | Wijziging van een identificerend gegeven | Same upsert-by-`identificatie` path as `W` — **documented limitation**: this bridge does not attempt a rename/re-key operation (StUF's `I` semantics imply the identifying attribute itself changed, which would require correlating the OLD identificatie to find the record; no live endpoint exists to verify whether real senders populate an old/new pair or expect the receiver to already know the mapping) |
| `V` | Vervallen (logical delete) | Marks the existing OR record `status: vervallen` — **never a hard delete** (mirrors this fleet's "never destroy data on an external signal" convention). A `V` for an `identificatie` with no existing record is a no-op (idempotent — nothing to vervallen) |

Outbound (this bridge → StUF consumer), only `T`/`W`/`V` are emitted — `I`
is not a code this bridge's own outbound leg has any reason to produce
(OR/ZGW zaak identifiers are stable once created in this fleet's model).

### 3.4 `noValue`/`xsi:nil` convention

An element explicitly marked absent per StUF's own convention:
`<zkn:toelichting StUF:noValue="geenWaarde" xsi:nil="true"/>`. This is
read as `null`, distinct from the element being entirely absent from the
document (also `null`) and distinct from a present-but-empty text node
(trimmed to `''`, also normalised to `null` — this bridge never
distinguishes "explicitly nil" from "empty string" on read, only on
*write*, where a genuinely absent optional value is rendered using the
nil convention rather than an empty tag, per the literal-leak guard's
"never emit an empty tag" rule).

### 3.5 `zakLk01` — zaak fields (inbound + outbound)

`identificatie` (REQUIRED — the literal-leak-guarded business key),
`omschrijving`, `toelichting` (optional), `zaaktype.code`/`.omschrijving`,
`registratiedatum`, `startdatum`, `einddatumGepland`, `einddatum`,
`archiefnominatie`, `betalingsIndicatie`, `status` (outbound only, when a
status-change kennisgeving carries one). No `isVan`/`heeftAlsInitiator`/
`heeftBetrekkingOp` relation blocks (roltype/betrokkene wiring) are
modelled — out of scope for this change's case-relevant create/update/
vervallen/document surface; a documented gap, not a silent omission (see
§9).

### 3.6 `edcLk01` — document fields (inbound only, per scope)

`identificatie` (REQUIRED), `titel`, `formaat`, `taal`, `creatiedatum`,
`ontvangstdatum`, `vertrouwelijkAanduiding`, `auteur`, `versie`,
`bestandsnaam`, and `isRelevantVoor.gerelateerde.identificatie` (the
related zaak's identificatie — required to know which OR zaak the
document upserts alongside; if the related zaak is not yet known to this
bridge, the document still upserts into its own target register/schema
independently, `zaakIdentificatie` recorded on the row for the consuming
app to resolve). Outbound `edcLk01` is explicitly out of scope (see §9) —
the task scope names outbound zaak/status kennisgeving only.

### 3.7 `Bv03`/`Fo03` reply berichten

`Bv03Bericht` (bevestiging): `stuurgegevens` only, `crossRefnummer` set to
the inbound message's own `referentienummer`. `Fo03Bericht`
(foutbericht): `stuurgegevens` (same correlation) plus `StUF:body` —
`code` (a small fixed catalogue: `StUF055`
"er is een fout opgetreden"/"de ontvangende applicatie is niet
beschikbaar", `StUF058` "berichttype niet ondersteund", `StUF063`
"bericht onvolledig of onjuist" — these codes are a plausible,
StUF-catalogue-shaped guess, not verified against the real, complete
StUF foutcode enumeration), `plek` (always `server` — this bridge never
claims the fault was the client's), `omschrijving` (the fixed catalogue
text). **HARD REQUIREMENT, verified by test**: `omschrijving` is NEVER
derived from a caught exception's own message — `StufZknAcknowledgementBuilder::buildFo03()`
takes a stable `$reason` key into its own fixed catalogue, never the raw
exception text, so an internal error (a stack trace, an SQL fragment, a
file path) can never leak onto the wire (`StufZknAcknowledgementBuilderTest::testFo03NeverLeaksInternalDetail()`).

## 4. Inbound routing decision: direct OR object path, not semantic-case-intake handoff

The task explicitly offered a choice: "creating or updating via the OR
object path, or feeding the semantic-case-intake handoff like
open-formulieren/dso did." **Decision: direct OR object path** (upsert via
`ObjectService::findAll()`+`saveObject()`), NOT the `x-openregister-handoff`
mechanism `open-formulieren-intake`/`dso-connector-adapter` use.

**Rationale**: the handoff mechanism exists for *intake* — an external
party submitting a NEW, often incomplete, human-review-worthy request that
needs mapping into this fleet's generic `ns#Case` contract before a
caseworker decides whether/how it becomes a case. A `zakLk01` is
structurally different: it IS an existing municipal case record, already
fully structured per the StUF-ZKN schema, arriving from a system-to-system
integration (the legacy StUF estate) that is *already* the system of
record for that case in the sending municipality's world. Treating a
`zakLk01` create/update/vervallen as a "human reviews an intake queue"
event would be architecturally wrong — it is a synchronization event, not
an intake event, closer in spirit to what `SynchronizationService`
already does for object-to-object mirroring elsewhere in this app than to
what `HandoffService` does for citizen-submitted forms. Direct upsert is
also simpler and fully testable without inventing a queued-record +
authenticated-completion-endpoint step that this specific message type
does not need (StUF senders expect an immediate `Bv03`/`Fo03`, not an async
review queue).

**Target register/schema**: per procest's own `ZgwService::RESOURCE_MAP`
(`git -C ../procest show` verified at HEAD — `'zaken' => ['zaken' =>
'zaak', ...]`, `'documenten' => ['enkelvoudiginformatieobjecten' =>
'enkelvoudiginformatieobject', ...]`), the canonical ZGW Zaken/Documenten
API resource-name vocabulary this bridge targets is `zaak`/
`enkelvoudiginformatieobject`. Procest's own OR register/schema slugs
hosting those resources are resolved dynamically per-tenant via
`ZgwMappingService` — genuinely configurable in this fleet, not a fixed
constant (mirrors the "OR register-import" family's established lesson
that register/schema slugs are legitimately tenant-specific). This bridge
therefore exposes `configuration.targetRegister`/`targetSchema` (zaak) and
`configuration.targetDocumentRegister`/`targetDocumentSchema` (document)
on its `source`, defaulting to `zaken`/`zaak` and
`documenten`/`enkelvoudiginformatieobject` respectively — sensible,
ZGW-vocabulary-aligned defaults that a deployment overrides to point at
whatever register/schema its procest (or other ZGW-API-hosting) instance
actually uses.

## 5. Idempotency design

**Requirement**: a redelivered StUF message (same `stuurgegevens.referentienummer`)
must not duplicate — no second `stuf_message` audit row, no repeated OR
upsert. StUF senders (per the standard's own delivery-retry expectation)
redeliver whenever they do not receive a timely `Bv03`.

**Mechanism**: `StufZknSyncService::receiveInbound()` first looks up an
existing `stuf_message` row by `(direction=inbound, referentienummer)`. If
one exists with `status=processed`, the method short-circuits straight to
a fresh `Bv03` (re-acknowledging, since the sender legitimately needs
that ack) WITHOUT re-running the OR upsert or writing a second audit row.
If an existing row exists but is NOT `status=processed` (e.g. a prior
attempt failed), the redelivery is treated as a legitimate retry: the
translation/upsert runs again and the SAME row is updated in place (never
a second insert) — this is the one case where "idempotent" means
"retry-until-success on the same row," not "no-op," matching how a StUF
sender's own redelivery-on-failure expectation actually works. Proven by
`StufZknSyncServiceTest::testRedeliveredInboundMessageDoesNotDuplicate()`.

**Not implemented**: cross-referentienummer deduplication of two
DIFFERENT `referentienummer`s that happen to describe the same
`identificatie` (e.g. a sender that generates a fresh reference per retry
rather than reusing one) — the correlation key this bridge trusts is
exactly the one the StUF standard defines for this purpose
(`referentienummer`); a sender that violates its own standard's retry
contract is outside what idempotency at this layer can reasonably cover
(the OR-level upsert-by-`identificatie` in §4 still prevents a full
duplicate CASE record even in that scenario — only the audit-log
duplication is a residual risk).

## 6. mTLS reuse — no new TLS implementation

`lib/Service/StufZkn/StufZknClient.php` dispatches through the SAME shared
`MtlsTransportService`/`MtlsConfigResolver`/`authentication.mode`
(`token`|`mtls`) pattern `IStandaardenClient`/`FscDirectoryClient`/
`DsoClient` already use (`mtls-client-certificate-transport`, #213,
verified merged immediately prior to this change on `development`). Real
StUF-ZKN municipal endpoints are typically PKIoverheid-secured (network/
Suwinet-style trust plus, where internet-routable, mutual TLS) — this is
the FIRST adapter in the fleet whose primary expected transport is mTLS
rather than token-auth-with-mTLS-as-an-added-option (iwmo-ijw/DSO both
originally shipped token-only and had mTLS added later); `StufZknClient`
ships with both from day one, `token` remaining the default/pre-production
fallback for demonstrability without real certificate material (same
`log`/sandbox-first convention as every sibling connector).
`StufZknClientTest::testSendRoutesThroughMtlsTransportWhenConfigured()`/
`testSendDoesNotRouteThroughMtlsTransportWhenTokenMode()` prove the
routing actually happens (orphaned-capability rule), not merely that the
code compiles.

## 7. Inbound authentication

A real StUF-ZKN deployment typically establishes trust at the transport
layer (PKIoverheid mTLS / municipal network trust) — outside this app's
control (this app is the *receiver*; enforcing a client-certificate
requirement on inbound traffic is a reverse-proxy/web-server concern, not
something `StufZknController` can itself demand of the calling
municipality's outbound HTTP client). This endpoint additionally requires
the SAME HMAC scheme `IwmoIjwController::inbound()` already uses
(`WebhookSignatureService`, `X-OpenConnector-Signature` computed over the
raw XML bytes, verified against `source.configuration.webhookSignature`)
as a demonstrable, testable authentication layer in an environment with no
real StUF-ZKN mTLS front-end available — mirrors the exact "no source
configured => fail closed 401" / "undifferentiated 401 body, never leak
which check failed" contract `IwmoIjwController` already ships and already
has accepted test coverage for. A real deployment would layer municipal
network trust or reverse-proxy mTLS on top of (not instead of) this
app-level signature check.

## 8. Persistence: `stuf_message`

One schema (not two), matching the task's explicit shape
(`direction`, `berichttype`, `referentienummer`, `status`, `error`) plus
`verwerkingssoort`/`entiteittype`/`syncedAt` for observability — mirrors
`iwmo_ijw_message`'s single-schema-with-`direction`-field design (not
`dso_verzoek`/`dso_message`'s two-schema split, which exists there because
DSO models a distinct multi-step verzoek LIFECYCLE with its own state
machine; a StUF-ZKN message is a single, atomic, replied-to-immediately
event on both directions, closer to `iwmo_ijw_message`'s shape). AVG/
persoonsgegevens hygiene: the raw envelope XML is NEVER persisted (a
zaak/document can carry persoonsgegevens on `betrokkene` relations even
though this change's own field table does not model them) — only
correlation/audit metadata, mirroring `iwmo_ijw_message`'s identical,
already-accepted "raw payload never stored verbatim" decision and its
consequence for retry (§ below).

**Retry limitation** (mirrors `IwmoIjwSyncService::retryOne()` verbatim):
because the raw outbound kennisgeving XML is not retained, `retryFailed()`
re-attempts transport dispatch with a minimal re-derived stub carrying
just the `referentienummer` — best-effort at the reference level. A truly
complete resend requires the caller to re-invoke `sendKennisgeving()` with
the original zaak payload. This still exercises and proves the retry code
path end to end (`StufZknRetryJob` + `StufZknSyncService::retryFailed()`/
`retryOne()`), which is what this change's scope requires.

## 9. How procest consumes this (cross-app contract, not implemented here)

- **Inbound** (StUF estate → OR): procest's own `zaak`/
  `enkelvoudiginformatieobject`-shaped register/schema (whatever it is
  configured to be, via `ZgwMappingService`) is set as this bridge's
  `configuration.targetRegister`/`targetSchema` — no procest code change
  ships in this PR. Once upserted, the zaak is immediately visible through
  procest's existing ZGW Zaken API surface (`ZrcController`) exactly as if
  it had been created via that API directly — this bridge writes the SAME
  underlying OR objects procest's own controllers read.
- **Outbound** (OR/ZGW zaak change → StUF estate): procest's own status-
  transition code (or a future `StufZknOutboundListener`, not built here)
  would call `POST /api/stuf-zkn/kennisgevingen` with
  `{zaak: {...}, verwerkingssoort: "T"|"W"|"V"}` whenever a zaak it manages
  changes — mirrors `IwmoIjwController::createBericht()`'s identical
  "sibling app pushes, this bridge translates+dispatches" contract. Not
  built in this PR (documented cross-app contract only, per the task's own
  precedent set by `iwmo-ijw-adapter`/`dso-connector-adapter`).
- A `503 not_configured` response from either endpoint means no active
  `type=stuf-zkn` source exists yet — treat as log-and-skip, not a
  citizen/caseworker-facing error (same convention as every other leaf
  connector's `not_configured` case).

## 10. Alternatives considered

- **A poll-based inbound leg** (mirroring `kiss-kcc-bridge`'s pattern) —
  rejected: StUF-ZKN is fundamentally a push (SOAP call) standard; there is
  no "list zaken since X" koppelvlak to poll, so a push endpoint is not an
  implementation choice but the only shape that matches the standard.
- **Feeding `zakLk01` through the semantic-case-intake `HandoffService`**
  — rejected, see §4.
- **A real SOAP library dependency** (e.g. `php-soap`) — rejected per the
  task's explicit "no new composer deps" rule and mirroring `iwmo-ijw-adapter`'s
  identical choice: `DOMDocument`/`SimpleXMLElement` (both PHP built-ins)
  are sufficient for hand-building/parsing the specific envelope shapes
  this bridge needs, and avoid pulling in a general-purpose WSDL/SOAP
  client whose auto-generated bindings this bridge does not use.
- **Modelling `isVan`/`heeftAlsInitiator`/roltype relations** — deferred,
  see §9/Open Questions; this change's scope is case-relevant
  create/update/vervallen + document attach, not the full StUF-ZKN
  betrokkene/rol graph.

## 11. Open Questions

- **The exact per-element namespace assignment (§3.1) is unverified** —
  isolated entirely to `StufZknNamespaces` + the three `StufZkn/*`
  translator/builder classes; a correction requires no change to the
  provider seam, controller, or sync-service orchestration.
- **The real StUF-ZKN `Fo03` foutcode catalogue is unverified** — this
  bridge's 4-entry catalogue (§3.7) is a plausible, StUF-shaped guess, not
  the complete real enumeration; isolated to
  `StufZknAcknowledgementBuilder::FAULT_CATALOGUE`.
- **`I` (identifying-attribute change) handling is a documented
  simplification** (§3.3) — a future revision with real StUF-ZKN sender
  behaviour to test against should determine whether senders actually
  populate an old/new identificatie pair for this code.
- **`isVan`/`heeftAlsInitiator`/`heeftBetrekkingOp` relation blocks
  (roltype/betrokkene) are not modelled** — this change's scope is the
  zaak/document's own fields; wiring the relation graph is a natural,
  isolated follow-up.
- **Outbound `edcLk01` (document → StUF estate) is out of scope** per the
  task's explicit scope (inbound zakLk01/edcLk01, outbound zakLk01 only).
- **PKIoverheid certificate issuance/provisioning for a real municipality**
  is, as with every sibling connector, an operator-side/certification-track
  concern entirely outside what a single code change delivers — the `log`
  provider makes the whole inbound→upsert→ack and
  outbound-translate→dispatch→audit path demonstrable and testable without
  any live StUF-ZKN access.
