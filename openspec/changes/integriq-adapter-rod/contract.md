# Contract: integriq-adapter-rod

## Consumers

- `learniq`: dispatches a `bron-rod` DataExchangeJob run by calling
  `POST /api/rod/berichten`; subscribes to `RodAcknowledgementReceivedEvent`
  to populate its own `ExchangeRejectionDetail` worklist when a signaalcode
  indicates a rejection or correction is needed.

## Endpoints

### `POST /api/rod/berichten`
**Auth**: Nextcloud session, `#[NoAdminRequired]` (a sibling app's own
background job runs as an authenticated system user, per the
`iwmo-ijw-adapter` push-endpoint precedent).

**Request:**
```json
{
  "berichtsoort": "inschrijving",
  "kenmerk": "<caller-generated correlation id>",
  "payload": {
    "bsn": "<raw BSN, hashed at rest, never logged>",
    "inschrijvingsdatum": "2026-09-01",
    "leerjaar": 4,
    "groep": "4B",
    "oppStartdatum": null,
    "oppEinddatum": null,
    "schooladvies": null
  }
}
```

**Response (200):**
```json
{ "ref": "MOCK-ROD-1", "berichtsoort": "inschrijving", "status": "sent" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | missing `berichtsoort`, `kenmerk`, or a required field for that `berichtsoort` |
| 503  | `not_configured` — no active `type=rod` source, or the `edukoppeling` binding is selected but no certificate reference is configured |

### `POST /api/rod/retour`
**Auth**: unauthenticated, HMAC-signed (`#[PublicPage]` +
`WebhookSignatureService`, mirrors `PeppolController::inbound()`).

**Request:** raw DUO acknowledgement envelope (Edukoppeling ebMS2 signal or
WUS synchronous response — see design.md).

**Response (200):**
```json
{ "received": true }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | missing or invalid HMAC signature — no processing attempted, no state change |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad request | required field missing before any envelope is built (literal-leak guard) |
| 401  | Unauthorized | retour signature verification failed |
| 503  | Not configured | no active `type=rod` source, or `edukoppeling` selected without a resolvable certificate reference |

## Versioning

v1, unversioned path (`/api/rod/*`), consistent with `iwmo-ijw-adapter` and
`digital-post-adapter`'s existing unversioned routes. A breaking change adds
`/api/v2/rod/*` rather than mutating v1 in place.

## Breaking Change Policy

Any change to the `RodAcknowledgementReceivedEvent` payload shape or the
`berichten` request schema is announced in the PR body and in
`openspec/specs/rod-adapter/spec.md`'s changelog before merge; learniq's
listener is the only known consumer and is notified via the cross-repo
follow-up section of this change's tasks.md.

## SLA

Best-effort, matching the existing `iwmo-ijw-adapter`/`digital-post-adapter`
seams: no queue-depth or latency guarantee is made until a live DUO
connection exists (M3(c), open). The `log` binding responds synchronously
with no network call.
