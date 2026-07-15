# Design: dso-connector-adapter

## 1. Verified-at-HEAD findings this design binds to

### 1.1 The STAM koppelvlak already existed but silently dropped every verzoek

`lib/Controller/DSOController.php::receiveVerzoek()` (shipped by the prior
`dso-omgevingsloket` + `dso-stam-pkioverheid-signature-verification` changes)
already: verifies `X-DSO-Signature` via `DSOSignatureVerifierService`
(PKIoverheid certificate-chain RSA in production, HMAC shared-secret in
pre-production — fails closed on every error path, 13 unit tests against a
real self-signed cert chain), validates the payload via
`DSOParserService::validatePayload()` (required fields, `type` enum, BSN
11-proef, ISO 8601 date), and parses it via
`DSOParserService::parseVerzoek()` (aanvrager, locatie incl. GML→GeoJSON
conversion, activiteiten, bijlagen refs). **Verified via direct code
inspection**: after parsing, the controller only logged
`'DSO STAM: Verzoek received'` and returned HTTP 202 — no
`ObjectService::saveObject()` call existed anywhere in the class, no
`dso_verzoek`-shaped schema existed in the register, and no handoff was
declared. Every real DSO Verzoek received in production was therefore
**logged and permanently discarded** — a complete, silent data-loss gap.

### 1.2 `DSOAdapterService`/`DSOStatusService`/`DSOSamenwerkingService` were built but never wired — a textbook orphaned-capability defect

`grep -rn "DSOAdapterService"` / `grep -rn "processVerzoek("` across `lib/`
(excluding the class's own file) returns **zero matches** —
`DSOAdapterService::processVerzoek()`, `handleMelding()`,
`handleInformatieverzoek()`, `handleVooroverleg()`, `handleAanvraag()`,
`handleSamenloop()`, `createHoofdzaakWithDeelzaken()`,
`createGecombineerdZaak()`, and `createZaak()` have no caller anywhere in
the app. Worse, even if wired, `createZaak()` (the method every one of those
paths ultimately calls) does not persist anything:

```php
public function createZaak(array $verzoek, string $zaaktypeIdentificatie, string $strategy='single'): array
{
    $zaakId = uniqid(prefix: 'zaak-', more_entropy: true);
    return ['id' => $zaakId, /* ...verzoek fields echoed back... */];
}
```

It fabricates an in-memory `uniqid()`-keyed array — no `ObjectService`
is even injected into the class. This is the fleet's documented
"orphaned capability" bug class in its most severe form: implemented,
plausible-looking, individually unit-tested logic that writes nothing real
and is invoked by nothing. `grep -rln "DSOStatusService"` /
`grep -rln "DSOSamenwerkingService"` across `lib/` likewise return only each
class's own file — `DSOStatusService::pushStatusToDSO()` (a real,
`IClientService`-based HTTP POST with exponential-backoff retry — legitimate,
working code) and all of `DSOSamenwerkingService` (SWR adviesverzoeken) are
equally unwired.

**Binding decision**: this change (a) fixes the critical inbound gap by
routing `receiveVerzoek()` through a NEW `DsoIngestService` that actually
persists to OpenRegister and declares a real handoff, (b) does **NOT** wire
`DSOAdapterService::createZaak()`'s fake-zaak path into anything — doing so
would propagate the defect (a second, competing "case creation" mechanism
that still writes nothing real) rather than fix it, and (c) does **NOT**
consolidate `DSOStatusService`'s orphaned-but-correct outbound push into the
new provider seam, kept deliberately self-contained/Guzzle-mockable instead
(see §5). `DSOAdapterService`'s activiteiten→zaaktype mapping table and
samenloop-strategy logic (`mapActiviteitenToZaaktypen()`,
`determineSamenloopStrategy()`) remain legitimate, reusable pure functions —
a future change could wire THOSE (not `createZaak()`) into a
multi-zaak-aware handoff extension; see §7 "Open Questions."

### 1.3 OpenRegister's real handoff API (unchanged since `open-formulieren-intake`, re-verified at this HEAD)

