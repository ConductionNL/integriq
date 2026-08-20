# Design: kiss-kcc-bridge

## Architecture Overview

```
   KISS deployment (VNG Klantinteracties API)
         ▲                          │
         │ POST create+link         │ GET klantcontacten?registratiedatum__gte=...&expand=...
         │                          ▼
   KlantinteractiesClient (REST, token auth, own Guzzle client)
         ▲                          │
         │                          ▼
KissSyncService.pushKlantcontact()  KissSyncService.pullSource() ◄── KissPullJob (hourly TimedJob)
         ▲                          │
         │                     upserts kiss_klantcontact (OR)
         │                     advances source.configuration.cursor
         │
   KissController.createKlantcontact()
         ▲
         │ POST /api/kiss/klantcontacten
   sibling app (e.g. procest's ContactMomentService)
```

Push is an authenticated NC-session call (mirrors
`NotifyNlController::send()` / `PeppolController::participants()` — the
production binding for sibling apps). Pull is cron-driven, not a route — no
inbound webhook exists in this design (see "Alternatives considered" for why
polling-only was chosen over a webhook).

## API-shape assumptions (READ THIS FIRST)

**No live KISS instance was available in this environment to verify
against.** Every endpoint, field name, query parameter, and auth header below
is an explicit, documented assumption — stated here rather than pretended
otherwise, per the task's own instruction. Two groundings were used, in order
of authority:

1. **This app's OWN already-implemented server-side half of the same
   dialect** — `openspec/specs/vng-klantinteracties-adapter/spec.md` and its
   archived design.md (`openspec/changes/archive/2026-07-12-vng-klantinteracties-adapter/design.md`)
   document the exact VNG field vocabulary this codebase already ships and
   tests against: `onderwerp`, `kanaal`, `tekst` (klantcontact content — NOT
   `inhoud`), `plaatsgevondenOp`, `partijIdentificator{codeSoortObjectId,
   objectId}`, and the `field__operator` double-underscore filter convention
   (`partijIdentificator__codeSoortObjectId`, confirmed again in
   `Endpoint.vngFilterTranslation`'s own schema description in
   `lib/Settings/openconnector_register.json`). `lib/Rule/AvgBsnPolicyRule.php`
   further confirms the BSN-hashing discipline this bridge mirrors.
2. **The published VNG Klantinteracties OpenAPI specification** (OpenKlant
   2.x reference implementation, which KISS itself implements) for the parts
   not already covered by (1): pagination envelope shape, the `Token` auth
   scheme, and the `onderwerpobjectidentificator` sub-object shape.

Concretely assumed:

