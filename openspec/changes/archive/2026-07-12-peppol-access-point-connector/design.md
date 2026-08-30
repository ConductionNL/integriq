# Design: peppol-access-point-connector

## Architecture Overview

The connector adds three surfaces on top of existing openconnector machinery:

```
                         producing app (shillinq AR/PO)
                                   │
        emits nl.conduction.peppol.outbound.requested      GET /api/peppol/participants/{id}
                                   │                                   │
                                   ▼                                   ▼
   EventService ──► PeppolOutboundConsumer ──► PeppolTransmissionService ──► PeppolAccessPointProviderInterface
                                   │                                   │            ├─ LogPeppolAccessPointProvider (sandbox)
                                   │                       persists    │            └─ RestPeppolAccessPointProvider ─► BrokeredCallService ─► AP
                                   ▼                    peppol_transmission (OR)
                       emits nl.conduction.peppol.delivery.status  ◄── status transitions
                                   ▲
   POST /api/peppol/inbound ──► webhook_signature rule ──► PeppolController.inbound ──► (delivery callback → status)
                                                                                     └─ (inbound doc → nl.conduction.peppol.inbound.received)
```

Nothing new is invented for transport, events, or auth: the outbound path is an
event consumer over the existing `EventService`; the inbound path is a consumer
endpoint gated by the existing `webhook_signature` rule; credentials flow
through the existing `BrokeredCallService`/`CredentialBrokerService`. The only
new abstraction is the AP provider seam, mirroring shillinq's
`PeppolTransmissionAdapterInterface`.

## API Design

### `GET /api/peppol/participants/{peppolId}`
**Response:**
```json
{ "exists": true, "supportedDocTypes": ["ubl-invoice-2.1", "ubl-order-3.0"] }
```

### `POST /api/peppol/inbound`
Gated by a `webhook_signature` rule. Two callback shapes:
**Delivery callback:**
```json
{ "transmissionId": "AP-TX-123", "status": "delivered", "detail": "" }
```
**Inbound document notification:**
```json
{ "senderPeppolId": "0192:9999999999", "documentType": "ubl-invoice-2.1", "payloadReference": "https://ap.example/doc/AP-DOC-9" }
```
**Response:** `{ "received": true }` (HTTP 200); HTTP 401 on signature failure.

Internal (not a new public route — reuses source config): outbound is triggered
by consuming `nl.conduction.peppol.outbound.requested`, not by an HTTP call.

## Database Changes

One new OR schema `peppol_transmission` added declaratively to
`lib/Settings/openconnector_register.json` (register `openconnector`). No SQL
migration — persisted as an OpenRegister object like `call_log`/`event_message`.

| Field | Type | Purpose |
|-------|------|---------|
| `objectUri` | string | Source object being transmitted (from the event) |
| `sourceApp` | string | Producing app slug |
| `recipientPeppolId` | string | `scheme:identifier` |
| `documentType` | string | e.g. `ubl-invoice-2.1` |
| `payloadFileUri` | string | Ref to the UBL payload |
| `transmissionId` | string\|null | AP-assigned id (null until sent) |
| `status` | enum | `queued\|sent\|delivered\|rejected\|failed` |
| `detail` | string\|null | Last status detail |
| `attempts` | array | `{at, error\|null}` per submission attempt |

## Nextcloud Integration

- Controllers: `PeppolController` (`participants`, `inbound`).
- Services: `PeppolTransmissionService`, `PeppolOutboundConsumer` (event
  handler), `Peppol\PeppolAccessPointProviderInterface` +
  `LogPeppolAccessPointProvider` + `RestPeppolAccessPointProvider`.
- Mappers/Entities: none new — `peppol_transmission` via OR `ObjectService`.
- Events/Hooks: consumes `nl.conduction.peppol.outbound.requested`; emits
  `nl.conduction.peppol.delivery.status` and `nl.conduction.peppol.inbound.received`
  via `EventService`.

## Security Considerations

- Inbound `POST /api/peppol/inbound` MUST be gated by the `webhook_signature`
  rule (HMAC-SHA256 over the raw body, `hash_equals`, timestamp tolerance)
  before any state change — matches `webhook-signing` REQ-WHS-003.
