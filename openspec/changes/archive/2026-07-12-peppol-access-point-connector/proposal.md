---
kind: code
depends_on: []
---

# Proposal: peppol-access-point-connector

## Summary

Add Peppol network connectivity to OpenConnector as a first-class connector so
that any Conduction app can look up Peppol participants, transmit UBL documents
(e-invoices, orders) over a Peppol Access Point (AP), and receive inbound
documents — without embedding a Peppol/AS4 client of its own. The connector
follows the openconnector idiom (source config schema + credential broker +
CloudEvents + inbound webhook rule) and mirrors the `digikoppeling-adapter`
NL-infrastructure pattern. It exposes a participant/SMP lookup endpoint, an
event-driven outbound transmission path (consuming
`nl.conduction.peppol.outbound.requested`, emitting
`nl.conduction.peppol.delivery.status`), and an inbound receive webhook that
republishes AP delivery callbacks as CloudEvents. A provider abstraction lets
the same connector run against a `log`/sandbox AP in dev/CI and a generic REST
access point in production.

## Motivation

The EU e-invoicing mandate (ViDA) and the Dutch `Peppol BIS Billing 3.0`
requirement make outbound e-invoicing over Peppol a hard dependency for the
accounting stack. shillinq already defines the *consume* side —
`lib/Service/PurchaseOrder/PeppolTransmissionAdapterInterface.php` +
`LogPeppolTransmissionAdapter` — whose production binding is documented as
"HTTP-backed against `/openconnector/api/peppol/...`". Per ADR-022 the real
Peppol AP integration must live in the app that owns integrations
(openconnector), not be re-implemented in every leaf app. Today that production
binding does not exist: there is no participant lookup, no AP transmission, and
no inbound receive path in openconnector. This change provides them.

## Capabilities

- `peppol-access-point-connector` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `PeppolController` (participant lookup +
  inbound receive webhook), a `PeppolAccessPointProviderInterface` abstraction
  with `Log` + generic `Rest` providers, an event consumer for outbound
  transmission, a `peppol_transmission` OR schema, and delivery-status
  CloudEvent emission.
- [ ] Project: `shillinq` — no code change here; shillinq's existing
  `PeppolTransmissionAdapterInterface` production binding will target the
  endpoints/events this change introduces (documented cross-app contract only).

## Scope

### In Scope

- Participant/SMP lookup: `GET /api/peppol/participants/{peppolId}` →
  `{exists, supportedDocTypes}`.
- Provider abstraction `PeppolAccessPointProviderInterface` with two bindings:
  a `log` (sandbox) provider and a generic `rest` provider (configurable base
  URL + brokered `credentialRef`, placeholder `YOUR_API_KEY_HERE`).
- Outbound transmission: consume `nl.conduction.peppol.outbound.requested`,
  resolve the payload UBL, submit via the configured provider, persist a
  `peppol_transmission` record, and drive its status lifecycle.
- Delivery-status events: emit `nl.conduction.peppol.delivery.status` on every
  state change (`queued|sent|delivered|rejected|failed`).
- Inbound receive path (stub): a webhook endpoint that accepts AP delivery
  callbacks, verifies them via the existing `webhook_signature` rule, and
  republishes them as CloudEvents.
- Credential brokering (ADR-007): AP API keys resolved via `credentialRef`,
  never stored/logged in plaintext.

### Out of Scope

- In-process AS4/AS2 message assembly, C2/C3 SBDH envelope construction, and
  PKI-mutual-TLS to the Peppol backbone — these are delegated to the configured
  access-point provider (a certified AP), not implemented here.
- UBL document *generation/validation* (Peppol BIS Billing 3.0 authoring) — the
  producing app supplies a ready UBL payload URI; this connector transports it.
- A Peppol SMP *registration/onboarding* UI — participants are assumed already
  registered with the AP.

## Approach

Model the AP connection as an openconnector Source whose `configuration`
selects a provider (`log` | `rest`) and carries the AP base URL and
`authentication.credentialRef`. A narrow `PeppolAccessPointProviderInterface`
(`lookupParticipant`, `submitDocument`) is implemented by
`LogPeppolAccessPointProvider` (sandbox) and `RestPeppolAccessPointProvider`
(generic REST AP). Outbound transmission is event-driven: an event consumer
subscribes to `nl.conduction.peppol.outbound.requested`, creates a
`peppol_transmission` OR object (`status=queued`), submits via the provider,
and emits `nl.conduction.peppol.delivery.status` on each transition; failures
follow the existing retry/dead-letter machinery. Inbound AP callbacks hit
`POST /api/peppol/inbound`, are signature-verified by the `webhook_signature`
rule, and are republished as CloudEvents. Details in design.md.

## New Dependencies

None. Reuses `CredentialBrokerService` (via `BrokeredCallService`), the
`EventService` CloudEvent fan-out, the `webhook_signature` rule, and the
existing job/retry/dead-letter surfaces.

## Impact

- New: `lib/Controller/PeppolController.php`,
  `lib/Service/Peppol/PeppolAccessPointProviderInterface.php`,
  `LogPeppolAccessPointProvider`, `RestPeppolAccessPointProvider`,
  `PeppolTransmissionService`, `appinfo/routes.php` entries, a
  `peppol_transmission` schema in `lib/Settings/openconnector_register.json`.
- Reused: `EventService`, `WebhookSignatureService`, `BrokeredCallService`,
  dead-letter-replay surface.

## Cross-Project Dependencies

- shillinq consumes this via its `PeppolTransmissionAdapterInterface` production
  binding (participant lookup + submit) and by producing
  `nl.conduction.peppol.outbound.requested` / observing
  `nl.conduction.peppol.delivery.status`. Contract owned here.

## Risks

### Risk 1: A certified AP is required for real transmission

**Severity:** Medium — **Mitigation:** ship the `log`/sandbox provider so the
whole path (lookup → transmit → status events → inbound republish) is
demonstrable end-to-end in dev/CI with no real AP or credential; production
swaps in the `rest` provider + a real `credentialRef`.

### Risk 2: Inbound callback authenticity

**Severity:** Medium — **Mitigation:** the inbound webhook MUST be gated by the
existing `webhook_signature` rule (HMAC over the raw body, constant-time
compare) before any republish; unsigned/mismatched callbacks are rejected 401.

### Risk 3: Duplicate/replayed delivery callbacks

**Severity:** Low — **Mitigation:** de-duplicate on the AP `transmissionId`;
a repeat callback for a terminal transmission is a no-op.

## Rollback Strategy

The connector is additive. Revert by removing the new controller/services/routes
and the `peppol_transmission` schema entry; no existing source, sync, rule, or
event behaviour changes, so removal cannot regress current integrations.

## Open Questions

None blocking — the sandbox provider makes the change self-contained; provider
selection and AP vendor are per-source configuration.