| Concern | Assumption | Where it lives |
| --- | --- | --- |
| List endpoint | `GET {baseUrl}/klantcontacten` | `KlantinteractiesClient::listKlantcontacten()` |
| Pagination envelope | `{count, next, previous, results: [...]}` (DRF-style) | same |
| Incremental filter | `registratiedatum__gte=<iso8601>` (VNG double-underscore convention) | same |
| Expansion | `expand=betrokkenen,onderwerpobjecten` | same |
| Sort | `sorteer=registratiedatum` (ascending, so `nextCursor` is monotonic) | same |
| Create endpoint | `POST {baseUrl}/klantcontacten`, response `{uuid, ...}` | `createKlantcontact()` |
| Betrokkene attach | `POST {baseUrl}/betrokkenen` with `{klantcontact: {uuid}, ...}` (VNG models betrokkenen as a separate FK'd resource, not embedded) | same |
| Case link | `POST {baseUrl}/onderwerpobjecten` with `{klantcontact: {uuid}, onderwerpobjectidentificator: {objectId, codeObjecttype, codeRegister, codeSoortObjectId}}` | `linkOnderwerpobject()` |
| `codeRegister` default | `"ZRC"` (Zaakregistratiecomponent, i.e. OpenZaak) — overridable via `configuration.onderwerpobject.codeRegister` | same |
| `codeSoortObjectId` | derived: `"UUID"` when the case reference is RFC-4122-shaped, else `"IDENTIFICATIE"` | `resolveSoortObjectId()` |
| Auth | `Authorization: Token <token>` (VNG/Common Ground convention, distinct from OAuth `Bearer`) — overridable via `configuration.authentication.scheme` | `buildAuthorizationHeader()` |

If the real KISS API diverges from any of these (e.g. a different pagination
param name, a different auth scheme, or an `expand=` depth limit), the fix is
isolated to `KlantinteractiesClient` — `KissSyncService`, `KissController`,
and the `kiss_klantcontact` schema are unaffected because of the provider
seam (see "Provider seam vs category IntegrationProvider" below).

## Cursor semantics

- The cursor (`source.configuration.cursor.lastRegistratiedatum`) is a
  KISS-side `registratiedatum` (ISO 8601), not a local timestamp — this
  avoids clock-skew between openconnector and KISS entirely.
- Each sweep calls `listKlantcontacten(since: <cursor>, pageSize)`, which the
  provider translates to `registratiedatum__gte=<cursor>` sorted ascending.
  This is an **inclusive** lower bound (`gte`, not `gt`) — the boundary
  record from the previous sweep is refetched and re-upserted, which is
  harmless because `upsertKlantcontact()` is idempotent on the KISS `uuid`
  (a redelivered record updates the existing local row in place, it never
  duplicates).
- After processing every item in the page, the cursor advances to the
  **maximum `registratiedatum` seen in the page** — regardless of whether
  any individual item failed to persist (per-record isolation, see below).
  This is a **deliberate availability-over-perfect-retry trade-off**: a page
  that is 99% successful must not be re-pulled in full on every subsequent
  sweep just because one record is persistently malformed (e.g. a KISS-side
  data quality issue that will never resolve itself). The failed record is
  logged (`kissId` + exception message) and simply does not get a local
  mirror; an operator can find it via the log and, if needed, backfill it
  manually. This mirrors how CDC/log-based sync systems generally treat a
  poison-pill record — never blocking the pipeline, at the cost of that one
  record needing manual attention.
- An **empty page** (nothing new/changed) does not write to the source at
  all — no spurious `saveObject` call, no churn on `dateModified`.
- Only ONE page is pulled per sweep (bounded by `configuration.pageSize`,
  default 100) — mirrors `BankfeedSyncService`'s one-window-per-sweep
  design. A backlog larger than `pageSize` catches up over several hourly
  sweeps rather than risking a single long-running request; this is
  acceptable because `KissPullJob` already disallows parallel runs
  (`setAllowParallelRuns(false)`), so sweeps cannot pile up.

## Mapping onderwerpobjecten to a case reference

A pulled klantcontact's `onderwerpobjecten` array (populated via `expand=`)
is scanned for the first entry whose
`onderwerpobjectidentificator.codeObjecttype` case-insensitively **contains**
the substring `zaak` (not an exact-enum match) — a deliberately tolerant
choice given the unverified live vocabulary (a real KISS deployment might use
`zaak`, `Zaak`, or a fuller `zaakobject` value; a substring match survives
all of these without needing correction). When found, `objectId` becomes
`caseReference` and the raw `codeObjecttype` becomes `caseObjectType`. When no
onderwerpobjecten are present, or none match the marker (e.g. a foreign
`codeObjecttype` like `partij` or `document`), both fields are persisted
`null` — the raw `onderwerpobjecten` array is still preserved verbatim on the
record either way, so no information is lost even when the case-mapping
heuristic misses.

## AVG / BSN handling

Consistent with this app's own `AvgBsnPolicyRule` (added by
`vng-klantinteracties-adapter`), any `betrokkenen[].partijIdentificator` whose
`codeSoortObjectId` is `bsn` (case-insensitive) has its `objectId` value
SHA-256-hashed before the `kiss_klantcontact` record is persisted — a raw
Dutch citizen service number is never written to storage by this bridge.
This is a lighter-weight, self-contained guard than the full 11-proef
validation `AvgBsnPolicyRule` performs (that rule operates on inbound
`maak-klantcontact` writes to this app's OWN Klantinteracties-shaped API
surface; this bridge pulls from an external KISS instance and applies the
same hash-before-store discipline at its own persistence boundary,
independently).

## Credential storage: why not `credentialRef`/`BrokeredCallService`

