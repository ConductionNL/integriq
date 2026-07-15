# Design: iwmo-ijw-adapter

## Architecture Overview

```
   OR case object (register=openconnector-consumer app, e.g. procest "toewijzing"/"declaratie")
         │ POST /api/iwmo-ijw/berichten {caseReference, kind, domain, ...fields}
         ▼
   IwmoIjwController::createBericht()
         │
         ▼
   IwmoIjwSyncService::sendBericht()
         │  OutboundBerichtTranslator::translate()  ── builds Wmo3xx/Jw3xx XML envelope
         │  literal-leak guard: missing/unresolved field -> IwmoIjwTranslationException, never emitted
         ▼
   IwmoIjwProviderInterface (send)         ◄── LogIwmoIjwProvider (default, dry-run)
         │                                 ◄── IStandaardenClient (REST, token auth — ASSUMED shape)
         ▼
   GGk / VECOZO (external — NOT reachable in this environment)
         │  ... days/weeks later, provider or GGk POSTs a retour message back ...
         ▼
   POST /api/iwmo-ijw/retour  (HMAC-signed, mirrors PeppolController::inbound())
         ▼
   IwmoIjwController::inbound()
         │
         ▼
   IwmoIjwSyncService::receiveRetour()
         │  InboundRetourTranslator::translate()  ── Wmo3xx/Jw3xx retour XML -> OR status update
         ▼
   single write path: OR case object status field updated (register/schema/uuid resolved from
   the retour's kenmerk/referentienummer)
         │
         ▼
   iwmo_ijw_message OR record persisted either way (direction, berichttype, status, ref, error)

   IwmoIjwRetryJob (hourly TimedJob) ── scans iwmo_ijw_message rows with status=failed|pending
   and re-attempts IwmoIjwSyncService::retryFailed() through the same send path.
```