Same engine, same constraint: `HandoffService::execute(register, schema, id,
handoffId)` runs under the caller's own RBAC; `x-openregister-handoff` is a
schema-level declarative dialect (`SchemaMapper.php`,
`HandoffAnnotationValidator.php`); v1 has **no system-user privilege lane**
(`lib/Listener/HandoffLifecycleListener.php`). **Consequence**: identical to
`open-formulieren-intake` — a DSO Verzoek arrives via an unauthenticated,
signed webhook, so this bridge does the fully-automatic part (receive,
verify, translate, persist, `status=mapped`) with zero human involvement,
and exposes a separate authenticated `POST .../{id}/handoff` endpoint for
the human-in-the-loop final step (a caseworker reviewing an intake queue —
not a functionality gap, the documented v1 behaviour).

### 1.4 `ns#Case` contract fields (unchanged — same hydra contract `open-formulieren-intake` targets)

| Field | Mandatory | This bridge |
|---|---|---|
| `title` | yes | `DsoVerzoekTranslator` output `mappedTitle` (first activiteit's omschrijving/code, or a type-based generic) |
| `summary` | yes | `mappedSummary` (all activiteiten, comma-joined, plus projectbeschrijving) |
| `channel` | yes | `mappedChannel` — always `const: "omgevingsloket"` |
| `source` | yes | **Engine-filled** provenance pointer — `{"provenance": true}`, never mapped by this app |
| `requester` | no | **Not mapped in v1** — same rationale as `open-formulieren-intake`: no OR-managed party register exists to resolve a BSN/KvK against in this fleet today. The aanvrager's `{bsn, kvkNummer}` IS captured on `dso_verzoek.requester` (never logged) for a future revision. |
| `priority` | no | `mappedPriority` — `hoog` for `aanvraag`, `normaal` otherwise (see §2.3) |

## 2. DSO concept mapping — what this connector targets and what it doesn't

Per the task's explicit instruction, this connector implements **only the
case-relevant inbound/outbound** surface. The wider DSO landscape (per
publicly documented VNG/"Aan de slag met de Omgevingswet" terminology):

| DSO concept | This connector | Rationale |
|---|---|---|
| **Omgevingsloket (OLO) / "Verzoeken indienen"** — citizens/businesses submit a vergunningaanvraag, melding, informatieverzoek, or vooroverleg through the national front-end; DSO-LV delivers it to the bevoegd gezag's registered endpoint (the "STAM koppelvlak") | **IN SCOPE** — this is exactly what `DSOController::receiveVerzoek()` + the new `DsoIngestService`/`DsoVerzoekTranslator` handle | Directly case-relevant: a Verzoek IS the intake event |
| Outbound status (voortgangsinformatie) / besluit acknowledgement back to DSO-LV | **IN SCOPE** — `DsoConnectorProviderInterface`/`DsoClient`, `DsoIngestService::postOutbound()` | Directly case-relevant: DSO-LV/the citizen expects status visibility on their Verzoek |
| **Samenwerkingsfunctie/Samenwerkingsruimte (SWR)** — multi-authority (bevoegd gezag + adviesorganisaties) collaboration on one case, adviesverzoeken exchange | **OUT OF SCOPE** — the pre-existing, still-unwired `DSOSamenwerkingService` already models this as a separate concern; task instruction explicitly scopes this change to case-relevant inbound/outbound only | Not part of the intake/status loop; a distinct DSO subsystem with its own koppelvlak |
| **STAM/STTR (toepasbare regels)** — the structured aanvraag/melding data model and the "applicable rules" engine that helps a citizen determine which activiteiten apply | **OUT OF SCOPE for STTR**; STAM is already consumed (that's what `DSOParserService` parses) | STTR is a rules-evaluation surface, not case-relevant inbound/outbound |
| **LVBB/DROP** (Landelijke Voorziening Bekendmaken en Beschikbaarstellen) — official publication of besluiten/regelingen (STOP-TPOD) | **OUT OF SCOPE** — a DIFFERENT DSO subsystem from Verzoeken. procest already has its own `PublicationService`/`BesluitvormingPublishHandler` (`openspec/changes/besluitvorming-workflow` in procest) dispatching to DROP/LVBB directly | Bekendmaking of a besluit (a college decision going into effect) is not the same flow as acknowledging a permit-request's status back to the requester via DSO-LV |
| DSO-LV certification, conformance testing, production/preprod credentials | **OUT OF SCOPE — see §7** | Multi-month track per procest's own research classification; this change ships the connector/translation layer only |

## 3. Mapping design

### 3.1 Two distinct translation layers (do not conflate — same structure as `open-formulieren-intake` §2.1)

1. **This app's own layer** (`DsoVerzoekTranslator`): a parsed DSO Verzoek →
   this bridge's own normalised `dso_verzoek` properties (`mappedTitle`,
   `mappedSummary`, `mappedChannel`, `mappedPriority`). Unlike
   `open-formulieren-intake`'s `FormFieldMapper` (which resolves an
   **admin-configured, per-form** mapping against an arbitrary third-party
   field set), this translator is **fixed, not admin-configurable** — a DSO
   Verzoek's shape is the nationally standardised STAM schema
   `DSOParserService` already parses; there is no per-deployment "form" to
   map differently.