Same reasoning as `notifynl-sms-channel`'s deviation, restated for KISS's
simpler (static, not computed) auth shape: KISS's token is a static
bearer-style secret with no per-request signing, which IS exactly the "v1
scope" `BrokeredCallService`/`CredentialBrokerService::injectAuth()` targets
(see the Peppol `rest` provider's `credentialRef` precedent) — so
`credentialRef` COULD express this. `KlantinteractiesClient` uses direct
`ICrypto` storage instead (mirroring `RestNotifyNlProvider`) for a narrower
reason than NotifyNL's: **self-containment**. `BrokeredCallService` resolves
`OCA\OpenRegister\Service\Credential\CredentialBrokerService` via
`class_exists()` lazy resolution because that class only exists on
OpenRegister versions shipping the credential broker; a KISS bridge built
against `credentialRef` would inherit that same optional-dependency
soft-coupling. Direct `ICrypto` encryption (`configuration.authentication.
encryptedToken`, decrypted in-process only for the instant needed to build
the Authorization header, never logged, never persisted decrypted) keeps
`KlantinteractiesClient` unit-testable exactly like `RestNotifyNlProvider`
with zero optional-class dependency. This is a legitimate choice, not a
"better" one than `credentialRef` — a future revision MAY migrate to
`credentialRef` once a live KISS deployment confirms the static-token
assumption holds, without changing `KlantinteractiesProviderInterface`.

## Provider seam vs category IntegrationProvider

`KlantinteractiesProviderInterface` is deliberately narrow (3 methods:
list/create/link) rather than the fleet's broader category
`IntegrationProvider` abstraction — mirroring `SmsProviderInterface` /
`PeppolAccessPointProviderInterface` / `Psd2AggregatorProviderInterface`. A
KISS deployment is not a generic "integration" with arbitrary CRUD; it is
one specific VNG-dialect contract with exactly these three operations
relevant to this bridge. A future compatible alternative (a different
KCC vendor implementing the same VNG Klantinteracties API shape) is added by
implementing this interface, never by editing `KissSyncService` or
`KissController`.

## How procest should consume this (cross-app contract, not implemented here)

Procest's `ContactMomentService` (native KCC module) is the intended
production consumer of `POST /api/kiss/klantcontacten`:

- On a citizen contact moment created in procest that should be visible in
  KISS: `POST /api/kiss/klantcontacten` with `{onderwerp, kanaal, tekst,
  plaatsgevondenOp, indicatieContactGelukt, taal, betrokkene, caseReference:
  <the procest case UUID>, sourceApp: "procest"}`. The response's `id` is the
  KISS-assigned klantcontact id — procest SHOULD store it on its own
  ContactMoment record for future correlation/dedup, mirroring how it would
  store a NotifyNL `providerMessageId`.
- To surface a KISS-originated klantcontact (pulled by `KissPullJob`) on a
  case's timeline: procest queries OpenRegister's generic object API for
  `register=openconnector, schema=kiss_klantcontact,
  caseReference=<the case UUID>` — no dedicated read endpoint is needed
  because `kiss_klantcontact` is a normal OR schema, queryable like any
  other (mirrors how procest already reads `sms_message`/`peppol_transmission`
  records for other connectors).
- A `not_configured` (503) response from the push endpoint means no active
  KISS source exists yet — procest SHOULD treat this the same way it already
  treats an unconfigured NotifyNL/Peppol source (log, skip, do not surface a
  citizen-facing error), not as a hard failure.

## Alternatives considered

- **An inbound webhook for KISS-side changes** (mirroring
  `PeppolController::inbound()` / `NotifyNlController::inbound()`) was
  considered instead of, or alongside, the pull job. Rejected for v1: the
  webhook pattern requires KISS to be configured to call back into this
  instance (network reachability from KISS to openconnector, a signing
  secret exchange) which is a heavier operational precondition than a
  simple outbound poll, and — critically — no live KISS instance was
  available to verify whether KISS even offers a configurable webhook
  mechanism for klantcontact changes (unlike NotifyNL/Peppol, whose webhook
  contracts are part of their respective published specs). The cursor-based
  pull is a strictly-safer default that requires zero KISS-side
  configuration beyond read access; a webhook can be added later without
  changing `KlantinteractiesProviderInterface`.
- **Storing the case link only as a flat `caseReference` string on
  `kiss_klantcontact` (no `onderwerpobjecten` raw array)** was considered
  for a smaller schema, but rejected: the raw array is cheap to store and
  preserves every onderwerpobject KISS reports (not just the first
  case-shaped one), which future changes (e.g. surfacing a
  klantcontact-to-multiple-cases link) can consume without a KISS re-pull.
