---
kind: code
depends_on: []
---

# Proposal: iwmo-ijw-adapter

## Summary

Add an iWMO/iJW (Wmo 3.0 / Jeugdwet 3.0 StUF iStandaarden) bridge to
OpenConnector — the fleet's integration hub — translating OpenRegister
social-domain case objects (a `toewijzing`/assignment or a
`declaratie`/invoice) into the Wmo301-308/Jw301-308-style berichttype
envelopes municipalities and care providers legally exchange, and
translating inbound retour messages (acceptance/rejection, start/stop-zorg,
declaratie processing) back into an OR case status update. A narrow
`IwmoIjwProviderInterface` (send) is bound by `LogIwmoIjwProvider`
(sandbox/dev default) and `IStandaardenClient` (generic REST binding
against a GGk/VECOZO-fronted endpoint). `POST /api/iwmo-ijw/berichten` lets
sibling apps (e.g. procest's social-domain case module) register a
toewijzing/declaratie; `POST /api/iwmo-ijw/retour` is a signed webhook
ingesting retour messages. An `IwmoIjwRetryJob` re-drives failed outbound
sends. An `iwmo_ijw_message` OR schema records every outbound attempt and
inbound retour for observability.

## Motivation

iWMO and iJW are legally required message standards for social-domain
(Wmo/Jeugdwet) interoperability between Dutch municipalities and care
providers — a municipality cannot lawfully assign or invoice Wmo/Jeugdwet
care outside these formats. No fleet connector bridges OpenRegister
case/social-domain objects to this standard today. Per the user-mandated
architecture, ALL integrations live in OpenConnector — it translates the
default OpenRegister/ZGW object APIs into other standards' APIs, never
re-implemented per leaf app (per ADR-022). This is the natural next
social-domain connector after `kiss-kcc-bridge` (KCC/klantcontacten) and
`vng-klantinteracties-adapter`.

## Capabilities

- `iwmo-ijw-adapter` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `IwmoIjwProviderInterface` abstraction
  with `Log` + `IStandaardenClient` (REST) bindings, two translator classes
  (`OutboundBerichtTranslator`, `InboundRetourTranslator`),
  `IwmoIjwSyncService`, `IwmoIjwController`, `IwmoIjwRetryJob`, and an
  `iwmo_ijw_message` OR schema.
- [ ] Project: `procest` (and other social-domain consumer apps) — no code
  change here; a social-domain case module would target
  `POST /api/iwmo-ijw/berichten` (documented cross-app contract only, see
  design.md "How procest / social-domain apps consume this").

## Scope

### In Scope

- `OutboundBerichtTranslator`: OR case object (`kind: toewijzing|declaratie`,
  `domain: wmo|jw`) -> Wmo303/Jw303 (Toewijzing) or Wmo321/Jw321
  (Declaratie) XML envelope. Throws `IwmoIjwTranslationException` (never
  emits a literal/empty placeholder) on any missing required field —
  literal-leak guard, tested per berichttype.
- `InboundRetourTranslator`: retour XML envelope (berichttype 302, 304-308,
  322) -> an OR case status update instruction (`status`, plus
  berichttype-specific fields like `careStartedAt`/`paymentReference`).
  Rejects a retour with an empty/missing `kenmerk` before any OR write is
  attempted.
- `IwmoIjwProviderInterface` (`getProviderId`, `getConfigSchema`, `send`),
  `LogIwmoIjwProvider` (sandbox, no network/secret), `IStandaardenClient`
  (generic REST binding, token auth, `ICrypto`-encrypted secret).
- `IwmoIjwSyncService`: `sendBericht()` (translate + provider send + persist
  `iwmo_ijw_message` + no OR-case mutation on the outbound leg besides
  storing the correlation `ref`), `receiveRetour()` (verify webhook
  signature, translate, single-write-path update of the linked OR case,
  persist `iwmo_ijw_message`), `retryFailed()` (per-message-isolated retry
  of failed outbound sends).
- `IwmoIjwController`: `createBericht()` (NC-session push endpoint, mirrors
  `KissController::createKlantcontact()`), `inbound()` (HMAC-signed retour
  receiver, mirrors `PeppolController::inbound()`), never a 500 on a
  verified inbound callback.
- `IwmoIjwRetryJob`: hourly `TimedJob` retrying failed outbound sends —
  satisfies the fleet's orphaned-capability rule (route + job wired AND
  covered by tests proving invocation, not just declared).
- `iwmo_ijw_message` OR schema (`direction`, `berichttype`, `domain`,
  `status`, `ref`, `kenmerk`, `caseReference`, `error`, `syncedAt`), plus
  the register's `schemas` list entry (double-checked against
  `components.schemas`, per the kiss-kcc-bridge lesson that this list can
  silently drift).
- Feature gating: `configuration.provider` (`log`|`rest`), default `log`.
  An unconfigured bridge reports `not_configured` cleanly, no HTTP.