- The participant lookup endpoint is a read-only proxy; it MUST validate the
  `peppolId` shape (`scheme:identifier`) to avoid SSRF-style passthrough and
  MUST NOT reflect the AP credential in any response.
- AP credentials resolved only via `credentialRef` through the broker (ADR-007);
  fail-closed on missing key material, no plaintext-on-disk fallback.
- Delivery-status/inbound events reuse `EventService`; the same IDOR/CSRF notes
  flagged in `events-cloudevents` REQ-005 apply to the subscription surface and
  are not widened by this change.

## Declarative-vs-imperative decision (ADR-031)

The `peppol_transmission` schema, its status enum, and its
`x-openregister-notifications` (e.g. notify on `failed`/dead-lettered) are
declared **declaratively** in `lib/Settings/openconnector_register.json`. The
transmission logic itself is **imperative** and justified under ADR-031's
"external integration" and "scheduled bulk / retry" exemptions: submitting UBL
to a certified external AP, brokering credentials, and driving a retry/
dead-letter lifecycle are external-integration side effects that cannot be
expressed as a declarative lifecycle block. Status *transitions* are recorded on
the object (declarative state), but the network hand-off and event emission are
service code. This mirrors `digikoppeling-adapter` (imperative transport,
declarative config) and shillinq's `PeppolTransmissionAdapterInterface`.

## File Structure

```
lib/
  Controller/
    PeppolController.php            # participants(), inbound()
  Service/
    Peppol/
      PeppolAccessPointProviderInterface.php
      LogPeppolAccessPointProvider.php
      RestPeppolAccessPointProvider.php
    PeppolTransmissionService.php   # queue → submit → status → events
    PeppolOutboundConsumer.php      # subscribes to outbound.requested
  Settings/
    openconnector_register.json     # + peppol_transmission schema
appinfo/
  routes.php                        # + /api/peppol/* routes
```

## Seed Data

A single sandbox Peppol source plus example transmissions so a fresh install
demonstrates lookup → transmit → status without a real AP.

### Schema: `peppol_transmission`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | tx-invoice-gemeente-nijmegen | tx-invoice-consultancy | tx-order-travel |
| objectUri | /objects/ar-invoice/00000000-0000-0000-0000-000000000000 | /objects/ar-invoice/00000000-0000-0000-0000-000000000000 | /objects/order/00000000-0000-0000-0000-000000000000 |
| sourceApp | shillinq | shillinq | shillinq |
| recipientPeppolId | 0106:00000000 | 0106:11111111 | 0106:22222222 |
| documentType | ubl-invoice-2.1 | ubl-invoice-2.1 | ubl-order-3.0 |
| transmissionId | MOCK-PEPPOL-1 | MOCK-PEPPOL-2 | null |
| status | delivered | sent | queued |
| detail | Accepted by receiver AP | Awaiting delivery receipt | Queued |

**Sandbox source** (`configuration.provider: log`,
`configuration.mockParticipants: ["0106:00000000","0106:11111111"]`) so
lookups and submits answer from canned data with no upstream call.

**Related items per object:**
- Files: the UBL payload referenced by `payloadFileUri` (a small BIS Billing 3.0 sample invoice XML).
- Notes: none.
- Tasks: none.
- Contacts: recipient organisation (municipality / consultancy / travel agency flavour).

## Trade-offs

- **Event-driven outbound vs synchronous submit endpoint.** Chose event-driven
  (`outbound.requested`) as the primary AR path for decoupling and retry, per the
  cross-app contract; a synchronous submit path can still be served by the same
  `PeppolTransmissionService` for shillinq's PO adapter binding without a second
  design. Alternative (only a synchronous endpoint) rejected: it couples the
  producer to AP latency and loses the dead-letter guarantee.
- **Provider seam vs category IntegrationProvider.** Peppol transport is a
  point-to-point transmission channel, not a query-time object proxy, so a narrow
  domain interface (`PeppolAccessPointProviderInterface`) fits better than
  `AbstractCategoryAdapterProvider` (which models query-time list/get). This
  matches how `digikoppeling-adapter` models transport, not catalogue proxying.

## Open Questions

None blocking — sandbox provider makes the path self-contained.