2. **OpenRegister's own layer** (`x-openregister-handoff` on `dso_verzoek`):
   those normalised properties → `ns#Case` contract fields, via OR's shipped
   `HandoffMappingEvaluator`. This bridge only *declares* the mapping.

### 3.2 Literal-leak guard (REQ)

Mirrors `open-formulieren-intake`'s `FormFieldMapper` and
`iwmo-ijw-adapter`'s `InboundRetourTranslator`'s `kenmerk` guard: a Verzoek
with an empty/missing `verzoekId` — the correlation reference the outbound
leg needs to post status/besluit back — MUST raise
`DsoTranslationException` **before** any `dso_verzoek` mapping is returned.
The caller (`DsoIngestService::ingest()`) never guesses or synthesises a
`verzoekId`; a Verzoek that fails this guard is persisted with
`status=failed` and the raw payload preserved on `rawVerzoek` for manual
triage, never silently discarded (fixing the exact class of gap described
in §1.1, just for THIS specific field rather than the whole record).

### 3.3 Fallback policy for thin data (partial Verzoeken)

Unlike the literal-leak guard (which refuses to fabricate a
**correlation reference**), a Verzoek that is merely thin on descriptive
data (no activiteit omschrijving, no projectbeschrijving) still translates
successfully with a documented, honest fallback — never a hard failure for
data that is legitimately optional on the real STAM schema:

- `mappedTitle`: activiteit `omschrijving` → activiteit `code` →
  `"DSO <type>"` (e.g. `"DSO informatieverzoek"`).
- `mappedSummary`: every activiteit's omschrijving/code (comma-joined) +
  projectbeschrijving when present → `"Verzoek zonder
  activiteitomschrijving."` when nothing at all is present.