- AVG/BSN hygiene: the raw BSN is sent on the wire (legally required to
  identify the care recipient) but NEVER persisted raw in
  `iwmo_ijw_message` — SHA-256-hashed before the audit record is saved,
  consistent with `AvgBsnPolicyRule`/`kiss-kcc-bridge` precedent.

### Out of Scope

- A live-verified GGk/VECOZO integration — no live connection was available
  in this environment (stated explicitly). Every endpoint/field/berichttype
  is a documented assumption; see design.md "Message-shape assumptions".
- Client-certificate (mTLS) authentication — the real GGk/VECOZO transport
  historically uses client certs, not a bearer token; `IStandaardenClient`
  implements token auth only (mirrors every other REST binding already in
  this app) and documents the mTLS gap explicitly as an "Open Question",
  not silently.
- Berichttype 301 (Verzoek om toewijzing, a pre-assignment negotiation step
  some municipalities skip) — only 303/321 outbound and their retour
  family are in scope; see design.md's berichttype table for the full
  documented rationale.
- A settings UI for entering/rotating the iWMO/iJW API token — same
  convention as `notifynl-sms-channel`/`kiss-kcc-bridge`; set via the
  existing config-write surface.
- procest's own consuming module — a cross-app contract this change
  defines and documents, not implements in procest.

## Approach

Model the iWMO/iJW connection as an openconnector `Source` (`type=iwmo-ijw`)
whose `configuration` selects a provider (`log`|`rest`) and carries
`authentication.encryptedToken`, `baseUrl`, `gemeentecode`. A narrow
`IwmoIjwProviderInterface` (`send`) is implemented by `LogIwmoIjwProvider`
and `IStandaardenClient`. `IwmoIjwSyncService` resolves the active source +
provider and drives `sendBericht()` (outbound) and `receiveRetour()`
(inbound, webhook-verified). `IwmoIjwController` stays a thin HTTP/auth
shell (mirrors `KissController`/`PeppolController`). Details in design.md.

## New Dependencies

None. Reuses `guzzlehttp/guzzle` (already a dependency), `ActionAuthService`,
`WebhookSignatureService`, and `OCP\Security\ICrypto` (all already used by
existing leaf connectors in this app). No SOAP/XSD library added — envelopes
are hand-built via PHP's built-in `DOMDocument`.

## Impact

- New: `lib/Service/IwmoIjw/{IwmoIjwProviderInterface,LogIwmoIjwProvider,
  IStandaardenClient,OutboundBerichtTranslator,InboundRetourTranslator}.php`,
  `lib/Service/IwmoIjwSyncService.php`, `lib/Controller/IwmoIjwController.php`,
  `lib/Cron/IwmoIjwRetryJob.php`, `lib/Exception/{IwmoIjwProviderException,
  IwmoIjwTranslationException}.php`, `appinfo/routes.php` +
  `appinfo/info.xml` entries, an `iwmo_ijw_message` schema in
  `lib/Settings/openconnector_register.json`.
- Reused: `ActionAuthService`, `WebhookSignatureService`, `OCP\Security\ICrypto`.

## Cross-Project Dependencies

- procest (or another social-domain case app) is the intended production
  consumer of `POST /api/iwmo-ijw/berichten` (contract owned here; no
  procest code change in this PR).

## Risks

### Risk 1: The real GGk/VECOZO message shape may differ from the documented assumption

**Severity:** Medium — **Mitigation:** every assumed berichttype/field/
envelope shape is documented explicitly in design.md, grounded in the
publicly published iStandaarden berichttype catalogue naming convention and
this app's own `kiss-kcc-bridge`/`vng-klantinteracties-adapter` precedent;
the `log`/sandbox provider makes the whole send/retour path demonstrable
end-to-end without a real credential; the provider seam isolates any future
correction to `IStandaardenClient` + the two translators alone.

### Risk 2: mTLS (client-certificate) auth is not implemented

**Severity:** Medium — **Mitigation:** documented explicitly as an Open
Question in design.md, not silently assumed away; `IStandaardenClient`'s
token-auth shape mirrors every other REST binding in this app and is
provider-seam-isolated, so adding client-cert support later requires no
change to `IwmoIjwProviderInterface`, `IwmoIjwSyncService`, or the
translators.

### Risk 3: A malformed or unrecognised retour could silently corrupt an unrelated OR case

**Severity:** Low — **Mitigation:** `InboundRetourTranslator` rejects any
retour with an empty/missing `kenmerk` BEFORE any OR write; the sync
service resolves the target case strictly by that correlation key, and an
unresolved `kenmerk` is logged and the webhook still acknowledges receipt
(never a 500, never a guessed target) — mirrors `PeppolController::inbound()`.

## Rollback Strategy

The connector is additive. Revert by removing the new
controller/services/cron job/routes and the `iwmo_ijw_message` schema
entry; no existing source, sync, rule, or event behaviour changes, so
removal cannot regress current integrations.

## Open Questions

Client-certificate transport auth and the exact GGk/VECOZO base URL are
explicitly deferred (see "Out of Scope" / design.md "Open Questions") — not
blocking, since the sandbox provider makes the change self-contained and
demonstrable without either.