Outbound is an authenticated NC-session call from a sibling app (mirrors
`KissController::createKlantcontact()` / `NotifyNlController::send()`).
Inbound retour ingestion is a signed webhook (mirrors
`PeppolController::inbound()`), because a real GGk/VECOZO deployment pushes
retour messages back asynchronously — there is no polling cursor here (unlike
kiss-kcc-bridge's pull job) because iStandaarden retour delivery is
push-based by design (StUF `Zender`/`Ontvanger` envelope routing), not a
listable REST collection.

## Message-shape assumptions (READ THIS FIRST)

**No live GGk or VECOZO connection was available in this environment to
verify against — stated explicitly, not pretended otherwise.** Every
berichttype, field name, and envelope shape below is a documented
assumption, grounded in the publicly published VNG/iStandaarden "StUF
Zorgberichtenapp" (iWmo 3.0 / iJw 3.0) message catalogue and this app's own
already-implemented `vng-klantinteracties-adapter` / `kiss-kcc-bridge`
precedent for how a VNG-dialect connector is structured in this codebase
(narrow provider interface, `Log` + REST bindings, encrypted static token,
per-message OR audit record). If the real GGk/VECOZO wire format diverges
(a different berichtcode numbering, a SOAP envelope instead of a bare XML
POST body, a different auth scheme), the fix is isolated to
`IStandaardenClient` and the two translator classes — `IwmoIjwSyncService`
and `IwmoIjwController` are unaffected (same provider-seam argument as
kiss-kcc-bridge's design.md).

### Berichttypen covered

| Code (Wmo\*/Jw\*) | Meaning (assumed) | Direction | Translator |
| --- | --- | --- | --- |
| 303 | Toewijzing (assignment of care) | OUTBOUND | `OutboundBerichtTranslator` |
| 321 | Declaratie (invoice for delivered care) | OUTBOUND | `OutboundBerichtTranslator` |
| 302 | Retour verzoek om toewijzing (request rejected) | INBOUND | `InboundRetourTranslator` |
| 304 | Retour toewijzing (assignment acknowledged/accepted) | INBOUND | `InboundRetourTranslator` |
| 305 | Start zorg (care delivery started, reported by provider) | INBOUND | `InboundRetourTranslator` |
| 306 | Retour start zorg | INBOUND | `InboundRetourTranslator` |
| 307 | Stop zorg (care delivery stopped, reported by provider) | INBOUND | `InboundRetourTranslator` |
| 308 | Retour stop zorg | INBOUND | `InboundRetourTranslator` |
| 322 | Retour declaratie (invoice processed/rejected + payment reference) | INBOUND | `InboundRetourTranslator` |

301 (Verzoek om toewijzing, a pre-assignment request some municipalities
skip in favour of sending 303 directly) is NOT implemented — out of scope,
see below. The 301-308 numbering pattern (request/retour, assignment/retour,
start/retour, stop/retour) and the 321/322 declaratie pair are this
change's own consistent assumption, not a verified excerpt of the real
iStandaarden XSD catalogue. `Jw*` codes mirror `Wmo*` 1:1 — iJw 3.0 and iWmo
3.0 are structurally parallel iStandaarden (same StUF envelope, distinct
namespace prefix), which is why one translator pair serves both, selected
by a `domain` (`wmo`|`jw`) parameter rather than duplicated per-domain
classes.

### Envelope shape (assumed)

Outbound and inbound both use a StUF-style envelope, hand-built via
`DOMDocument` (no XSD/SOAP dependency added — a hand-rolled thin envelope,
consistent with "NO new composer deps"):

```xml
<Bericht xmlns="http://www.vng.nl/iwmo/iStandaarden" xmlns:jw="http://www.vng.nl/ijw/iStandaarden">
  <stuurgegevens>
    <berichtcode>Wmo303</berichtcode>
    <zender><code>{gemeentecode}</code></zender>
    <ontvanger><code>{aanbiederAgbCode}</code></ontvanger>
    <referentienummer>{ref}</referentienummer>
    <tijdstipBericht>{iso8601}</tijdstipBericht>
  </stuurgegevens>
  <body>
    <!-- berichttype-specific fields, see per-translator field table -->
  </body>
</Bericht>
```

`stuurgegevens.referentienummer` is the correlation key: the outbound
translator generates it (a local UUID prefixed `IWMO-`/`IJW-`), and the
retour message MUST echo it back as `stuurgegevens.kenmerk` (assumed field
name — "kenmerk" is the StUF-standard back-reference field, distinct from
the retour's own `referentienummer`). `IwmoIjwSyncService::receiveRetour()`
resolves the linked local record and OR case object by this
`kenmerk`/`referentienummer` value.

### Outbound field table (assumed VNG field vocabulary)

| Field | Berichttype | Required | Assumption |
| --- | --- | --- | --- |
| `bsn` | 303 | yes | Client burgerservicenummer — sent RAW on the wire (legally required to identify the client to the care provider); NEVER persisted raw in `iwmo_ijw_message` (see "AVG / BSN handling"). |
| `productcode` | 303, 321 | yes | iWmo/iJw product identification code. |
| `ingangsdatum` | 303 | yes | Assignment start date (ISO 8601 date). |
| `einddatum` | 303 | no | Assignment end date. |
| `omvang` | 303 | yes | Awarded volume/quantity (StUF `Omvang` group: `waarde` + `eenheid`). |
| `leveringsvorm` | 303 | yes | `ZIN` (zorg in natura) or `PGB` (persoonsgebonden budget). |
| `aanbiederAgbCode` | 303, 321 | yes | Care provider AGB code — becomes `stuurgegevens.ontvanger.code`. |
| `gemeentecode` | 303, 321 | yes | Municipality code (Gemeentelijke Basisadministratie) — becomes `stuurgegevens.zender.code`. |
| `toewijzingReferentie` | 321 | yes | The `referentienummer` of the original 303 this declaratie invoices against. |
| `factuurnummer` | 321 | yes | Invoice number. |
| `bedrag` | 321 | yes | Invoiced amount (decimal, EUR assumed — no currency field in scope). |
| `periodeStart` / `periodeEind` | 321 | yes | Invoiced delivery period. |

### Inbound retour field table (assumed)

| Field | Berichttype | Maps to OR case field |
| --- | --- | --- |
| `resultaat` | 302, 304 | `status`: `resultaat=akkoord` -> `accepted`; anything else -> `rejected` (302 defaults to `rejected` when absent — a request retour with no explicit result is treated as a rejection, never silently accepted). |
| `startdatumWerkelijk` | 305 | `status` -> `care_started`, `careStartedAt` <- this field. |
| `einddatumWerkelijk` | 307 | `status` -> `care_stopped`, `careStoppedAt` <- this field. |
| `resultaat` | 306, 308 | `status` -> `care_start_confirmed` / `care_stop_confirmed`. |
| `betaalstatus` + `betalingReferentie` | 322 | `status` -> `invoice_processed` when `betaalstatus=akkoord`, else `invoice_rejected`; `paymentReference` <- `betalingReferentie`. |

Any retour whose `berichtcode` does not match one of the 7 recognised codes
above, or whose `kenmerk` does not resolve to a known local
`iwmo_ijw_message`, is logged and the endpoint still acknowledges receipt
(`{received: true}`) — mirrors `PeppolController::inbound()`'s "never 500 on
a verified callback" rule; a malformed or unrecognised retour must not
crash a real GGk delivery pipeline that may retry on error.

## Literal-leak guard

`OutboundBerichtTranslator::translate()` never substitutes an empty string,
`null`, or a template placeholder into the rendered XML for a REQUIRED
field (see field tables above) — a missing required field throws
`IwmoIjwTranslationException` naming the field, BEFORE any XML is built.
As defense in depth, `assertNoUnresolvedPlaceholder()` scans the fully
rendered envelope string for leftover `{{`/`}}`/`%%UNRESOLVED%%` markers
(the same class of bug the fleet's `oc-mapping-literal-leak` defect
documented — an unresolved mapping reference silently passing through as
literal text) and throws if any survive. `InboundRetourTranslatorTest`
mirrors this on the inbound side: a retour XML with an empty/missing
`kenmerk` is rejected before any OR write is attempted (never writes to
"whatever object happens to match a blank reference").

## AVG / BSN handling

Consistent with `kiss-kcc-bridge`'s `AvgBsnPolicyRule` precedent: the raw
BSN is legally required IN THE OUTBOUND WIRE MESSAGE (a care provider
cannot deliver Wmo/Jw care without knowing which citizen it concerns), so
`OutboundBerichtTranslator` puts it in the XML unredacted. `IwmoIjwSyncService`
however NEVER persists that raw XML into `iwmo_ijw_message.payload` — a
SHA-256 hash of the bsn replaces it before the audit record is saved
(`redactBsnForAudit()`), so a citizen service number never lives in
OpenRegister storage even though it legitimately left the system on the
wire. This is the same asymmetry (raw on the wire, hashed at rest) that
`vng-klantinteracties-adapter`'s field vocabulary documents for onward VNG
calls generally.

## Provider seam, credential storage, feature gating

Mirrors kiss-kcc-bridge exactly: `IwmoIjwProviderInterface` (3 methods:
`getProviderId`, `getConfigSchema`, `send`) is implemented by
`LogIwmoIjwProvider` (sandbox — no network, synthetic `MOCK-IWMO-<n>` refs,
default for dev/CI) and `IStandaardenClient` (generic REST binding: token
auth via `Authorization: Bearer <token>`, `configuration.authentication.
encryptedToken` ENCRYPTED AT REST via `OCP\Security\ICrypto`, decrypted
in-process per request, never logged). `configuration.provider` selects the
binding (`log`|`rest`), default `log` — an unconfigured source (none active,
or `type=iwmo-ijw` absent) makes `sendBericht()`/`receiveRetour()` report a
clean `not_configured` 503, no HTTP attempted (mirrors every other leaf
connector's convention already in this app — no `log|test|live` string
config exists anywhere in this codebase, checked against kiss-kcc-bridge's
own design.md note).

**Auth/transport deviation, stated explicitly**: real GGk/VECOZO
connectivity uses mutual TLS client-certificate authentication, NOT a
bearer token — this was explicitly called out in the task brief as a fact
about the real-world standard. `IStandaardenClient` implements the token
scheme ONLY (same shape as every other REST binding in this app) because
(a) no live GGk instance exists in this environment to validate a
client-cert handshake against, and (b) Guzzle client-cert configuration
(`cert`/`ssl_key` options) is a deployment-time concern (the actual PEM
material), not a translation-logic concern. This is flagged as a known gap
in "Open Questions" below — a future revision adds `configuration.
clientCertificate` (path/`ssl_key` passphrase, injected the same way
`Source.configuration` already carries other secrets) without touching
`IwmoIjwProviderInterface`.

## Persistence: `iwmo_ijw_message`

One OR record per outbound send attempt AND per inbound retour received
(never merged) — `direction` (`outbound`|`inbound`), `berichttype`
(`Wmo303`, `Jw322`, ...), `domain` (`wmo`|`jw`), `status`
(`sent`|`failed`|`accepted`|`rejected`|`care_started`|`care_start_confirmed`
|`care_stopped`|`care_stop_confirmed`|`invoice_processed`|
`invoice_rejected`), `ref` (the `referentienummer` this message carries),
`kenmerk` (the correlated outbound ref, inbound-only), `caseReference`,
`error` (transport/provider failure detail, null on success), `syncedAt`.
Per-message isolation: `IwmoIjwSyncService::retryFailed()` iterates
`status=failed`/`pending` rows independently — one message's retry
exception is logged and skipped, never aborting the sweep (same pattern as
`KissSyncService::pullAll()`'s per-source isolation).

## Single write path — addressing the linked OR case

Unlike `kiss_klantcontact` (which never mutates a foreign object — it only
records a `caseReference` for consuming apps to query), this change's task
brief explicitly requires the inbound retour to "update the linked OR
object". OpenRegister objects are always addressed by the
(register, schema, uuid) triple — `ObjectService::saveObject()`/`findAll()`
have no "resolve by bare uuid across every register" call in this
codebase's existing usage (see `KissSyncService`, `PeppolTransmissionService`
for precedent: every call names its own `register`/`schema` explicitly).
OpenConnector therefore requires the PUSH request to supply `caseRegister`
and `caseSchema` alongside `caseReference` — the consuming app already knows
where its own case object lives. All three are persisted on the outbound
`iwmo_ijw_message` row so the retour leg (which only receives the `kenmerk`
correlation value) can resolve the exact same triple without guessing.

`IwmoIjwSyncService::updateLinkedCase()` is the SINGLE place that performs
this write — called from exactly one call site (`receiveRetour()`) — and it
NEVER overwrites the consuming app's own domain fields (e.g. a generic
`status` property that app already owns for its own workflow). Instead it
merge-writes a namespaced sub-object, `iwmoIjw: {status, careStartedAt,
careStoppedAt, paymentReference, berichttype, syncedAt}`, onto the existing
object (read-merge-save, preserving every other field verbatim). This
avoids clobbering an unrelated field with a Wmo/Jw-specific status value a
consuming app's own status enum was never designed to hold.

## How procest / social-domain apps consume this (cross-app contract, not implemented here)

- To register a Wmo/Jw assignment: `POST /api/iwmo-ijw/berichten` with
  `{caseReference: <OR case uuid>, caseRegister: <OR register slug>,
  caseSchema: <OR schema slug>, kind: "toewijzing", domain: "wmo"|"jw",
  bsn, productcode, ingangsdatum, omvang, leveringsvorm, aanbiederAgbCode,
  gemeentecode}`. `caseRegister`/`caseSchema` tell OpenConnector exactly
  where to write the retour outcome back to (see "Single write path"
  above) — omit them (or `caseReference`) to opt out of the write-back and
  rely on querying `iwmo_ijw_message` directly instead. The response's
  `ref` (referentienummer) SHOULD also be stored on the consuming app's own
  case/toewijzing record for its own correlation, mirroring how procest
  stores a NotifyNL `providerMessageId` / KISS klantcontact id.
- On retour, the linked case object gains/updates a namespaced `iwmoIjw`
  sub-object (`status`, `careStartedAt`, `careStoppedAt`,
  `paymentReference`, `berichttype`, `syncedAt`) — the consuming app's own
  fields are never touched.
- To register a declaratie: same endpoint, `kind: "declaratie"` plus the
  declaratie field table above, including `toewijzingReferentie` (the `ref`
  from the earlier 303 send).
- To observe retour outcomes on a case's timeline: query OpenRegister's
  generic object API for `register=openconnector, schema=iwmo_ijw_message,
  caseReference=<the case UUID>` — no dedicated read endpoint needed, same
  precedent as `kiss_klantcontact`.
- A `not_configured` (503) response means no active `type=iwmo-ijw` source
  exists — treat as log-and-skip, not a citizen-facing error (same
  convention as every other leaf connector).

## Alternatives considered

- **Polling GGk/VECOZO for retour messages** (kiss-kcc-bridge's cursor
  pattern) was considered for symmetry with the pull job already present in
  this app. Rejected: iStandaarden retour delivery is StUF push-based by
  design (the GGk routes a retour to the ORIGINAL sender's registered
  endpoint) — there is no published "list my retour messages since X"
  collection endpoint to poll in the first place, unlike KISS's DRF-style
  klantcontacten list. A webhook receiver is the only architecturally
  correct shape here; the `IwmoIjwRetryJob` cron instead re-drives
  OUTBOUND sends that previously failed transport (a genuinely pollable,
  locally-owned queue: `iwmo_ijw_message` rows with `status=failed`).
- **A single translator class handling both directions** was considered
  (fewer files) but rejected per the task's explicit "ONE testable
  translator class per direction" requirement, and because the outbound
  and inbound field vocabularies barely overlap (only `referentienummer`/
  `kenmerk` correlate them) — a combined class would need direction
  branching throughout, which the split avoids entirely.

## Open Questions

- Client-certificate (mTLS) auth for a real GGk/VECOZO connection is
  explicitly deferred (see "Provider seam" above) — `IStandaardenClient`
  ships with token auth only, a documented gap, not a silent omission.
- Whether municipalities exchange iWmo/iJw directly with GGk or via a
  regional Gegevensknooppunt intermediary varies by region; this design
  treats `configuration.baseUrl` as "whatever endpoint the deployment
  points at" and does not encode a specific GGk/VECOZO base URL — another
  explicit unknown, not a false certainty.