- `mappedPriority`: `hoog` for `aanvraag` (a full vergunningaanvraag carries
  Awb beslistermijn pressure), `normaal` for `melding`/`informatieverzoek`/
  `vooroverleg` (lighter-weight, no formal besluit required for the latter
  two per `DSOAdapterService`'s own existing type taxonomy).

## 4. Persistence: `dso_verzoek` / `dso_message`

`dso_verzoek` — one record per received Verzoek (never merged with a
retry): `verzoekId`, `bronorganisatie`, `type`, `indieningsdatum`,
`rawVerzoek` (audit — the full parsed payload), `requester`
(`{bsn, kvkNummer}`, never logged), `mappedTitle`/`mappedSummary`/
`mappedChannel`/`mappedPriority`, `status`
(`received → mapped → handed_off | failed`), `errorDetail`,
`correlationId`, `targetCase`, `receivedAt`. Per-verzoek isolation: a
translation failure on one verzoek (`DsoIngestService::ingest()`) never
affects another — each webhook POST is one independent `ingest()` call
(same isolation shape as `OpenFormulierenIntakeService::ingest()`).

`dso_message` — one record per outbound dispatch **attempt** (status or
besluit, success or failure — never merged/updated, mirrors
`iwmo_ijw_message`'s append-style convention): `ref`, `type`
(`status`|`besluit`), `status` (`sent`|`failed`), `payload` (audit — the
exact dispatched body), `verzoekUuid` (correlates back to its
`dso_verzoek`), `error` (null on success), `syncedAt`. Per-message
isolation: `DsoIngestService::postOutbound()` always persists the
`dso_message` row — on provider failure it persists `status=failed` +
`error` FIRST, then rethrows `DsoProviderException` (mirrors
`IwmoIjwSyncService::sendBericht()`'s "record before rethrow" ordering).

## 5. Outbound provider seam, credential storage, and message-shape assumptions

`DsoConnectorProviderInterface::send(sourceConfiguration, verzoekId, type,
payload): string` — a narrow domain seam mirroring
`IwmoIjwProviderInterface`/`KlantinteractiesProviderInterface`. Selected at
runtime via the `type=dso` source's `configuration.provider`
(`log`|`rest`), defaulting to `log` — an unconfigured deployment can never
accidentally dispatch a live DSO-LV call.

**ASSUMED TRANSPORT SHAPE — no live DSO-LV/preprod connection was available
to verify against in this environment**, every endpoint/field/header below
is an explicit, documented assumption, isolated to `DsoClient` alone:

- `POST {baseUrl}/statussen` with `{verzoekId, status, timestamp, ...fields}`
  — the `{verzoekId, status, timestamp}` shape is grounded in the
  pre-existing, already-shipped (if orphaned)
  `DSOStatusService::buildStatusPayload()`, which this change does not
  duplicate the transport of but does mirror the payload shape of for
  consistency.
- `POST {baseUrl}/besluiten` with `{verzoekId, besluit, gemotiveerd,
  timestamp, ...fields}` — no pre-existing precedent in this app; the field
  names are a plausible, documented guess (`besluit` = the decision outcome
  string, `gemotiveerd` = the motivation text), not a verified fact.
- Response: a JSON `{ref: "..."}` envelope, or an empty 2xx body — both
  accepted (`extractRef()` derives a local reference,
  `<verzoekId>-<type>-<timestamp>`, when the transport assigns none).
- Auth: `Authorization: Bearer <token>` — **a deliberate deviation from the
  real DSO-LV outbound transport**, which (per the inbound leg's own
  `DSOSignatureVerifierService`, already PKIoverheid-aware) very likely uses
  PKIoverheid client-certificate (mTLS) authentication for production
  traffic, not a bearer token. Flagged explicitly in §7 "Open Questions" —
  not a silent omission. Mirrors `IStandaardenClient`'s identical,
  already-accepted deviation. `configuration.authentication.encryptedToken`
  is ENCRYPTED AT REST via `OCP\Security\ICrypto`, decrypted in-process only
  for the instant needed to build each request's header.

**Why this is NOT consolidated with `DSOStatusService`**: `DSOStatusService`
is real, working code (`IClientService`-based, exponential-backoff retry,
mTLS-`cert`-path-ready) but sleeps synchronously between retries (2s, 4s,
8s) — reusing it as `DsoClient`'s transport would make every provider
failure-path unit test take up to 14 real seconds, and would couple this
change's test suite to that retry timing. `DsoClient` is instead a
single-attempt send (consistent with `IStandaardenClient`/
`KlantinteractiesClient` — retry, where it exists in this app, is a
separate cron-driven concern, e.g. `IwmoIjwRetryJob`, not inline
sleep-based backoff). A future revision could either route `DsoClient`
through `DSOStatusService`'s transport or add a `DsoOutboundRetryJob`
mirroring `IwmoIjwRetryJob` — not built here (not required by this change's
four numbered scope items).

## 6. How procest/VTH consumes this (cross-app contract, not implemented here)

- procest's `case` schema (prior `semantic-case-intake` change — the same
  dependency `open-formulieren-intake` has) is the intended production
  `ns#Case` provider. No procest code change ships in this PR.
- To review and complete an intake queue: query OpenRegister's generic
  object API for `register=openconnector, schema=dso_verzoek, status=mapped`
  (no dedicated "queue" endpoint needed — same precedent as
  `kiss_klantcontact`/`openformulieren_submission`), then
  `POST /api/dso/verzoeken/{id}/handoff` as an authenticated caseworker.
- To post a status/besluit update back to DSO-LV once a case progresses:
  `POST /api/dso/verzoeken/{id}/status` with `{type: "status"|"besluit",
  ...type-specific fields}`. A `503` response means no active `type=dso`
  source is configured — treat as log-and-skip, not a citizen-facing error
  (same convention as every other leaf connector's `not_configured` case).
- procest's VTH module (`docs/Features/vth-module.md`, currently
  planned-not-implemented) would drive `postOutbound()` from its own
  behandelproces status transitions — a future procest-side change, not
  built here.
- `dso_verzoek.requester` (`{bsn, kvkNummer}`) is available for a future
  revision to resolve against an OR-managed party register once one exists
  in this fleet — same deferred `requester` story as
  `open-formulieren-intake`.

## 7. Alternatives considered

- **Wiring `DSOAdapterService::processVerzoek()`/`createZaak()` into the
  ingest path** — rejected (see §1.2): it fabricates zaak data in-memory,
  writes nothing real, and reusing it would create a second, competing,
  equally-fake "case creation" path alongside the real handoff engine this
  change adds.
- **A cursor-based poll job for inbound Verzoeken** (mirroring
  `kiss-kcc-bridge`'s pattern) — rejected: the STAM koppelvlak is already a
  signed HTTP push delivery (verified, pre-existing,
  PKIoverheid/HMAC-gated), so there is no "list my Verzoeken since X" gap to
  fill; introducing a parallel poll mechanism would duplicate delivery.
- **Consolidating the outbound leg with `DSOStatusService`** — rejected for
  this pass, see §5; a documented, isolated follow-up.

## 8. Open Questions

- **DSO-LV certification and production/pre-productie base URLs,
  credentials, and PKIoverheid client-certificate provisioning are
  explicitly deferred.** procest's own research
  (`docs/research/market-feature-workup-2026-07.md`) classifies this as
  "own wave — large, certification track": DSO-LV onboarding, conformance
  testing against VNG/Kadaster's DSO test environment, and PKIoverheid
  certificate issuance are all multi-month processes entirely outside what
  a single code change can deliver. This change ships the connector/
  translation layer with a `log`/sandbox default so the full
  inbound→mapped→handoff→outbound path is demonstrable and testable without
  any live DSO-LV access.
- **PKIoverheid client-certificate (mTLS) auth on the OUTBOUND leg is not
  implemented** — `DsoClient` uses Bearer-token auth as a documented
  deviation (§5); a future revision adds `configuration.certPath`/mTLS
  support without touching `DsoConnectorProviderInterface`. Note the
  INBOUND leg already has real PKIoverheid chain validation
  (`DSOSignatureVerifierService`, pre-existing, unaffected by this change).
- **The exact DSO-LV outbound status/besluit endpoint paths, payload field
  names, and response envelope are unverified** (§5) — isolated to
  `DsoClient` alone should a correction be needed.
- **Whether DSO also supports a push/notification delivery mode** for
  status/besluit acknowledgement (mirroring the generic VNG Notificaties
  API pattern several Common Ground APIs use) is unverified; this change
  only implements the outbound POST leg.
- **Reconciling `DSOAdapterService`'s activiteiten→zaaktype/samenloop
  routing with the handoff engine** — OpenRegister's `HandoffService` v1 has
  no first-class "one Verzoek → N Cases" concept; a Verzoek with multiple
  activiteiten currently hands off to exactly ONE Case (the whole Verzoek,
  all activiteiten summarised together — see §3.3). Building genuine
  samenloop-aware multi-zaak handoff (deelzaken vs. gecombineerd, per
  `DSOAdapterService`'s existing strategy logic) is a separate, larger
  design question for a future change.
- **Bijlagen (attachment) download** — `DSOAdapterService::downloadBijlagen()`
  already exists (HTTPS-only SSRF guard, retry, mTLS-ready) but remains
  unwired; wiring it onto `dso_verzoek` (mirroring
  `open-formulieren-intake`'s attachment fetch-and-store) is a natural,
  isolated follow-up, not required by this change's scope.
