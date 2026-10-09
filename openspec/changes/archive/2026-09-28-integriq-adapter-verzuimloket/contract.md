# Contract: integriq-adapter-verzuimloket

## Consumers

- `learniq`: dispatches a `leerplicht` DataExchangeJob run by calling
  `POST /api/verzuimloket/berichten`; subscribes to
  `VerzuimloketAcknowledgementReceivedEvent` to learn the outcome of a
  melding.

## Endpoints

### `POST /api/verzuimloket/berichten`
**Auth**: Nextcloud session, `#[NoAdminRequired]`.

**Request:**
```json
{
  "meldingType": "eerste-melding",
  "kenmerk": "<caller-generated correlation id>",
  "payload": {
    "bsn": "<raw BSN, hashed at rest, never logged>",
    "windowStart": "2026-09-01",
    "windowEnd": "2026-09-28",
    "metricValue": 16,
    "breachingRecords": [ { "date": "2026-09-15", "lesuren": 4 } ],
    "interventions": [ { "recordedBy": "u-mentor-1", "recordedAt": "2026-09-16T09:00:00+02:00", "note": "Contact opgenomen met ouders" } ]
  }
}
```

**Response (200):**
```json
{ "ref": "MOCK-VERZUIM-1", "meldingType": "eerste-melding", "status": "sent" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | missing `meldingType`, `kenmerk`, or a required field for that `meldingType` |
| 503  | `not_configured` — no active `type=verzuimloket` source, or `edukoppeling` selected without a certificate reference |

### `POST /api/verzuimloket/retour`
**Auth**: unauthenticated, HMAC-signed (`#[PublicPage]` + `WebhookSignatureService`).

**Request:** raw DUO acknowledgement envelope.

**Response (200):**
```json
{ "received": true }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | missing or invalid HMAC signature |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad request | required field missing before any envelope is built |
| 401  | Unauthorized | retour signature verification failed |
| 503  | Not configured | no active `type=verzuimloket` source, or `edukoppeling` selected without a resolvable certificate reference |

## Versioning

v1, unversioned path (`/api/verzuimloket/*`), same convention as
`integriq-adapter-rod`.

## Breaking Change Policy

Any change to `VerzuimloketAcknowledgementReceivedEvent`'s payload shape or
the `berichten` request schema is announced in the PR body before merge;
learniq's own listener (not part of this change) is the known consumer.

## SLA

Best-effort, matching `integriq-adapter-rod`. The `log` binding responds
synchronously with no network call.
